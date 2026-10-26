<?php
/**
 * Centralized Availability Calculation Engine v2
 *
 * Fixes applied:
 *  1. All checks are date-range aware (not just today)
 *  2. Pending resourceRequests are treated as soft-blocked intervals
 *  3. Leave / Tour / Holiday status surfaced per-date
 *  4. Free-slot logic lives here only (frontend calls the AJAX endpoint)
 *  5. No duplicate logic between front-end and back-end
 */

// ─────────────────────────────────────────────
//  Primitives
// ─────────────────────────────────────────────

function timeToMinutes(string $time): int {
    if ($time === '' || $time === null) return 0;
    [$h, $m] = explode(':', $time);
    return (int)$h * 60 + (int)$m;
}

function minutesToTime(int $minutes): string {
    $h = (int)floor($minutes / 60);
    $m = $minutes % 60;
    return str_pad((string)$h, 2, '0', STR_PAD_LEFT)
         . ':' . str_pad((string)$m, 2, '0', STR_PAD_LEFT);
}

function formatHours(int $minutes): string {
    if ($minutes <= 0) return '0h';
    $h = (int)floor($minutes / 60);
    $m = $minutes % 60;
    return $m === 0 ? "{$h}h" : "{$h}h {$m}m";
}

// ─────────────────────────────────────────────
//  Interval helpers
// ─────────────────────────────────────────────

/**
 * Merge overlapping [start, end] intervals (both numeric minutes).
 * @param  array[] $intervals  Each element: ['start'=>int, 'end'=>int]
 * @return array[]
 */
function mergeTimeIntervals(array $intervals): array {
    if (empty($intervals)) return [];
    usort($intervals, fn($a, $b) => $a['start'] - $b['start']);

    $merged = [$intervals[0]];
    foreach (array_slice($intervals, 1) as $iv) {
        $last = &$merged[count($merged) - 1];
        if ($iv['start'] <= $last['end']) {
            $last['end'] = max($last['end'], $iv['end']);
        } else {
            $merged[] = $iv;
        }
    }
    return $merged;
}

function calculateTotalMinutes(array $mergedIntervals): int {
    return array_sum(array_map(fn($iv) => $iv['end'] - $iv['start'], $mergedIntervals));
}

// ─────────────────────────────────────────────
//  Work-window
// ─────────────────────────────────────────────

/**
 * Returns the intern's expected punch-in/out window, or default work window (09:00 - 17:00).
 */
function getInternWorkWindow($con, string $internId): array {
    $id = esc($con, $internId);
    $r = mysqli_query($con,
        "SELECT COALESCE(TIME_FORMAT(expectedPunchIn,'%H:%i'),'09:00')  AS ws,
                COALESCE(TIME_FORMAT(expectedPunchOut,'%H:%i'),'17:00') AS we
         FROM   signup
         WHERE  (internId = '$id' OR userId = '$id')
           AND  (TRIM(LOWER(status)) = 'active' OR status = '1' OR status = 1 OR status IS NULL OR status = '')
         LIMIT  1");
    if (!$r || mysqli_num_rows($r) === 0) {
        $r = mysqli_query($con,
            "SELECT COALESCE(TIME_FORMAT(expectedPunchIn,'%H:%i'),'09:00')  AS ws,
                    COALESCE(TIME_FORMAT(expectedPunchOut,'%H:%i'),'17:00') AS we
             FROM   signup
             WHERE  (internId = '$id' OR userId = '$id')
             LIMIT  1");
    }
    if (!$r || mysqli_num_rows($r) === 0) {
        return [
            'workStartTime'    => '09:00',
            'workEndTime'      => '17:00',
            'workStartMinutes' => 540,
            'workEndMinutes'   => 1020,
            'totalMinutes'     => 480,
        ];
    }
    $row = mysqli_fetch_assoc($r);
    $ws = !empty($row['ws']) ? $row['ws'] : '09:00';
    $we = !empty($row['we']) ? $row['we'] : '17:00';
    $s = timeToMinutes($ws);
    $e = timeToMinutes($we);
    if ($e <= $s) { $s = 540; $e = 1020; $ws = '09:00'; $we = '17:00'; }
    return [
        'workStartTime'    => $ws,
        'workEndTime'      => $we,
        'workStartMinutes' => $s,
        'workEndMinutes'   => $e,
        'totalMinutes'     => max(0, $e - $s),
    ];
}

// ─────────────────────────────────────────────
//  Blocking conditions (per-date)
// ─────────────────────────────────────────────

function isHoliday($con, string $date): bool {
    $d = esc($con, $date);
    $r = mysqli_query($con,
        "SELECT id FROM holidays WHERE from_date <= '$d' AND to_date >= '$d' LIMIT 1");
    return $r && mysqli_num_rows($r) > 0;
}

function isInternOnLeave($con, string $internId, string $date): bool {
    $uid = resolveUserId($con, $internId);
    if ($uid === null) return false;
    $uid = esc($con, $uid);
    $d   = esc($con, $date);
    $r   = mysqli_query($con,
        "SELECT id FROM leaves
         WHERE  userId='$uid' AND status='Approved'
           AND  from_date <= '$d' AND to_date >= '$d'
         LIMIT  1");
    return $r && mysqli_num_rows($r) > 0;
}

function isInternOnTour($con, string $internId, string $date): bool {
    $uid = resolveUserId($con, $internId);
    if ($uid === null) return false;
    $uid = esc($con, $uid);
    $d   = esc($con, $date);
    $r   = mysqli_query($con,
        "SELECT id FROM tour
         WHERE  userId='$uid' AND status='Approved'
           AND  fromDate <= '$d' AND toDate >= '$d'
         LIMIT  1");
    return $r && mysqli_num_rows($r) > 0;
}

// ─────────────────────────────────────────────
//  Allocations (approved) + Pending requests
// ─────────────────────────────────────────────

/**
 * Returns approved allocations that cover $date.
 * Each element: [id, startTime, endTime, startMinutes, endMinutes, project, client]
 */
function getApprovedAllocationsForDate($con, string $internId, string $date): array {
    $id = esc($con, $internId);
    $uid = resolveUserId($con, $internId) ?? $id;
    $uidEsc = esc($con, $uid);
    $d  = esc($con, $date);
    $r  = mysqli_query($con,
        "SELECT id,
                COALESCE(allocStartTime,'09:00') AS allocStartTime,
                COALESCE(allocEndTime,'17:00')   AS allocEndTime,
                projectName, clientName
         FROM   internAllocations
         WHERE  (internId = '$id' OR internId = '$uidEsc')
           AND  startDate <= '$d' AND endDate >= '$d'
         ORDER  BY allocStartTime ASC");
    return $r ? fetchAllocRows($r) : [];
}

/**
 * Returns PENDING resource-requests that cover $date (treated as soft-blocked).
 * This prevents double-booking before a request is formally approved.
 */
function getPendingRequestsForDate($con, string $internId, string $date): array {
    $id = esc($con, $internId);
    $uid = resolveUserId($con, $internId) ?? $id;
    $uidEsc = esc($con, $uid);
    $d  = esc($con, $date);
    $r  = mysqli_query($con,
        "SELECT id,
                COALESCE(allocStartTime,'09:00') AS allocStartTime,
                COALESCE(allocEndTime,'17:00')   AS allocEndTime,
                projectName, clientName
         FROM   resourceRequests
         WHERE  (internId = '$id' OR internId = '$uidEsc')
           AND  status   = 'Pending'
           AND  startDate <= '$d' AND endDate >= '$d'
         ORDER  BY allocStartTime ASC");
    return $r ? fetchAllocRows($r, true) : [];
}

// ─────────────────────────────────────────────
//  Core: single-date availability
// ─────────────────────────────────────────────

/**
 * Full availability for one intern on one date.
 * Returns a rich result array used by both the AJAX endpoint and the validator.
 *
 * Result keys:
 *   available      bool
 *   reason         string  ('ok'|'holiday'|'leave'|'tour'|'no_work_window')
 *   message        string
 *   usedMinutes    int
 *   freeMinutes    int
 *   totalMinutes   int
 *   workStartTime  string
 *   workEndTime    string
 *   allocations    array   (approved only)
 *   pendingSlots   array   (pending requests)
 *   mergedIntervals array  (approved + pending merged)
 *   freeSlots      array   [{start,end,startTime,endTime,duration}]
 */
function calculateAvailabilityForDate($con, string $internId, string $date): array {
    $unavail = fn(string $reason, string $msg) => [
        'available'      => false,
        'reason'         => $reason,
        'message'        => $msg,
        'usedMinutes'    => 0,
        'freeMinutes'    => 0,
        'totalMinutes'   => 0,
        'workStartTime'  => null,
        'workEndTime'    => null,
        'allocations'    => [],
        'pendingSlots'   => [],
        'mergedIntervals'=> [],
        'freeSlots'      => [],
    ];

    if (date('w', strtotime($date)) == 0)
        return $unavail('sunday', 'This date is a Sunday (non-working day)');
    if (isHoliday($con, $date))
        return $unavail('holiday', 'This date is a company holiday');
    if (isInternOnLeave($con, $internId, $date))
        return $unavail('leave', 'Intern is on approved leave');
    if (isInternOnTour($con, $internId, $date))
        return $unavail('tour', 'Intern is on approved tour');

    $ww = getInternWorkWindow($con, $internId);
    if (!$ww)
        return $unavail('no_work_window', 'Intern work window not found');

    $approved = getApprovedAllocationsForDate($con, $internId, $date);
    $pending  = getPendingRequestsForDate($con, $internId, $date);

    // Build merged intervals from both approved and pending
    $intervals = array_map(
        fn($a) => ['start' => $a['startMinutes'], 'end' => $a['endMinutes']],
        array_merge($approved, $pending)
    );
    $merged    = mergeTimeIntervals($intervals);
    $usedMins  = calculateTotalMinutes($merged);
    $freeMins  = max(0, $ww['totalMinutes'] - $usedMins);
    $freeSlots = computeFreeSlots($ww['workStartMinutes'], $ww['workEndMinutes'], $merged);

    return [
        'available'       => true,
        'reason'          => 'ok',
        'message'         => '',
        'usedMinutes'     => $usedMins,
        'freeMinutes'     => $freeMins,
        'totalMinutes'    => $ww['totalMinutes'],
        'workStartTime'   => $ww['workStartTime'],
        'workEndTime'     => $ww['workEndTime'],
        'workStartMinutes'=> $ww['workStartMinutes'],
        'workEndMinutes'  => $ww['workEndMinutes'],
        'allocations'     => $approved,
        'pendingSlots'    => $pending,
        'mergedIntervals' => $merged,
        'freeSlots'       => $freeSlots,
    ];
}

/**
 * Compute free time slots given the work window and already-merged busy intervals.
 * Returns array of [{start, end, startTime, endTime, duration}]
 */
function computeFreeSlots(int $wStart, int $wEnd, array $mergedBusy): array {
    $free   = [];
    $cursor = $wStart;
    foreach ($mergedBusy as $iv) {
        if ($cursor < $iv['start']) {
            $free[] = makeSlot($cursor, $iv['start']);
        }
        $cursor = max($cursor, $iv['end']);
    }
    if ($cursor < $wEnd) {
        $free[] = makeSlot($cursor, $wEnd);
    }
    return $free;
}

// ─────────────────────────────────────────────
//  Date-range availability (for the form display)
// ─────────────────────────────────────────────

/**
 * Returns daily availability keyed by 'Y-m-d' for every date in the range.
 * Summarises the worst-case (minimum free minutes) for quick display.
 */
function calculateAvailabilityForDateRange($con, string $internId, string $startDate, string $endDate): array {
    $result = [];
    $cur    = $startDate;
    $endTs  = strtotime($endDate);
    while (strtotime($cur) <= $endTs) {
        $result[$cur] = calculateAvailabilityForDate($con, $internId, $cur);
        $cur = date('Y-m-d', strtotime($cur . ' +1 day'));
    }
    return $result;
}

/**
 * Summarise a date-range result:
 * - minFreeMinutes  : worst day's free time
 * - blockedDates    : days that are fully blocked (holiday/leave/tour)
 * - overlapDates    : days where the requested slot would conflict
 * Returns a structure used by validateAllocationRequest().
 */
function summariseDateRange(array $dailyAvailability): array {
    $blocked = [];
    $minFree = PHP_INT_MAX;
    foreach ($dailyAvailability as $date => $day) {
        if (!$day['available']) {
            $blocked[$date] = $day['reason'];
        } else {
            $minFree = min($minFree, $day['freeMinutes']);
        }
    }
    return [
        'blockedDates'   => $blocked,
        'minFreeMinutes' => $minFree === PHP_INT_MAX ? 0 : $minFree,
    ];
}

// ─────────────────────────────────────────────
//  Overlap check
// ─────────────────────────────────────────────

/**
 * Check whether a proposed [startTime, endTime] overlaps any existing
 * approved allocation or pending request on $date,
 * optionally excluding $excludeAllocationId.
 */
function checkTimeSlotOverlap(
    $con,
    string  $internId,
    string  $date,
    string  $startTime,
    string  $endTime,
    ?int    $excludeAllocationId = null,
    bool    $includePending = true
): array {
    $ns = timeToMinutes($startTime);
    $ne = timeToMinutes($endTime);

    $approved = getApprovedAllocationsForDate($con, $internId, $date);
    $pending  = $includePending ? getPendingRequestsForDate($con, $internId, $date) : [];
    $all      = array_merge($approved, $pending);

    foreach ($all as $a) {
        if ($excludeAllocationId !== null && (int)$a['id'] === $excludeAllocationId) continue;
        // Standard overlap: (sA < eB) AND (eA > sB)
        if ($ns < $a['endMinutes'] && $ne > $a['startMinutes']) {
            return ['overlap' => true, 'conflictWith' => $a];
        }
    }
    return ['overlap' => false];
}

// ─────────────────────────────────────────────
//  Allocation validator (used by process scripts)
// ─────────────────────────────────────────────

/**
 * Main validator called before saving a request or approving an allocation.
 * Performs all rules: date order, min duration, work window, leaves, tours, holidays, overlap.
 * Returns ['valid' => bool, 'errors' => [...], 'warnings' => [...]]
 */
function validateAllocationRequest(
    $con,
    string $internId,
    string $startDate,
    string $endDate,
    string $allocStartTime,
    string $allocEndTime,
    ?int   $excludeAllocationId = null,
    bool   $includePending = false   // false = only block on Approved; true = also block on Pending
): array {
    $errors   = [];
    $warnings = [];

    if (strtotime($endDate) < strtotime($startDate)) {
        $errors[] = 'End date cannot be before start date.';
        return ['valid' => false, 'errors' => $errors, 'warnings' => $warnings];
    }

    $sm = timeToMinutes($allocStartTime);
    $em = timeToMinutes($allocEndTime);
    if ($sm === false || $em === false || $em <= $sm) {
        $errors[] = 'Invalid time slot: End time must be strictly after start time.';
        return ['valid' => false, 'errors' => $errors, 'warnings' => $warnings];
    }

    $ww = getInternWorkWindow($con, $internId);
    if (!$ww) {
        $errors[] = 'Intern work window not found.';
        return ['valid' => false, 'errors' => $errors, 'warnings' => $warnings];
    }

    // Soft warning: outside normal hours
    if ($sm < $ww['workStartMinutes'] || $em > $ww['workEndMinutes']) {
        $warnings[] = "Time slot is outside the intern's normal working hours "
                    . "({$ww['workStartTime']} – {$ww['workEndTime']}).";
    }

    // Per-date validation
    $cur   = $startDate;
    $endTs = strtotime($endDate);

    while (strtotime($cur) <= $endTs) {
        if (date('w', strtotime($cur)) == 0) {
            $errors[] = "$cur: Sunday (non-working day).";
        } elseif (isHoliday($con, $cur)) {
            $errors[] = "$cur: Company holiday.";
        } elseif (isInternOnLeave($con, $internId, $cur)) {
            $errors[] = "$cur: Intern is on approved leave.";
        } elseif (isInternOnTour($con, $internId, $cur)) {
            $errors[] = "$cur: Intern is on approved tour.";
        } else {
            // Check overlap: always block on Approved allocations;
            // only block on Pending when $includePending = true (e.g. HR direct-assign).
            $oc = checkTimeSlotOverlap(
                $con, $internId, $cur,
                $allocStartTime, $allocEndTime,
                $excludeAllocationId,
                $includePending
            );
            if ($oc['overlap']) {
                $c = $oc['conflictWith'];
                $tag = isset($c['isPending']) && $c['isPending'] ? ' (pending)' : '';
                $errors[] = "$cur: overlaps with \"" . $c['project'] . "\"" . $tag . " "
                           . "(" . $c['startTime'] . " – " . $c['endTime'] . ").";
            }
        }
        $cur = date('Y-m-d', strtotime($cur . ' +1 day'));
    }

    return [
        'valid'    => empty($errors),
        'errors'   => $errors,
        'warnings' => $warnings,
    ];
}

// ─────────────────────────────────────────────
//  AJAX endpoint helper
//  Returns JSON-ready availability for one intern across a date range.
//  Called by getAvailability.php
// ─────────────────────────────────────────────

/**
 * Build the JSON payload served to the front-end.
 * Keys mirror what resourceRequest.php (JS) already expects, extended with range data.
 */
function buildAvailabilityPayload(
    $con,
    string $internId,
    string $forDate,       // display date (usually today OR selected start date)
    string $rangeStart,    // for range summary
    string $rangeEnd
): array {
    $ww = getInternWorkWindow($con, $internId);
    if (!$ww) {
        return ['error' => 'Intern work window not found'];
    }

    // Today's (or forDate's) detail
    $day = calculateAvailabilityForDate($con, $internId, $forDate);

    // Range summary
    $rangeData    = calculateAvailabilityForDateRange($con, $internId, $rangeStart, $rangeEnd);
    $rangeSummary = summariseDateRange($rangeData);

    // Build used-slots for the timeline (approved + pending, labelled)
    $usedSlots = [];
    if ($day['available']) {
        foreach ($day['allocations'] as $a) {
            $usedSlots[] = [
                's'         => $a['startMinutes'],
                'e'         => $a['endMinutes'],
                'project'   => $a['project'],
                'isPending' => false,
            ];
        }
        foreach ($day['pendingSlots'] as $a) {
            $usedSlots[] = [
                's'         => $a['startMinutes'],
                'e'         => $a['endMinutes'],
                'project'   => $a['project'] . ' ⏳',
                'isPending' => true,
            ];
        }
    }

    return [
        'workStartTime'   => $ww['workStartTime'],
        'workEndTime'     => $ww['workEndTime'],
        'workStart12'     => to12hr($ww['workStartTime']),
        'workEnd12'       => to12hr($ww['workEndTime']),
        'workStartMins'   => $ww['workStartMinutes'],
        'workEndMins'     => $ww['workEndMinutes'],
        'totalMins'       => $ww['totalMinutes'],

        // Day detail
        'dayAvailable'    => $day['available'],
        'dayReason'       => $day['reason'],
        'dayMessage'      => $day['message'],
        'usedMins'        => $day['usedMinutes'],
        'freeMins'        => $day['freeMinutes'],
        'usedSlots'       => $usedSlots,
        'freeSlots'       => $day['freeSlots'],   // canonical free slots from backend

        // Range summary
        'rangeMinFree'    => $rangeSummary['minFreeMinutes'],
        'rangeBlocked'    => $rangeSummary['blockedDates'],  // {date: reason, …}
    ];
}

// ─────────────────────────────────────────────
//  Private helpers
// ─────────────────────────────────────────────

function esc($con, string $v): string {
    return mysqli_real_escape_string($con, $v);
}

/** Cache userId lookups within a single request */
$_userIdCache = [];
function resolveUserId($con, string $internId): ?string {
    global $_userIdCache;
    if (isset($_userIdCache[$internId])) return $_userIdCache[$internId];
    $id = esc($con, $internId);
    $r  = mysqli_query($con, "SELECT userId FROM signup WHERE (internId='$id' OR userId='$id') LIMIT 1");
    if (!$r || mysqli_num_rows($r) === 0) {
        $_userIdCache[$internId] = $internId;
        return $internId;
    }
    $uid = mysqli_fetch_assoc($r)['userId'];
    $_userIdCache[$internId] = $uid;
    return $uid;
}

function fetchAllocRows($result, bool $isPending = false): array {
    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $s = timeToMinutes($row['allocStartTime']);
        $e = timeToMinutes($row['allocEndTime']);
        $rows[] = [
            'id'           => (int)$row['id'],
            'startTime'    => $row['allocStartTime'],
            'endTime'      => $row['allocEndTime'],
            'startMinutes' => $s,
            'endMinutes'   => $e,
            'project'      => $row['projectName'],
            'client'       => $row['clientName'],
            'isPending'    => $isPending,
        ];
    }
    return $rows;
}

function makeSlot(int $s, int $e): array {
    return [
        'start'     => $s,
        'end'       => $e,
        'startTime' => minutesToTime($s),
        'endTime'   => minutesToTime($e),
        'duration'  => $e - $s,
    ];
}

function to12hr(string $t24): string {
    [$h, $m] = explode(':', $t24);
    $h   = (int)$h;
    $ap  = $h >= 12 ? 'PM' : 'AM';
    $h12 = $h % 12 ?: 12;
    return str_pad((string)$h12, 2, '0', STR_PAD_LEFT) . ":$m $ap";
}

// Keep legacy entry-points so existing callers still work unchanged
function getFreeTimeSlotsForDate($con, string $internId, string $date): array {
    $av = calculateAvailabilityForDate($con, $internId, $date);
    return $av['freeSlots'] ?? [];
}