<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * late_mark_helper.php — Late Mark Policy Helper Engine
 * ─────────────────────────────────────────────────────────────────────────────
 * Implements the Late Mark Policy:
 * 1. Working Hours: 9:30 AM to 6:30 PM (or user custom expectedPunchIn).
 * 2. Grace Time: 15 minutes.
 * 3. Arrival Up to 9:45 AM → On Time / Not Late (lateMark = 'no').
 * 4. Arrival 9:46 AM to 10:30 AM → Present but 1 Late Mark (lateMark = 'yes').
 * 5. Arrival After 10:30 AM → Half Day (isHalfDay = true, lateMark = 'no').
 * 6. Monthly Adjustment / Leave Deduction:
 *    - 1 to 3 Late Marks in a month = No penalty (warning level).
 *    - 4th Late Mark onwards = Half Day Leave deduction for every 2 late marks.
 *      (e.g., 4-5 marks = 0.5 day, 6-7 marks = 1.0 day, 8-9 marks = 1.5 days).
 * 7. Policy applies ONLY from POLICY_EFFECTIVE_DATE onwards.
 * 8. Policy applies ONLY to eligible roles: senior_intern, hr, resourceAllocator.
 * ─────────────────────────────────────────────────────────────────────────────
 */

/**
 * Effective date from which the Late Mark Policy is enforced.
 * Attendance records before this date are exempt from all policy calculations.
 */
if (!defined('POLICY_EFFECTIVE_DATE')) {
    define('POLICY_EFFECTIVE_DATE', '2026-08-01');
}

if (!function_exists('isLateMarkEligible')) {
    /**
     * Determines whether a user's role is subject to the Late Mark Policy.
     *
     * Eligible roles:
     *   - userRole = 'senior_intern'
     *   - userRole = 'resourceAllocator'
     *   - designation = 'hr'
     *
     * @param string $userRole    The user's role (e.g., 'senior_intern', 'junior_intern')
     * @param string $designation The user's designation (e.g., 'hr', 'receptionist')
     * @return bool True if the user should receive late marks, false otherwise
     */
    function isLateMarkEligible(string $userRole, string $designation = ''): bool {
        $eligibleRoles        = ['senior_intern', 'resourceAllocator'];
        $eligibleDesignations = ['hr'];
        return in_array($userRole, $eligibleRoles, true)
            || in_array(strtolower(trim($designation)), $eligibleDesignations, true);
    }
}

if (!function_exists('evaluateLateMarkPolicy')) {
    /**
     * Evaluates punch-in time against expected punch-in time.
     *
     * Returns a no-penalty result automatically when:
     *   - $date is provided and is before POLICY_EFFECTIVE_DATE (2026-08-01)
     *   - $punchInTime is empty / placeholder
     *
     * @param string $punchInTime    Punch-in time string (e.g. '09:37:00' or '09:37 AM')
     * @param string $expectedPunchIn Expected punch-in time string (default '09:30:00')
     * @param string $date           The attendance date in Y-m-d format (optional, used for policy date guard)
     * @return array {
     *   'lateMark': 'yes'|'no',
     *   'isLate': bool,
     *   'isHalfDay': bool,
     *   'lateMinutes': int
     * }
     */
    function evaluateLateMarkPolicy(string $punchInTime, string $expectedPunchIn = '09:30:00', string $date = ''): array {
        $noMark = [
            'lateMark'    => 'no',
            'isLate'      => false,
            'isHalfDay'   => false,
            'lateMinutes' => 0
        ];

        // ── Policy Date Guard ─────────────────────────────────────────────────
        // Policy is only effective from POLICY_EFFECTIVE_DATE (2026-08-01).
        // Historical records before this date are always exempt.
        if (!empty($date) && $date < POLICY_EFFECTIVE_DATE) {
            return $noMark;
        }

        // ── Empty / placeholder punch-in ──────────────────────────────────────
        if (empty($punchInTime) || $punchInTime === '00:00:00' || $punchInTime === '-') {
            return $noMark;
        }

        $expSec     = strtotime("1970-01-01 " . $expectedPunchIn);
        $piSec      = strtotime("1970-01-01 " . $punchInTime);
        $graceSec   = $expSec + (15 * 60);  // 09:45:00 for 09:30:00 expected
        $halfDaySec = $expSec + (60 * 60);  // 10:30:00 for 09:30:00 expected

        if ($piSec <= $graceSec) {
            // Up to 09:45 AM → On Time / Not Late
            return $noMark;
        } elseif ($piSec <= $halfDaySec) {
            // 09:46 AM to 10:30 AM → Present but 1 Late Mark
            $lateMinutes = (int)ceil(($piSec - $expSec) / 60);
            return [
                'lateMark'    => 'yes',
                'isLate'      => true,
                'isHalfDay'   => false,
                'lateMinutes' => $lateMinutes
            ];
        } else {
            // After 10:30 AM → Half Day
            $lateMinutes = (int)ceil(($piSec - $expSec) / 60);
            return [
                'lateMark'    => 'no',
                'isLate'      => false,
                'isHalfDay'   => true,
                'lateMinutes' => $lateMinutes
            ];
        }
    }
}

if (!function_exists('calculateMonthlyLateMarkDeductions')) {
    /**
     * Calculates the half-day leave deductions for a given monthly late mark count.
     * 
     * Policy:
     * - 1 to 3 Late Marks = No penalty (0.0)
     * - 4th Late Mark onwards = Half Day Leave deduction for every 2 late marks
     * 
     * @param int $lateCount Total number of late marks in the month
     * @return float Half day leave deduction (e.g. 0.0, 0.5, 1.0, 1.5, etc.)
     */
    function calculateMonthlyLateMarkDeductions(int $lateCount): float {
        if ($lateCount < 4) {
            return 0.0;
        }
        return floor(($lateCount - 2) / 2) * 0.5;
    }
}

if (!function_exists('getMonthlyLateMarkCount')) {
    /**
     * Get total count of Late Marks for user in specified month (YYYY-MM).
     * 
     * @param mysqli $con
     * @param string $userId
     * @param string $yearMonth Format YYYY-MM
     * @return int
     */
    function getMonthlyLateMarkCount(mysqli $con, string $userId, string $yearMonth): int {
        $u  = mysqli_real_escape_string($con, $userId);
        $ym = mysqli_real_escape_string($con, $yearMonth);

        $res = mysqli_query($con, "
            SELECT COUNT(*) AS cnt 
            FROM attendance 
            WHERE userId = '$u' 
              AND DATE_FORMAT(date, '%Y-%m') = '$ym' 
              AND LOWER(lateMark) = 'yes'
        ");

        if ($res && $row = mysqli_fetch_assoc($res)) {
            return (int)$row['cnt'];
        }
        return 0;
    }
}
