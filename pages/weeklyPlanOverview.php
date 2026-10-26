<?php
session_start();
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}

include('../include/db_config.php');
require_once('availabilityEngine.php');

$userId   = $_SESSION['userId'];
$userRow  = mysqli_fetch_assoc(mysqli_query($con,
    "SELECT fullName, userRole, designation FROM signup WHERE userId='$userId'"
));
$userName = $userRow ? htmlspecialchars($userRow['fullName']) : 'Unknown';
$userRole  = strtolower(trim($userRow['userRole'] ?? $_SESSION['userRole'] ?? ''));
$userDesig = strtolower(trim($userRow['designation'] ?? $_SESSION['designation'] ?? ''));

// Role-based access matrix
$directAssignRoles = ['resourceallocator', 'resource_allocator', 'resource allocator', 'resourcemanager', 'resource_manager', 'resourcehead', 'resource_head'];
$canDirectAssign   = in_array($userRole, $directAssignRoles) || in_array($userDesig, $directAssignRoles);

$viewOnlyRoles   = ['senior_intern', 'seniorintern', 'teamlead', 'team_lead', 'admin', 'superadmin', 'hr', 'md'];
$isViewOnly      = in_array($userRole, $viewOnlyRoles) || in_array($userDesig, $viewOnlyRoles);

// Block anyone who is neither a direct-assign role nor a view-only role
if (!$canDirectAssign && !$isViewOnly) {
    $redirectPage = (in_array($userRole, ['junior_intern', 'juniorintern'])) ? 'jrEmpDashboard.php' : 'login.php';
    echo "<script>window.location.href = '$redirectPage';</script>";
    exit;
}

// URL Parameters 
$weekOffset        = isset($_GET['w'])            ? (int)$_GET['w']             : 0;
$branchFilter      = isset($_GET['branch'])       ? trim($_GET['branch'])       : '';
$modeFilter        = isset($_GET['mode'])         ? trim($_GET['mode'])         : '';
$batchFilter       = isset($_GET['batch'])        ? trim($_GET['batch'])        : '';
$dateFilter        = isset($_GET['date'])         ? trim($_GET['date'])         : '';
$projectFilter     = isset($_GET['project'])      ? trim($_GET['project'])      : '';
$availStatusFilter = isset($_GET['avail_status']) ? trim($_GET['avail_status']) : '';

if ($dateFilter !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFilter)) {
    $dateFilter = '';
}

//Week calculation 
$todayTs   = strtotime('today');
$todayDate = date('Y-m-d', $todayTs);
$dayOfWeek = (int)date('N', $todayTs);

$baseWeekStart = strtotime('-' . ($dayOfWeek - 1) . ' days', $todayTs);
$weekStart     = strtotime(($weekOffset * 7) . ' days', $baseWeekStart);

$weekDays = [];
for ($i = 0; $i < 6; $i++) {
    $ts         = strtotime("+$i days", $weekStart);
    $weekDays[] = [
        'label' => date('D', $ts),
        'date'  => date('d M', $ts),
        'value' => date('Y-m-d', $ts),
    ];
}
$weekLabel = date('d M Y', $weekStart) . ' – ' . date('d M Y', strtotime('+5 days', $weekStart));

// Date filter auto-adjust week
if ($dateFilter !== '') {
    $dfTs     = strtotime($dateFilter);
    $dfDow    = (int)date('N', $dfTs);
    $dfWkMon  = strtotime('-' . ($dfDow - 1) . ' days', $dfTs);
    $diffWks  = (int)round(($dfWkMon - $baseWeekStart) / (7 * 86400));
    if ($diffWks !== $weekOffset) {
        $u = '?w=' . $diffWks . '&branch=' . urlencode($branchFilter)
           . '&mode=' . urlencode($modeFilter) . '&batch=' . urlencode($batchFilter)
           . '&project=' . urlencode($projectFilter) . '&avail_status=' . urlencode($availStatusFilter)
           . '&date=' . urlencode($dateFilter);
        header('Location: ' . $u);
        exit;
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function minutesBetween($start, $end) {
    if (empty($start) || empty($end)) return 0;
    [$sh, $sm] = explode(':', $start);
    [$eh, $em] = explode(':', $end);
    return ((int)$eh * 60 + (int)$em) - ((int)$sh * 60 + (int)$sm);
}
function fmtHours($minutes) {
    if ($minutes <= 0) return '0h';
    $h = floor($minutes / 60);
    $m = $minutes % 60;
    return $m === 0 ? $h . 'h' : $h . 'h ' . $m . 'm';
}

function isJuniorInternRole($designation, $userRole) {
    $d = strtolower(str_replace([' ', '_', '.'], '', (string)$designation));
    $r = strtolower(str_replace([' ', '_', '.'], '', (string)$userRole));
    if ($d === 'seniorintern' || $r === 'senior_intern' || $r === 'seniorintern') return false;
    if (strpos($d, 'senior') !== false || strpos($r, 'senior') !== false) return false;
    if (strpos($d, 'lead') !== false || strpos($r, 'lead') !== false) return false;
    if (strpos($d, 'resource') !== false || strpos($r, 'resource') !== false) return false;
    if (strpos($d, 'admin') !== false || strpos($r, 'admin') !== false) return false;
    if (strpos($d, 'hr') !== false || strpos($r, 'hr') !== false) return false;
    if (strpos($d, 'staff') !== false || strpos($r, 'staff') !== false) return false;
    if (strpos($d, 'developer') !== false || strpos($r, 'developer') !== false) return false;
    if (strpos($d, 'md') !== false || strpos($r, 'md') !== false) return false;
    return true;
}

function calcDayUsedMins($projectAllocs, $lunchItems) {
    $projectMins = array_sum(array_column($projectAllocs, 'minutes'));
    $nonOverlapLunchMins = 0;
    
    foreach ($lunchItems as $lb) {
        $lStart = $lb['startTime'] ?? '';
        $lEnd   = $lb['endTime'] ?? '';
        $lMins  = (int)($lb['minutes'] ?? 0);
        if ($lMins <= 0 || empty($lStart) || empty($lEnd) || $lEnd <= $lStart) continue;
        
        $overlapMins = 0;
        foreach ($projectAllocs as $pa) {
            $pStart = $pa['startTime'] ?? '';
            $pEnd   = $pa['endTime'] ?? '';
            if (empty($pStart) || empty($pEnd)) continue;
            
            $maxStart = max($lStart, $pStart);
            $minEnd   = min($lEnd, $pEnd);
            
            if ($maxStart < $minEnd) {
                $overlapMins += minutesBetween($maxStart, $minEnd);
            }
        }
        
        $effectiveOverlap = min($lMins, $overlapMins);
        $nonOverlapLunchMins += max(0, $lMins - $effectiveOverlap);
    }
    
    return $projectMins + $nonOverlapLunchMins;
}

// ── Build WHERE clause for interns ────────────────────────────────────────────
$filterWhereParts = [
    "(TRIM(LOWER(s.status)) = 'active' OR s.status = '1' OR s.status = 1)",
    "(TRIM(LOWER(s.regstatus)) = 'approved' OR s.regstatus IS NULL OR s.regstatus = '')",
    "(s.designation IS NULL OR LOWER(s.designation) NOT IN ('admin', 'hr', 'superadmin', 'developer', 'md', 'staff'))",
    "(s.userRole IS NULL OR LOWER(s.userRole) NOT IN ('admin', 'hr', 'superadmin', 'md', 'staff'))"
];

if ($branchFilter !== '') {
    $bSafe = mysqli_real_escape_string($con, $branchFilter);
    $filterWhereParts[] = "TRIM(s.branch) = '$bSafe'";
}
if ($modeFilter !== '') {
    $mSafe = mysqli_real_escape_string($con, $modeFilter);
    $filterWhereParts[] = "LOWER(TRIM(s.mode)) = '$mSafe'";
}
if ($batchFilter !== '') {
    $batSafe = mysqli_real_escape_string($con, $batchFilter);
    $filterWhereParts[] = "s.batch_number = '$batSafe'";
}
if ($projectFilter !== '') {
    $pSafe = mysqli_real_escape_string($con, $projectFilter);
    $filterWhereParts[] = "COALESCE(NULLIF(TRIM(s.internId),''), NULLIF(TRIM(s.emp_id),''), s.userId) COLLATE utf8mb4_unicode_ci IN (SELECT DISTINCT internId COLLATE utf8mb4_unicode_ci FROM internAllocations WHERE projectName = '$pSafe')";
}

$internWhere = implode(' AND ', $filterWhereParts);

$nonInternExclusion = "AND (designation IS NULL OR LOWER(designation) NOT IN ('admin', 'hr', 'superadmin', 'developer', 'md', 'staff')) AND (userRole IS NULL OR LOWER(userRole) NOT IN ('admin', 'hr', 'superadmin', 'md', 'staff'))";

// ── Branches, Modes, Batches for filter dropdowns ─────────────────────────────
$branchResult = mysqli_query($con,
    "SELECT DISTINCT TRIM(branch) AS branch FROM signup
     WHERE (TRIM(LOWER(status))='active' OR status='1' OR status=1) $nonInternExclusion AND branch IS NOT NULL AND TRIM(branch)!=''
     ORDER BY branch ASC"
);
$branches = [];
if ($branchResult) while ($b = mysqli_fetch_assoc($branchResult)) $branches[] = $b['branch'];

$modeResult = mysqli_query($con,
    "SELECT DISTINCT LOWER(TRIM(mode)) AS mode FROM signup
     WHERE (TRIM(LOWER(status))='active' OR status='1' OR status=1) $nonInternExclusion AND mode IS NOT NULL AND TRIM(mode)!=''
     ORDER BY mode ASC"
);
$modes = [];
if ($modeResult) while ($m = mysqli_fetch_assoc($modeResult)) $modes[] = $m['mode'];

$batchResult = mysqli_query($con,
    "SELECT DISTINCT batch_number FROM signup
     WHERE (TRIM(LOWER(status))='active' OR status='1' OR status=1) $nonInternExclusion AND batch_number IS NOT NULL AND TRIM(batch_number)!=''
     ORDER BY batch_number ASC"
);
$batches = [];
if ($batchResult) while ($b = mysqli_fetch_assoc($batchResult)) $batches[] = $b['batch_number'];

// ── Project list ──────────────────────────────────────────────────────────────
$projectList = [];
$pq = mysqli_query($con,
    "SELECT DISTINCT projectId, projectName, client FROM projectMaster
     WHERE status=1 ORDER BY projectName ASC"
);
if ($pq) while ($row = mysqli_fetch_assoc($pq)) $projectList[] = $row;

// ── Fetch active interns (Combining signup & active interns table) ────────────
$bCond = ($branchFilter !== '') ? "AND TRIM(COALESCE(branch, '')) = '" . mysqli_real_escape_string($con, $branchFilter) . "'" : "";
$mCond = ($modeFilter !== '') ? "AND LOWER(TRIM(COALESCE(mode, ''))) = '" . mysqli_real_escape_string($con, strtolower($modeFilter)) . "'" : "";
$batCond = ($batchFilter !== '') ? "AND batch = '" . mysqli_real_escape_string($con, $batchFilter) . "'" : "";
$pCond = ($projectFilter !== '') ? "AND internId COLLATE utf8mb4_unicode_ci IN (SELECT DISTINCT internId COLLATE utf8mb4_unicode_ci FROM internAllocations WHERE projectName = '" . mysqli_real_escape_string($con, $projectFilter) . "')" : "";

$internResult = mysqli_query($con,
    "SELECT DISTINCT internId, userId, fullName, designation, userRole, branch, mode, batch, phase, workStartTime, workEndTime FROM (
        SELECT COALESCE(NULLIF(TRIM(s.internId),''), NULLIF(TRIM(s.emp_id),''), s.userId) AS internId,
               s.userId, s.fullName, s.designation, s.userRole,
               COALESCE(s.branch, 'Not Assigned') AS branch,
               COALESCE(LOWER(TRIM(s.mode)), '')  AS mode,
               COALESCE(s.batch_number, '')        AS batch,
               'Active'                            AS phase,
               TIME_FORMAT(COALESCE(s.expectedPunchIn, '09:00'), '%H:%i') AS workStartTime,
               TIME_FORMAT(COALESCE(s.expectedPunchOut, '17:00'), '%H:%i') AS workEndTime
        FROM signup s
        WHERE $internWhere

        UNION

        SELECT i.internId AS internId,
               COALESCE(s.userId, i.internId) AS userId,
               i.fullName,
               COALESCE(s.designation, 'juniorIntern') AS designation,
               COALESCE(s.userRole, 'junior_intern') AS userRole,
               COALESCE(NULLIF(TRIM(i.branch),''), NULLIF(TRIM(i.intern_location),''), 'Not Assigned') AS branch,
               COALESCE(LOWER(TRIM(i.mode)), '') AS mode,
               COALESCE(i.batch, '') AS batch,
               'Active' AS phase,
               TIME_FORMAT(COALESCE(s.expectedPunchIn, '09:00'), '%H:%i') AS workStartTime,
               TIME_FORMAT(COALESCE(s.expectedPunchOut, '17:00'), '%H:%i') AS workEndTime
        FROM interns i
        INNER JOIN signup s ON (TRIM(s.internId) = TRIM(i.internId) OR (s.email IS NOT NULL AND s.email != '' AND LOWER(TRIM(s.email)) = LOWER(TRIM(i.email))))
        WHERE i.internId IS NOT NULL AND TRIM(i.internId) != ''
          AND (TRIM(LOWER(s.status)) = 'active' OR s.status = '1' OR s.status = 1)
          AND (TRIM(LOWER(s.regstatus)) = 'approved' OR s.regstatus IS NULL OR s.regstatus = '')
          AND (s.designation IS NULL OR LOWER(s.designation) NOT IN ('admin', 'hr', 'superadmin', 'developer', 'md', 'staff'))
          AND (s.userRole IS NULL OR LOWER(s.userRole) NOT IN ('admin', 'hr', 'superadmin', 'md', 'staff'))
    ) active_interns_combined
    WHERE internId IS NOT NULL AND TRIM(internId) != ''
    $bCond $mCond $batCond $pCond
    ORDER BY fullName ASC"
);
if (!$internResult) die("Intern query failed: " . mysqli_error($con));

$interns = [];
while ($r = mysqli_fetch_assoc($internResult)) {
    $r['totalMinutes'] = minutesBetween($r['workStartTime'], $r['workEndTime']);
    $r['isJuniorIntern'] = isJuniorInternRole($r['designation'] ?? '', $r['userRole'] ?? '');
    $interns[$r['internId']] = $r;
}

$wStart = $weekDays[0]['value'];
$wEnd   = $weekDays[5]['value'];

// ── Holidays ──────────────────────────────────────────────────────────────────
$holidayByDay  = [];
$holidayResult = mysqli_query($con,
    "SELECT title, from_date, to_date FROM holidays
     WHERE from_date <= '$wEnd' AND to_date >= '$wStart'"
);
if ($holidayResult) {
    while ($h = mysqli_fetch_assoc($holidayResult)) {
        foreach ($weekDays as $day) {
            if ($day['value'] >= $h['from_date'] && $day['value'] <= $h['to_date']) {
                $holidayByDay[$day['value']] = [
                    'title'    => $h['title'],
                    'fromDate' => $h['from_date'],
                    'toDate'   => $h['to_date']
                ];
            }
        }
    }
}

// ── Allocations ───────────────────────────────────────────────────────────────
$byDay = [];
if (!empty($interns)) {
    $internIds = array_map(fn($id) => "'" . mysqli_real_escape_string($con, $id) . "'",
        array_keys($interns));
    $internIdList = implode(',', $internIds);

    $allocResult = mysqli_query($con, "
        SELECT a.id,
               a.internId COLLATE utf8mb4_unicode_ci AS internId,
               a.projectName, a.clientName, a.startDate, a.endDate,
               TIME_FORMAT(COALESCE(a.allocStartTime, '09:00'), '%H:%i') AS allocStartTime,
               TIME_FORMAT(COALESCE(a.allocEndTime,   '17:00'), '%H:%i') AS allocEndTime
        FROM   internAllocations a
        WHERE  a.startDate <= '$wEnd' AND a.endDate >= '$wStart'
          AND  a.internId COLLATE utf8mb4_unicode_ci IN ($internIdList)
    ");
    if (!$allocResult) die("Alloc query failed: " . mysqli_error($con));

    while ($a = mysqli_fetch_assoc($allocResult)) {
        $iid = $a['internId'];
        if (!isset($interns[$iid])) continue;
        $allocMins = minutesBetween($a['allocStartTime'], $a['allocEndTime']);
        foreach ($weekDays as $day) {
            if ($day['value'] >= $a['startDate'] && $day['value'] <= $a['endDate']) {
                $byDay[$iid][$day['value']][] = [
                    'id'        => (int)$a['id'],
                    'project'   => $a['projectName'],
                    'client'    => $a['clientName'],
                    'startTime' => $a['allocStartTime'],
                    'endTime'   => $a['allocEndTime'],
                    'minutes'   => $allocMins,
                    'startDate' => $a['startDate'],
                    'endDate'   => $a['endDate'],
                ];
            }
        }
    }
}

// ── userId maps ───────────────────────────────────────────────────────────────
$userIdToInternId = [];
$internIdToUserId = [];
foreach ($interns as $iid => $intern) {
    if (!empty($intern['userId'])) {
        $userIdToInternId[$intern['userId']] = $iid;
        $internIdToUserId[$iid] = $intern['userId'];
    }
}

// ── Leaves & Tours ────────────────────────────────────────────────────────────
$leaveByDay = [];
$tourByDay  = [];
if (!empty($internIdToUserId)) {
    $userIds = array_map(fn($uid) => "'" . mysqli_real_escape_string($con, $uid) . "'",
        array_values($internIdToUserId));
    $userIdList = implode(',', $userIds);

    $leaveResult = mysqli_query($con,
        "SELECT l.userId, l.leave_name, l.leave_type, l.from_date, l.to_date, l.reason, l.approved_by, s_app.fullName AS approverName, 'leave' AS type
         FROM leaves l
         LEFT JOIN signup s_app ON s_app.userId = l.approved_by
         WHERE l.status='Approved' AND l.userId IN ($userIdList)
           AND (l.leave_name != 'Rostering Off' AND COALESCE(l.leave_type, '') != 'RO')
           AND l.from_date <= '$wEnd' AND l.to_date >= '$wStart'
         UNION ALL
         SELECT t.userID AS userId, p.projectName AS leave_name, '' AS leave_type, t.fromDate AS from_date, t.toDate AS to_date, t.reason, t.approvedBy AS approved_by, s_app.fullName AS approverName, 'tour' AS type
         FROM tour t
         LEFT JOIN signup s_app ON s_app.userId = t.approvedBy
         LEFT JOIN projectMaster p ON p.projectId = t.projectId
         WHERE t.status='Approved' AND t.userID IN ($userIdList)
           AND t.fromDate <= '$wEnd' AND t.toDate >= '$wStart'"
    );
    if ($leaveResult) {
        while ($lv = mysqli_fetch_assoc($leaveResult)) {
            $lvInternId = $userIdToInternId[$lv['userId']] ?? null;
            if (!$lvInternId) continue;
            $appr = !empty($lv['approverName']) ? $lv['approverName'] : (!empty($lv['approved_by']) ? $lv['approved_by'] : 'Management / HR');
            $detail = [
                'leaveName'  => $lv['leave_name'] ?: ($lv['type'] === 'leave' ? 'Leave' : 'Official Tour'),
                'leaveType'  => $lv['leave_type'] ?: '',
                'reason'     => $lv['reason'] ?: 'No reason provided',
                'approvedBy' => $appr,
                'fromDate'   => $lv['from_date'],
                'toDate'     => $lv['to_date'],
                'projectName'=> $lv['leave_name'] ?: 'Official Tour'
            ];
            foreach ($weekDays as $day) {
                if ($day['value'] >= $lv['from_date'] && $day['value'] <= $lv['to_date']) {
                    if ($lv['type'] === 'leave') $leaveByDay[$lvInternId][$day['value']] = $detail;
                    else                         $tourByDay[$lvInternId][$day['value']]  = $detail;
                }
            }
        }
    }
}

$rosterOffByDay = [];
// ── Roster Off ── from leaves table where leave_name = 'Rostering Off' ────────
if (!empty($internIdToUserId)) {
    $roUserIds = array_map(fn($uid) => "'" . mysqli_real_escape_string($con, $uid) . "'",
        array_values($internIdToUserId));
    $roUserIdList = implode(',', $roUserIds);
    $rosterResult = mysqli_query($con,
        "SELECT l.userId, l.from_date, l.to_date, l.reason, l.approved_by, s_app.fullName AS approverName
         FROM leaves l
         LEFT JOIN signup s_app ON s_app.userId = l.approved_by
         WHERE l.status='Approved'
           AND (l.leave_name = 'Rostering Off' OR COALESCE(l.leave_type, '') = 'RO')
           AND l.userId IN ($roUserIdList)
           AND l.from_date <= '$wEnd' AND l.to_date >= '$wStart'"
    );
    if ($rosterResult) {
        while ($ro = mysqli_fetch_assoc($rosterResult)) {
            $roInternId = $userIdToInternId[$ro['userId']] ?? null;
            if (!$roInternId) continue;
            $appr = !empty($ro['approverName']) ? $ro['approverName'] : (!empty($ro['approved_by']) ? $ro['approved_by'] : 'Management / HR');
            $detail = [
                'leaveName'  => 'Rostering Off',
                'reason'     => $ro['reason'] ?: 'Rostering Off Day',
                'approvedBy' => $appr,
                'fromDate'   => $ro['from_date'],
                'toDate'     => $ro['to_date']
            ];
            foreach ($weekDays as $day) {
                if ($day['value'] >= $ro['from_date'] && $day['value'] <= $ro['to_date']) {
                    $rosterOffByDay[$roInternId][$day['value']] = $detail;
                }
            }
        }
    }
}

// ── Lunch Breaks from TaskEntryNew ────────────────────────────────────────────
$lunchByDay = [];
if (!empty($internIdToUserId)) {
    $lunchUserIds = array_map(fn($uid) => "'" . mysqli_real_escape_string($con, $uid) . "'",
        array_values($internIdToUserId));
    $lunchUserIdList = implode(',', $lunchUserIds);

    $lunchResult = mysqli_query($con, "
        SELECT t.userId,
               t.startTime,
               t.endTime,
               t.status,
               t.totalDuration,
               DATE_FORMAT(t.startTime, '%Y-%m-%d') AS dateVal,
               TIME_FORMAT(t.startTime, '%H:%i') AS startTimeFmt,
               TIME_FORMAT(COALESCE(t.endTime, NOW()), '%H:%i') AS endTimeFmt
        FROM TaskEntryNew t
        WHERE (t.task = 'Lunch Break' OR t.workType = 'Lunch Break')
          AND t.startTime >= '$wStart 00:00:00'
          AND t.startTime <= '$wEnd 23:59:59'
          AND t.userId IN ($lunchUserIdList)
        ORDER BY t.startTime ASC
    ");
    if ($lunchResult) {
        while ($lb = mysqli_fetch_assoc($lunchResult)) {
            $lbInternId = $userIdToInternId[$lb['userId']] ?? null;
            if (!$lbInternId) continue;
            
            $dVal  = $lb['dateVal'];
            $stFmt = $lb['startTimeFmt'];
            $etFmt = $lb['endTimeFmt'];
            
            $durMins = minutesBetween($stFmt, $etFmt);
            if ($durMins <= 0) $durMins = 60;
            
            $lunchByDay[$lbInternId][$dVal][] = [
                'id'        => 'lunch_' . $lb['userId'] . '_' . $dVal,
                'project'   => '🍱 Lunch Break',
                'client'    => 'Lunch Break',
                'startTime' => $stFmt,
                'endTime'   => $etFmt,
                'minutes'   => $durMins,
                'isLunch'   => true,
                'status'    => $lb['status'],
                'startDate' => $dVal,
                'endDate'   => $dVal
            ];
        }
    }
}

// Availability legend filtering is handled seamlessly client-side via DataTables custom search extension.

// ── Calculate Stats & Exact Hours for Cards ───────────────────────────────────
$totalInterns     = count($interns);
$allocatedCount   = 0;
$freeCount        = 0;
$overCapCount     = 0;

$totalCapMins   = 0;
$totalAllocMins = 0;
$totalFreeMins  = 0;
$totalOverMins  = 0;

foreach ($interns as $iid => $intern) {
    $hasAlloc = false;
    $isOver   = false;
    $isJr     = $intern['isJuniorIntern'] ?? false;

    foreach ($weekDays as $day) {
        $dow           = (int)date('N', strtotime($day['value']));
        $isJrWeekend   = ($isJr && ($dow === 6 || $dow === 7));

        $projectAllocs = $byDay[$iid][$day['value']] ?? [];
        $lunchItems    = $lunchByDay[$iid][$day['value']] ?? [];
        $allocs        = array_merge($projectAllocs, $lunchItems);
        $usedMins      = calcDayUsedMins($projectAllocs, $lunchItems);

        $onLeave       = !empty($leaveByDay[$iid][$day['value']]);
        $rosterOff     = !empty($rosterOffByDay[$iid][$day['value']]);
        $onTour        = !empty($tourByDay[$iid][$day['value']]);
        $isHoliday     = isset($holidayByDay[$day['value']]) || ($isJrWeekend && empty($projectAllocs) && !$onLeave && !$onTour && !$rosterOff);

        $totalMins     = $isJrWeekend ? 0 : $intern['totalMinutes'];
        $freeMins      = max(0, $totalMins - $usedMins);

        if (!$onLeave && !$onTour && !$rosterOff && !$isHoliday) {
            $totalCapMins += $totalMins;
            $totalFreeMins += $freeMins;
        }

        $totalAllocMins += $usedMins;

        if ($usedMins > 0) $hasAlloc = true;
        if ($totalMins > 0 && $usedMins > $totalMins) {
            $isOver = true;
            $totalOverMins += ($usedMins - $totalMins);
        }
    }
    $hasAlloc ? $allocatedCount++ : $freeCount++;
    if ($isOver) $overCapCount++;
}

$capHrs   = round($totalCapMins / 60);
$allocHrs = round($totalAllocMins / 60);
$freeHrs  = round($totalFreeMins / 60);
$overHrs  = round($totalOverMins / 60);

// ── Build schedule JSON for JS ────────────────────────────────────────────────
$scheduleJson = [];
foreach ($interns as $iid => $intern) {
    $days = [];
    $isJr = $intern['isJuniorIntern'] ?? false;

    foreach ($weekDays as $day) {
        $dow           = (int)date('N', strtotime($day['value']));
        $isJrWeekend   = ($isJr && ($dow === 6 || $dow === 7));

        $projectAllocs = $byDay[$iid][$day['value']] ?? [];
        $lunchItems    = $lunchByDay[$iid][$day['value']] ?? [];
        $allocs        = array_merge($projectAllocs, $lunchItems);
        $usedMins      = calcDayUsedMins($projectAllocs, $lunchItems);

        $leaveData     = $leaveByDay[$iid][$day['value']] ?? null;
        $rosterData    = $rosterOffByDay[$iid][$day['value']] ?? null;
        $tourData      = $tourByDay[$iid][$day['value']] ?? null;
        $holidayData   = $holidayByDay[$day['value']] ?? null;

        $onLeave       = !empty($leaveData);
        $rosterOff     = !empty($rosterData);
        $onTour        = !empty($tourData);

        if (!$holidayData && $isJrWeekend && empty($projectAllocs) && !$onLeave && !$onTour && !$rosterOff) {
            $holidayData = [
                'title'    => 'Holiday',
                'fromDate' => $day['value'],
                'toDate'   => $day['value']
            ];
        }

        $isHoliday     = !empty($holidayData);
        $holidayTitle  = $isHoliday ? ($holidayData['title'] ?? 'Holiday') : '';

        $totalMins     = $isJrWeekend ? 0 : $intern['totalMinutes'];
        $freeMins      = max(0, $totalMins - $usedMins);

        $days[$day['value']] = compact(
            'allocs','usedMins','freeMins','totalMins',
            'onLeave','rosterOff','onTour','isHoliday','holidayTitle',
            'leaveData','rosterData','tourData','holidayData'
        );
    }
    $scheduleJson[$iid] = [
        'name'           => $intern['fullName'],
        'branch'         => $intern['branch'] ?? '',
        'mode'           => $intern['mode']   ?? '',
        'batch'          => $intern['batch']  ?? '',
        'phase'          => $intern['phase']  ?? 'Not Set',
        'isJuniorIntern' => $isJr,
        'workStartTime'  => $intern['workStartTime'],
        'workEndTime'    => $intern['workEndTime'],
        'totalMins'      => $intern['totalMinutes'],
        'days'           => $days,
    ];
}

// ── renderPill ────────────────────────────────────────────────────────────────
function renderPill($allocs, $usedMins, $freeMins, $totalMins, $isPastDay,
                    $name_s, $iid_s, $dateVal, $onLeave, $rosterOff,
                    $onTour = false, $isHoliday = false, $holidayTitle = '') {
    $oc = 'onclick="openPanel(\'' . $iid_s . '\',\'' . addslashes($name_s) . '\',\'' . $dateVal . '\')"';

    if ($isHoliday) {
        $label = $holidayTitle ? htmlspecialchars($holidayTitle) : 'Holiday';
        return '<div class="cell-pill pill-holiday" ' . $oc . ' title="' . htmlspecialchars($holidayTitle) . ' - Click for details">'
            . '<div class="pill-main"><span class="pill-dot dot-holiday"></span><span>🎉 ' . $label . '</span></div>'
            . '</div>';
    }
    if ($onLeave) {
        return '<div class="cell-pill pill-leave" ' . $oc . ' title="Approved Leave - Click for details">'
            . '<div class="pill-main"><span class="pill-dot dot-leave"></span><span>On Leave</span></div></div>';
    }
    if ($onTour) {
        return '<div class="cell-pill pill-tour" ' . $oc . ' title="On Approved Tour - Click for details">'
            . '<div class="pill-main"><span class="pill-dot dot-tour"></span><span>✈️ On Tour</span></div></div>';
    }
    if ($rosterOff) {
        return '<div class="cell-pill pill-roster" ' . $oc . ' title="Rostering Off - Click for details">'
            . '<div class="pill-main"><span class="pill-dot dot-roster"></span><span>Rostering Off</span></div></div>';
    }

    $over       = ($totalMins > 0) && ($usedMins > $totalMins);
    $pct        = $totalMins > 0 ? min(100, round($usedMins / $totalMins * 100)) : 0;
    $freeLabel  = fmtHours($freeMins);
    $usedLabel  = fmtHours($usedMins);
    $totalLabel = fmtHours($totalMins);
    $oc         = 'onclick="openPanel(\'' . $iid_s . '\',\'' . addslashes($name_s) . '\',\'' . $dateVal . '\')"';

    if (empty($allocs)) {
        $cls  = $isPastDay ? 'pill-past' : 'pill-free';
        $icon = $isPastDay ? '<span class="pill-dot dot-past"></span>' : '<span class="pill-dot dot-free"></span>';
        $txt  = $totalLabel . ' available';
        $bar  = '';
    } elseif ($over) {
        $cls  = 'pill-over'; $icon = '<span class="pill-dot dot-over"></span>'; $txt = $usedLabel . ' · over cap';
        $bar  = '<span class="pill-progress"><span class="pill-progress-fill" style="width:100%;background:#E24B4A;"></span></span>';
    } elseif ($freeMins > 0) {
        $cls  = 'pill-partial'; $icon = '<span class="pill-dot dot-partial"></span>'; $txt = $freeLabel . ' free';
        $bar  = '<span class="pill-progress"><span class="pill-progress-fill" style="width:' . $pct . '%;background:#4b49ac;"></span></span>';
    } else {
        $cls  = 'pill-full'; $icon = '<span class="pill-dot dot-full"></span>'; $txt = $usedLabel . ' · full';
        $bar  = '<span class="pill-progress"><span class="pill-progress-fill" style="width:100%;background:#3B6D11;"></span></span>';
    }

    return '<div class="cell-pill ' . $cls . '" ' . $oc . '>'
        . '<div class="pill-main">' . $icon . '<span>' . $txt . '</span></div>' . $bar . '</div>';
}

$singleDateMode   = ($dateFilter !== '');
$singleDateInWeek = $singleDateMode
    && ($dateFilter >= $weekDays[0]['value'])
    && ($dateFilter <= $weekDays[5]['value']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>Weekly Plan Overview</title>
<?php include('../partials/_links.php'); ?>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap4.min.css">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

<style>
.stat-card{background:#fff;border-radius:12px;padding:18px 22px;box-shadow:0 2px 12px rgba(47,85,255,.07);display:flex;align-items:center;gap:16px;margin-bottom:20px;border-left:4px solid #4b49ac}
.stat-card.allocated{border-left-color:#3B6D11}
.stat-card.free{border-left-color:#854F0B}
.stat-card .stat-icon{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0}
.stat-card.total .stat-icon{background:#EEEDFE;color:#4b49ac}
.stat-card.allocated .stat-icon{background:#eaf3de;color:#3B6D11}
.stat-card.free .stat-icon{background:#FAEEDA;color:#854F0B}
.stat-card .stat-number{font-size:1.6rem;font-weight:800;color:#222;line-height:1}
.stat-card .stat-label{font-size:0.8rem;color:#888;margin-top:2px}
.stat-card .stat-sub{font-size:0.75rem;font-weight:700;color:#4b49ac;margin-top:4px}

.select2-container .select2-selection--single{height:calc(1.5em + .75rem + 2px) !important;border:1px solid #ced4da !important;border-radius:4px !important;padding:0 !important;background-color:#fff !important;box-shadow:none !important}
.select2-container--default .select2-selection--single .select2-selection__rendered{line-height:calc(1.5em + .75rem + 2px) !important;padding-left:12px !important;color:#495057 !important;font-size:0.75rem !important}
.select2-container--default .select2-selection--single .select2-selection__arrow{height:calc(1.5em + .75rem + 2px) !important;right:8px !important}
.select2-container--default.select2-container--focus .select2-selection--single,
.select2-container--default.select2-container--open  .select2-selection--single{border-color:#80bdff !important;box-shadow:0 0 0 .2rem rgba(0,123,255,.25) !important}
.select2-dropdown{border:1px solid #ced4da !important;border-radius:4px !important;font-size:0.75rem !important}
.select2-container--default .select2-results__option--highlighted[aria-selected]{background-color:#4b49ac !important}
.filter-bar .select2-container .select2-selection--single{height:34px !important;border:1px solid #c5c4e8 !important;border-radius:6px !important;background-color:#fff !important;}
.filter-bar .select2-container--default .select2-selection--single .select2-selection__rendered{line-height:34px !important;padding-left:10px !important;padding-right:20px !important;font-size:0.78rem !important;color:#495057 !important;}
.filter-bar .select2-container--default .select2-selection--single .select2-selection__arrow{height:34px !important;right:4px !important;}
.filter-bar .select2-container--default .select2-selection--single .select2-selection__clear{height:34px !important;line-height:34px !important;margin-right:4px !important;font-size:14px !important;color:#999 !important;}
.toolbar-row{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:16px}
.toolbar-left{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.toolbar-right{display:flex;align-items:center;gap:8px;flex-shrink:0;flex-wrap:wrap}
.filter-bar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:10px 14px;background:#f8f8ff;border:1px solid #e0dfff;border-radius:8px;margin-bottom:10px}
.filter-group{display:flex;align-items:center;gap:6px;}
.filter-bar-label{font-size:0.7rem;font-weight:600;color:#6c757d;white-space:nowrap;}
.filter-select{height:34px;border:1px solid #c5c4e8;border-radius:6px;padding:0 10px;font-size:0.78rem;color:#495057;background:#fff;outline:none;cursor:pointer;transition:border-color .15s;min-width:140px;}
.filter-select:focus{border-color:#4b49ac;box-shadow:0 0 0 2px rgba(75,73,172,.12)}
.btn-clear-filters{background:#dc3545;color:#fff;border:none;border-radius:6px;padding:6px 12px;font-size:0.75rem;font-weight:600;cursor:pointer;white-space:nowrap;transition:background .13s;}
.btn-clear-filters:hover{background:#c82333}

/* Interactive Legend Buttons */
.legend-btn {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 10px; border-radius: 16px; border: 1.5px solid #e0e4ef;
    background: #fff; color: #555; font-size: 0.75rem; font-weight: 600;
    cursor: pointer; transition: all 0.2s; user-select: none;
}
.legend-btn:hover {
    border-color: #4b49ac; color: #4b49ac; background: #f8f9fe; transform: translateY(-1px);
}
.legend-btn.active-legend {
    background: #4b49ac; color: #fff; border-color: #4b49ac; box-shadow: 0 2px 8px rgba(75,73,172,0.25);
}
.legend-btn.active-legend .pill-dot { border: 1px solid #fff; }

.week-nav-wrap{display:flex;align-items:center;gap:6px;background:#f0f0fb;border:1px solid #c5c4e8;border-radius:8px;padding:5px 10px;white-space:nowrap}
.week-range-lbl{font-size:0.76rem;color:#4b49ac;font-weight:600;min-width:175px;text-align:center;cursor:pointer;user-select:none;border-bottom:1px dashed #c5c4e8;padding-bottom:1px}
.week-range-lbl:hover{color:#3b3a8f}
.btn-wk{background:#fff;border:1px solid #c5c4e8;color:#4b49ac;border-radius:5px;width:28px;height:28px;display:flex;align-items:center;justify-content:center;font-size:0.75rem;cursor:pointer;text-decoration:none;flex-shrink:0;transition:background .13s}
.btn-wk:hover{background:#e4e3f8;color:#3b3a8f}
.btn-wk-today{background:#4b49ac;border:1px solid #4b49ac;color:#fff;border-radius:5px;padding:0 10px;height:28px;display:flex;align-items:center;font-size:0.72rem;font-weight:600;cursor:pointer;text-decoration:none;white-space:nowrap;transition:background .13s}
.btn-wk-today:hover{background:#3b3a8f;color:#fff}
.dl-wrap{position:relative;display:inline-flex;flex-shrink:0}
.dl-split{display:inline-flex;align-items:stretch;border-radius:6px;overflow:hidden;border:1px solid #28a745}
.dl-split .dl-main{background:#28a745;color:#fff;border:none;padding:5px 14px;font-size:0.78rem;font-weight:600;cursor:pointer;white-space:nowrap;display:flex;align-items:center;gap:5px;transition:background .13s}
.dl-split .dl-main:hover{background:#218838}
.dl-split .dl-div{width:1px;background:rgba(255,255,255,.35)}
.dl-split .dl-caret{background:#28a745;color:#fff;border:none;padding:5px 10px;cursor:pointer;display:flex;align-items:center;transition:background .13s}
.dl-split .dl-caret:hover{background:#1e7e34}
.dl-menu{position:absolute;top:calc(100% + 4px);right:0;background:#fff;border:1px solid #dee2e6;border-radius:6px;box-shadow:0 4px 16px rgba(0,0,0,.12);z-index:9999;min-width:155px;overflow:hidden;display:none}
.dl-menu.open{display:block}
.dl-menu a{display:flex;align-items:center;gap:8px;padding:10px 14px;font-size:0.8rem;color:#2d2d2d;text-decoration:none;transition:background .12s}
.dl-menu a:hover{background:#f0f0fb;color:#4b49ac}
.btn-request{background:#4b49ac;color:#fff;border:none;border-radius:6px;padding:6px 14px;font-size:0.78rem;font-weight:600;cursor:pointer;white-space:nowrap;transition:background .13s}
.btn-request:hover{background:#3b3a8f}
.weekly-table-wrap{width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch}
#weeklyTable_wrapper .dataTables_filter{display:none}
.dt-search-wrap{display:flex;align-items:center;background:#fff;border:1px solid #c5c4e8;border-radius:7px;overflow:hidden;height:34px}
.dt-search-wrap i{padding:0 10px;color:#9090a8;font-size:0.85rem;flex-shrink:0}
.dt-search-wrap input{border:none;outline:none;font-size:0.82rem;color:#495057;background:transparent;width:180px;padding-right:10px}
#weeklyTable_wrapper .dataTables_length label{font-size:0.8rem;color:#495057}
#weeklyTable_wrapper .dataTables_length select{border:1px solid #c5c4e8;border-radius:5px;padding:3px 8px;font-size:0.8rem;outline:none}
#weeklyTable_wrapper .dataTables_info{font-size:0.78rem;color:#9090a8;padding-top:8px}
#weeklyTable_wrapper .dataTables_paginate{padding-top:6px}
#weeklyTable_wrapper .dataTables_paginate .paginate_button{border-radius:5px !important;font-size:0.78rem !important;padding:4px 9px !important}
#weeklyTable_wrapper .dataTables_paginate .paginate_button.current,
#weeklyTable_wrapper .dataTables_paginate .paginate_button.current:hover{background:#4b49ac !important;border-color:#4b49ac !important;color:#fff !important}
#weeklyTable_wrapper .dataTables_paginate .paginate_button:hover{background:#f0f0fb !important;border-color:#c5c4e8 !important;color:#4b49ac !important}
#weeklyTable{width:100% !important;min-width:1180px;border-collapse:separate;border-spacing:0;table-layout:fixed}
#weeklyTable thead th{background:#2d2d2d;color:#fff;font-size:0.78rem;font-weight:600;white-space:nowrap;border:none !important;padding:11px 10px !important;vertical-align:middle;position:sticky;top:0;z-index:2}
#weeklyTable thead th:first-child{border-radius:8px 0 0 0;width:270px;min-width:270px}
#weeklyTable tbody td:first-child{width:270px;min-width:270px;max-width:270px;overflow:hidden}
#weeklyTable thead th:last-child{border-radius:0 8px 0 0}
.th-today{background:#3b3a8f !important}
.th-past{background:#555 !important;opacity:.85}
.th-future{background:#2d2d2d !important}
.th-saturday{background:#1a3a5c !important}
.th-holiday{background:#7B1D1D !important}
.th-date-focus{background:#1a4d2e !important;outline:2px solid #5cdb95;}
#weeklyTable tbody tr{transition:background .12s}
#weeklyTable tbody tr:hover{background:#f5f5ff !important}
#weeklyTable tbody tr:nth-child(even){background:#fafafa}
#weeklyTable tbody td{vertical-align:middle;font-size:0.85rem;border-color:#f0f0f5 !important;padding:10px 10px !important}
.intern-name{font-size:0.85rem;font-weight:700;color:#1a1a2e;line-height:1.25;word-break:break-word}
.intern-meta{font-size:0.7rem;color:#6c757d;margin-top:2px;line-height:1.35;word-break:break-word}
.intern-hours{font-size:0.7rem;color:#4b49ac;font-weight:600;margin-top:3px;line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.day-cell{text-align:center;min-width:130px;padding:10px 8px !important}
.day-cell-today{background:#f6f5ff}
.day-cell-past{background:#fafafa}
.day-cell-saturday{background:#f0f5ff}
.day-cell-holiday{background:#fff1f1 !important}
.day-cell-date-focus{background:#f0fff4 !important;outline:2px inset #5cdb95}
.cell-pill{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:5px;padding:7px 10px;border-radius:8px;font-size:0.73rem;font-weight:600;cursor:pointer;border:1.5px solid transparent;transition:filter .14s,border-color .14s;width:100%}
.cell-pill:hover{filter:brightness(0.94)}
.pill-main{display:flex;align-items:center;gap:6px}
.pill-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0;display:inline-block}
.dot-free{background:#7F77DD}.dot-partial{background:#4b49ac}.dot-full{background:#3B6D11}
.dot-over{background:#E24B4A}.dot-past{background:#bbb}.dot-leave{background:#c0392b}
.dot-roster{background:#7f8c8d}.dot-holiday{background:#b91c1c}.dot-tour{background:#1d4ed8}
.pill-free{background:#EEEDFE;border-color:#AFA9EC;color:#3C3489}
.pill-partial{background:#f0f0fb;border-color:#c5c4e8;color:#4b49ac}
.pill-full{background:#eaf3de;border-color:#97c459;color:#27500A}
.pill-over{background:#FCEBEB;border-color:#F09595;color:#791F1F}
.pill-past{background:#f4f4f4;border-color:#e0e0e0;color:#aaa}
.pill-leave{background:#FDECEC;border-color:#f1948a;color:#922b21;cursor:pointer;}
.pill-roster{background:#f2f3f4;border-color:#aab7b8;color:#5d6d7e;cursor:pointer;}
.pill-holiday{background:#FEE2E2;border-color:#FECACA;color:#7F1D1D;cursor:pointer;}
.pill-tour{background:#DBEAFE;border-color:#BFDBFE;color:#1E3A8A;cursor:pointer;}
.pill-progress{display:block;height:3px;border-radius:3px;background:rgba(0,0,0,.08);width:100%;overflow:hidden}
.pill-progress-fill{height:100%;border-radius:99px;transition:width .3s}
.date-focus-notice{display:flex;align-items:center;justify-content:space-between;padding:8px 14px;background:#f0fff4;border:1px solid #a7f3d0;border-radius:8px;margin-bottom:10px;font-size:0.8rem;color:#065f46;flex-wrap:wrap;gap:8px}
.date-focus-notice strong{color:#047857}
.btn-clear-date{background:none;border:1px solid #6ee7b7;color:#065f46;border-radius:5px;padding:3px 10px;font-size:0.75rem;font-weight:600;cursor:pointer;transition:background .13s}
.btn-clear-date:hover{background:#d1fae5}
.dt-info-bar{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:12px;padding:10px 14px;background:#f8f8ff;border:1px solid #e0dfff;border-radius:8px;font-size:0.8rem;color:#6c757d}
.date-input-filter{height:34px;border:1px solid #c5c4e8;border-radius:6px;padding:0 8px;font-size:0.78rem;color:#495057;background:#fff;outline:none;cursor:pointer;transition:border-color .15s}
.date-input-filter:focus{border-color:#4b49ac;box-shadow:0 0 0 2px rgba(75,73,172,.12)}
.btn-clear-date-sm{background:none;border:none;color:#c0392b;font-size:0.72rem;font-weight:600;cursor:pointer;padding:0 4px;border-radius:4px;white-space:nowrap}
.btn-clear-date-sm:hover{background:#FDECEC}
.wk-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:2000;align-items:center;justify-content:center}
.wk-overlay.open{display:flex}
.wk-cal{background:#fff;border-radius:14px;width:380px;max-width:calc(100vw - 24px);box-shadow:0 20px 60px rgba(0,0,0,.22);overflow:hidden}
.wk-cal-head{background:#4b49ac;color:#fff;padding:14px 18px;display:flex;align-items:center;justify-content:space-between}
.wk-cal-head .wk-title{font-size:0.92rem;font-weight:700}
.wk-cal-head .wk-close{background:rgba(255,255,255,.18);border:none;color:#fff;width:26px;height:26px;border-radius:50%;cursor:pointer;font-size:1rem;display:flex;align-items:center;justify-content:center}
.wk-cal-head .wk-close:hover{background:rgba(255,255,255,.32)}
.wk-nav{display:flex;align-items:center;justify-content:space-between;padding:10px 16px;border-bottom:1px solid #f0efff}
.wk-nav .wk-month-lbl{font-size:0.9rem;font-weight:700;color:#2d2d2d;cursor:pointer;user-select:none}
.wk-nav-btn{background:none;border:1px solid #e0dfff;color:#4b49ac;border-radius:5px;width:28px;height:28px;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:0.75rem;transition:all .13s}
.wk-nav-btn:hover{background:#f0f0fb}
.wk-dow-row{display:grid;grid-template-columns:28px repeat(7,1fr);padding:8px 12px 4px;font-size:0.68rem;font-weight:700;color:#adb5bd;text-transform:uppercase;letter-spacing:.04em}
.wk-grid{padding:0 12px 12px}
.wk-row{display:grid;grid-template-columns:28px repeat(7,1fr);margin-bottom:2px;border-radius:8px;cursor:pointer;transition:background .12s}
.wk-row:hover .wk-day{background:rgba(75,73,172,.07)}
.wk-row:hover .wk-day:first-child{background:transparent}
.wk-row.wk-selected .wk-day{background:#4b49ac;color:#fff}
.wk-row.wk-selected .wk-day:first-child{background:transparent;color:#4b49ac}
.wk-row.wk-current-row .wk-day{background:#f0f0fb;color:#4b49ac}
.wk-row.wk-current-row .wk-day:first-child{background:transparent;color:#adb5bd}
.wk-day{height:32px;display:flex;align-items:center;justify-content:center;font-size:0.8rem;border-radius:6px;transition:background .1s}
.wk-day.wk-num{font-size:0.65rem;color:#adb5bd;font-weight:600;border-radius:0;background:transparent !important}
.wk-day.other-month{color:#ccc}
.wk-day.today-cell{font-weight:800;position:relative}
.wk-day.today-cell::after{content:'';position:absolute;bottom:3px;left:50%;transform:translateX(-50%);width:4px;height:4px;border-radius:50%;background:#4b49ac}
.wk-row.wk-selected .wk-day.today-cell::after{background:#fff}
.wk-footer{padding:10px 16px 14px;border-top:1px solid #f0efff;display:flex;gap:8px}
.wk-footer .wk-go{flex:1;background:#4b49ac;color:#fff;border:none;border-radius:7px;padding:9px;font-size:0.83rem;font-weight:600;cursor:pointer}
.wk-footer .wk-go:hover{background:#3b3a8f}
.wk-footer .wk-go:disabled{opacity:.55;cursor:not-allowed}
.wk-footer .wk-cancel{background:none;color:#6c757d;border:1px solid #dee2e6;border-radius:7px;padding:9px 16px;font-size:0.82rem;cursor:pointer}
.wk-footer .wk-today-btn{background:none;color:#4b49ac;border:1px solid #c5c4e8;border-radius:7px;padding:9px 14px;font-size:0.82rem;font-weight:600;cursor:pointer}
.panel-backdrop{visibility:hidden;opacity:0;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1054;transition:opacity .2s,visibility .2s}
.panel-backdrop.open{visibility:visible;opacity:1}
#dayPanel{display:flex;flex-direction:column;visibility:hidden;pointer-events:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%) scale(.95);width:540px;max-width:calc(100vw - 32px);max-height:88vh;background:#fff;border-radius:12px;box-shadow:0 20px 60px rgba(0,0,0,.22);z-index:1055;overflow:hidden;opacity:0;transition:opacity .2s,transform .2s,visibility .2s}
#dayPanel.open{visibility:visible;pointer-events:auto;opacity:1;transform:translate(-50%,-50%) scale(1)}
.panel-header{color:#fff;padding:18px 20px 14px;flex-shrink:0}
.panel-header-top{display:flex;align-items:flex-start;justify-content:space-between}
.panel-intern-name{font-size:1rem;font-weight:700;margin:0;line-height:1.2}
.panel-day-label{font-size:0.78rem;opacity:.8;margin-top:3px}
.panel-close{background:rgba(255,255,255,.18);border:none;color:#fff;width:28px;height:28px;border-radius:50%;cursor:pointer;font-size:1rem;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.panel-close:hover{background:rgba(255,255,255,.32)}
.panel-hrs-bar-wrap{margin-top:14px;display:flex;align-items:center;gap:10px}
.panel-hrs-bar{flex:1;height:6px;background:rgba(255,255,255,.25);border-radius:99px;overflow:hidden}
.panel-hrs-fill{height:100%;border-radius:99px;background:#fff;transition:width .3s}
.panel-hrs-fill.over{background:#ffc078}
.panel-hrs-label{font-size:0.78rem;font-weight:600;opacity:.9;white-space:nowrap}
.panel-free-badge{display:inline-block;margin-top:8px;background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.35);color:#fff;border-radius:20px;padding:3px 12px;font-size:0.74rem;font-weight:600}
.panel-work-window{display:inline-block;margin-top:4px;font-size:0.72rem;opacity:.75}
.panel-body{flex:1;overflow-y:auto;padding:20px}
.proj-row{display:flex;align-items:flex-start;gap:12px;padding:12px 14px;border-radius:8px;background:#f8f9fa;border:1px solid #e9ecef;margin-bottom:10px}
.proj-dot{width:10px;height:10px;border-radius:50%;margin-top:4px;flex-shrink:0}
.proj-info{flex:1;min-width:0}
.proj-name{font-size:0.875rem;font-weight:600;color:#2d2d2d;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.proj-client{font-size:0.75rem;color:#6c757d;margin-top:2px}
.proj-time-badge{background:#EEEDFE;color:#3b3a8f;font-size:0.75rem;font-weight:700;padding:3px 10px;border-radius:12px;white-space:nowrap;flex-shrink:0}
.proj-hrs-note{font-size:0.7rem;color:#9090a8;margin-top:2px}
.panel-empty{text-align:center;padding:32px 20px;color:#adb5bd;font-size:0.85rem}
.panel-empty i{font-size:2rem;display:block;margin-bottom:8px}
.panel-section-label{font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#9090a8;margin-bottom:10px}
.panel-leave-notice{background:#FDECEC;border:1px solid #f1948a;border-radius:8px;padding:16px;text-align:center;color:#922b21;font-size:0.88rem;font-weight:600}
.panel-leave-notice i{font-size:1.4rem;display:block;margin-bottom:6px}
.panel-roster-notice{background:#f2f3f4;border:1px solid #aab7b8;border-radius:8px;padding:16px;text-align:center;color:#5d6d7e;font-size:0.88rem;font-weight:600}
.panel-roster-notice i{font-size:1.4rem;display:block;margin-bottom:6px}
.panel-holiday-notice{background:#FEE2E2;border:1px solid #FECACA;border-radius:8px;padding:16px;text-align:center;color:#7F1D1D;font-size:0.88rem;font-weight:600}
.panel-holiday-notice i{font-size:1.4rem;display:block;margin-bottom:6px}
.panel-tour-notice{background:#DBEAFE;border:1px solid #BFDBFE;border-radius:8px;padding:16px;text-align:center;color:#1E3A8A;font-size:0.88rem;font-weight:600}
.panel-tour-notice i{font-size:1.4rem;display:block;margin-bottom:6px}
.timeline-wrap{background:#f0f0fb;border-radius:8px;padding:10px 14px;margin-bottom:14px}
.timeline-label{font-size:0.72rem;font-weight:700;color:#4b49ac;margin-bottom:6px}
.timeline-bar{position:relative;height:20px;background:#e0dfff;border-radius:10px;overflow:visible;margin-bottom:4px}
.timeline-slot{position:absolute;height:100%;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:0.62rem;font-weight:700;color:#fff;overflow:hidden;white-space:nowrap}
.timeline-ticks{display:flex;justify-content:space-between;font-size:0.6rem;color:#9090a8;margin-top:2px}
.panel-past-notice{background:#e7f3ff;border:1px solid #90c9f9;border-radius:8px;padding:12px 14px;margin-top:14px;font-size:0.8rem;color:#0066cc;text-align:center}
.panel-assign-box{background:#EEEDFE;border:1px dashed #AFA9EC;border-radius:8px;padding:14px;margin-top:16px}
.panel-assign-box .form-label{font-size:0.78rem;font-weight:600;color:#495057;margin-bottom:3px}
.panel-assign-box .form-control{font-size:0.82rem;padding:6px 10px}
.panel-assign-title{font-size:0.82rem;font-weight:700;color:#3C3489;margin-bottom:12px;display:flex;align-items:center;gap:6px}
.btn-assign-panel{width:100%;background:#4b49ac;border:none;color:#fff;border-radius:6px;padding:8px;font-size:0.82rem;font-weight:600;cursor:pointer;margin-top:10px;transition:background .13s}
.btn-assign-panel:hover{background:#3b3a8f}
.btn-assign-panel:disabled{opacity:.65;cursor:not-allowed}
.time-conflict-warning{background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:8px 10px;font-size:0.75rem;color:#856404;margin-top:6px;display:none}
.time-conflict-warning.show{display:block}
.edit-form-box{background:#f8f8ff;border:1px solid #e0dfff;border-radius:8px;padding:12px 14px;margin-top:8px;display:none}
.edit-form-box.open{display:block}
.edit-form-box .form-label{font-size:0.76rem;font-weight:600;color:#495057;margin-bottom:3px}
.edit-form-box .form-control{font-size:0.8rem;padding:5px 9px}
.btn-edit-toggle{background:none;border:1px solid #c5c4e8;color:#4b49ac;border-radius:5px;padding:2px 9px;font-size:0.7rem;font-weight:600;cursor:pointer;white-space:nowrap}
.btn-edit-toggle:hover{background:#f0f0fb}
.btn-save-edit{background:#4b49ac;border:none;color:#fff;border-radius:5px;padding:5px 14px;font-size:0.78rem;font-weight:600;cursor:pointer}
.btn-save-edit:hover{background:#3b3a8f}
.btn-save-edit:disabled{opacity:.65;cursor:not-allowed}
.btn-cancel-edit{background:none;border:1px solid #dee2e6;color:#6c757d;border-radius:5px;padding:5px 12px;font-size:0.78rem;cursor:pointer}
.btn-delete-alloc{background:#dc3545;border:none;color:#fff;border-radius:5px;padding:5px 14px;font-size:0.78rem;font-weight:600;cursor:pointer;margin-left:4px}
.btn-delete-alloc:hover{background:#c82333}
.btn-delete-alloc:disabled{opacity:.65;cursor:not-allowed}
.role-badge-hr{display:inline-flex;align-items:center;gap:4px;background:#EEEDFE;color:#3C3489;border:1px solid #c5c4e8;border-radius:12px;padding:3px 10px;font-size:0.7rem;font-weight:700;margin-left:6px}
.holiday-header-tag{display:inline-block;font-size:0.52rem;background:rgba(254,202,202,.35);color:#fca5a5;border-radius:4px;padding:1px 5px;margin-top:2px;letter-spacing:.03em;font-weight:700;text-transform:uppercase;max-width:110px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
</style>
</head>
<body>
<div class="container-scroller">
    <?php include('../partials/_navbar.php'); ?>
    <div class="container-fluid page-body-wrapper">
        <?php include('../partials/_sidebar.php'); include('../partials/_settings-panel.php'); ?>
        <div class="main-panel">
        <div class="content-wrapper">

        <!-- Stat Cards -->
        <div class="row">
            <div class="col-md-3 mb-3">
                <div class="stat-card total">
                    <div class="stat-icon"><i class="fa fa-users"></i></div>
                    <div>
                        <div class="stat-number"><?php echo $totalInterns; ?></div>
                        <div class="stat-label">Active Interns</div>
                        <div class="stat-sub">⚡ <?php echo $capHrs; ?> hrs total capacity</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="stat-card allocated">
                    <div class="stat-icon"><i class="fa fa-briefcase"></i></div>
                    <div>
                        <div class="stat-number"><?php echo $allocatedCount; ?></div>
                        <div class="stat-label">Allocated Interns</div>
                        <div class="stat-sub" style="color:#3B6D11;">💼 <?php echo $allocHrs; ?> hrs allocated</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="stat-card free">
                    <div class="stat-icon"><i class="fa fa-clock-o"></i></div>
                    <div>
                        <div class="stat-number"><?php echo $freeCount; ?></div>
                        <div class="stat-label">Free / Available</div>
                        <div class="stat-sub" style="color:#854F0B;">🟢 <?php echo $freeHrs; ?> hrs available</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="stat-card" style="border-left-color:<?php echo $overCapCount > 0 ? '#dc3545' : '#17a2b8'; ?>;">
                    <div class="stat-icon" style="background:<?php echo $overCapCount > 0 ? '#fdecec' : '#eef1ff'; ?>;color:<?php echo $overCapCount > 0 ? '#dc3545' : '#17a2b8'; ?>;">
                        <i class="fa <?php echo $overCapCount > 0 ? 'fa-exclamation-triangle' : 'fa-check-square-o'; ?>"></i>
                    </div>
                    <div>
                        <div class="stat-number"><?php echo $overCapCount; ?></div>
                        <div class="stat-label"><?php echo $overCapCount > 0 ? 'Over-Allocated Interns' : 'Optimal Capacity'; ?></div>
                        <div class="stat-sub" style="color:<?php echo $overCapCount > 0 ? '#dc3545' : '#17a2b8'; ?>;">
                            <?php echo $overCapCount > 0 ? '🔴 ' . $overHrs . ' hrs over cap' : '✅ 0 hrs over cap'; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
        <div class="col-12 grid-margin stretch-card">
        <div class="card">
        <div class="card-body">

            <!-- Toolbar -->
            <div class="toolbar-row">
                <div class="toolbar-left">
                    <h4 class="card-title mb-0" style="white-space:nowrap;">
                        Weekly Plan Overview
                        <?php if ($canDirectAssign): ?>
                        <span class="role-badge-hr"><i class="fa fa-shield"></i> Resource Manager Access</span>
                        <?php else: ?>
                        <span class="badge badge-secondary ml-2" style="font-size:0.7rem;vertical-align:middle;"><i class="fa fa-eye me-1"></i> Read-Only View</span>
                        <?php endif; ?>
                    </h4>
                </div>
                <div class="toolbar-right">
                    <div class="week-nav-wrap">
                        <a href="?w=<?php echo $weekOffset-1; ?>&branch=<?php echo urlencode($branchFilter); ?>&mode=<?php echo urlencode($modeFilter); ?>&batch=<?php echo urlencode($batchFilter); ?>&project=<?php echo urlencode($projectFilter); ?>&avail_status=<?php echo urlencode($availStatusFilter); ?>&date=<?php echo urlencode($dateFilter); ?>" class="btn-wk" title="Previous week"><i class="fa fa-chevron-left"></i></a>
                        <span class="week-range-lbl" onclick="openWkPicker()" title="Click to jump to a week"><?php echo $weekLabel; ?></span>
                        <?php if ($weekOffset !== 0): ?>
                        <a href="?w=0&branch=<?php echo urlencode($branchFilter); ?>&mode=<?php echo urlencode($modeFilter); ?>&batch=<?php echo urlencode($batchFilter); ?>&project=<?php echo urlencode($projectFilter); ?>&avail_status=<?php echo urlencode($availStatusFilter); ?>&date=<?php echo urlencode($dateFilter); ?>" class="btn-wk-today">Today</a>
                        <?php else: ?>
                        <span style="width:44px;display:inline-block;"></span>
                        <?php endif; ?>
                        <a href="?w=<?php echo $weekOffset+1; ?>&branch=<?php echo urlencode($branchFilter); ?>&mode=<?php echo urlencode($modeFilter); ?>&batch=<?php echo urlencode($batchFilter); ?>&project=<?php echo urlencode($projectFilter); ?>&avail_status=<?php echo urlencode($availStatusFilter); ?>&date=<?php echo urlencode($dateFilter); ?>" class="btn-wk" title="Next week"><i class="fa fa-chevron-right"></i></a>
                    </div>

                    <?php if (in_array($userRole, ['hr', 'resourceallocator', 'admin', 'superadmin'])): ?>
                    <a href="resourceApprovals.php" class="btn btn-sm btn-outline-primary" style="font-weight:700;border-radius:6px;padding:5px 12px;font-size:0.78rem;" title="Go to Resource Approvals Dashboard">
                        <i class="fa fa-shield me-1"></i> Resource Approvals Hub
                    </a>
                    <div class="dl-wrap">
                        <div class="dl-split">
                            <button class="dl-main" onclick="doDownload('excel')"><i class="fa fa-download"></i> Download</button>
                            <div class="dl-div"></div>
                            <button class="dl-caret" onclick="toggleDlMenu(event)"><i class="fa fa-caret-down"></i></button>
                        </div>
                        <div class="dl-menu" id="dlMenu">
                            <a href="#" onclick="doDownload('excel');return false;">📊&nbsp; Excel (.xlsx)</a>
                            <a href="#" onclick="doDownload('pdf');return false;">📄&nbsp; PDF</a>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Filter Bar -->
            <div class="filter-bar">
                <div class="filter-group">
                    <span class="filter-bar-label">Branch:</span>
                    <select class="filter-select" id="branchSelect" onchange="applyFilters()">
                        <option value="" <?php echo $branchFilter==='' ? 'selected':''; ?>>All Branches</option>
                        <?php foreach ($branches as $b): ?>
                        <option value="<?php echo htmlspecialchars($b); ?>" <?php echo $branchFilter===$b ? 'selected':''; ?>><?php echo htmlspecialchars($b); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <span class="filter-bar-label">Mode:</span>
                    <select class="filter-select" id="modeSelect" onchange="applyFilters()">
                        <option value="" <?php echo $modeFilter==='' ? 'selected':''; ?>>All Modes</option>
                        <option value="online"  <?php echo $modeFilter==='online'  ? 'selected':''; ?>>Online</option>
                        <option value="offline" <?php echo $modeFilter==='offline' ? 'selected':''; ?>>Offline</option>
                    </select>
                </div>

                <div class="filter-group">
                    <span class="filter-bar-label">Batch:</span>
                    <select class="filter-select" id="batchSelect" onchange="applyFilters()">
                        <option value="" <?php echo $batchFilter==='' ? 'selected':''; ?>>All Batches</option>
                        <?php foreach ($batches as $b): ?>
                        <option value="<?php echo htmlspecialchars($b); ?>" <?php echo $batchFilter===$b ? 'selected':''; ?>><?php echo htmlspecialchars($b); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <span class="filter-bar-label">Project:</span>
                    <select class="filter-select" id="projectSelect" onchange="applyFilters()" style="min-width:160px;">
                        <option value="" <?php echo $projectFilter==='' ? 'selected':''; ?>>All Projects</option>
                        <?php foreach ($projectList as $p): 
                            $pName = $p['projectName'];
                            $pClient = trim($p['client'] ?? '');
                            $pLabel = (!empty($pClient) && strtoupper($pClient) !== 'NA') ? ($pName . ' - ' . $pClient) : $pName;
                        ?>
                        <option value="<?php echo htmlspecialchars($pName); ?>" <?php echo $projectFilter===$pName ? 'selected':''; ?>>
                            <?php echo htmlspecialchars($pLabel); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <span class="filter-bar-label">Availability:</span>
                    <select class="filter-select" id="availStatusSelect" onchange="applyFilters()">
                        <option value="" <?php echo $availStatusFilter==='' ? 'selected':''; ?>>All Availability</option>
                        <option value="free" <?php echo $availStatusFilter==='free' ? 'selected':''; ?>>🟢 Free / Available</option>
                        <option value="partial" <?php echo $availStatusFilter==='partial' ? 'selected':''; ?>>🔵 Partially Allocated</option>
                        <option value="full" <?php echo $availStatusFilter==='full' ? 'selected':''; ?>>🟢 Fully Allocated</option>
                        <option value="overcap" <?php echo $availStatusFilter==='overcap' ? 'selected':''; ?>>🔴 Over Capacity</option>
                        <option value="leave" <?php echo $availStatusFilter==='leave' ? 'selected':''; ?>>🏖️ On Leave</option>
                        <option value="tour" <?php echo $availStatusFilter==='tour' ? 'selected':''; ?>>✈️ On Tour</option>
                        <option value="roster" <?php echo $availStatusFilter==='roster' ? 'selected':''; ?>>🔒 Roster Off</option>
                        <option value="holiday" <?php echo $availStatusFilter==='holiday' ? 'selected':''; ?>>🎉 Holiday</option>
                    </select>
                </div>

                <?php if ($branchFilter !== '' || $modeFilter !== '' || $batchFilter !== '' || $projectFilter !== '' || $availStatusFilter !== ''): ?>
                <button class="btn-clear-filters" onclick="clearAllFilters()">✕ Clear Filters</button>
                <?php endif; ?>
            </div>

            <!-- Interactive Legend Filter Bar -->
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3 p-2 px-3" style="background:#fff;border:1px solid #e0e4ef;border-radius:10px;font-size:0.76rem;color:#555;">
                <span class="font-weight-bold text-muted"><i class="fa fa-filter me-1" style="color:#4b49ac;"></i> Click Legend to Filter:</span>
                
                <span class="legend-btn active-legend" data-status="all" onclick="filterByLegend('all')">
                    All
                </span>
                <span class="legend-btn" data-status="free" onclick="filterByLegend('free')">
                    <span class="pill-dot dot-free"></span> Available
                </span>
                <span class="legend-btn" data-status="partial" onclick="filterByLegend('partial')">
                    <span class="pill-dot dot-partial"></span> Partial
                </span>
                <span class="legend-btn" data-status="full" onclick="filterByLegend('full')">
                    <span class="pill-dot dot-full"></span> Fully Allocated
                </span>
                <span class="legend-btn" data-status="overcap" onclick="filterByLegend('overcap')">
                    <span class="pill-dot dot-over"></span> Over Capacity
                </span>
                <span class="legend-btn" data-status="leave" onclick="filterByLegend('leave')">
                    <span class="pill-dot dot-leave"></span> On Leave
                </span>
                <span class="legend-btn" data-status="tour" onclick="filterByLegend('tour')">
                    <span class="pill-dot dot-tour"></span> On Tour
                </span>
                <span class="legend-btn" data-status="roster" onclick="filterByLegend('roster')">
                    <span class="pill-dot dot-roster"></span> Roster Off
                </span>
                <span class="legend-btn" data-status="holiday" onclick="filterByLegend('holiday')">
                    <span class="pill-dot dot-holiday"></span> Holiday
                </span>
            </div>

            <?php if ($singleDateMode): ?>
            <div class="date-focus-notice">
                <span>📅 Showing data for: <strong><?php echo date('l, d M Y', strtotime($dateFilter)); ?></strong></span>
                <button class="btn-clear-date" onclick="clearDateFilter()">✕ Clear Date Filter</button>
            </div>
            <?php endif; ?>

            <div class="dt-info-bar">
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    <div class="dt-search-wrap">
                        <i class="fa fa-search"></i>
                        <input type="text" id="dtCustomSearch" placeholder="Search intern..." oninput="filterTable(this.value)">
                    </div>
                    <span id="dtRowCount">Showing <strong><?php echo count($interns); ?></strong> interns</span>
                </div>
            </div>

            <!-- Weekly Table Grid -->
            <div class="weekly-table-wrap">
            <table class="table" id="weeklyTable">
                <thead>
                    <tr>
                        <th>Intern</th>
                        <?php foreach ($weekDays as $day):
                            $isToday     = ($day['value'] === $todayDate);
                            $isPastDay   = ($day['value'] < $todayDate);
                            $isSat       = (date('N', strtotime($day['value'])) == 6);
                            $isHol       = isset($holidayByDay[$day['value']]);
                            $holName     = $isHol ? ($holidayByDay[$day['value']]['title'] ?? 'Holiday') : '';
                            $isDateFocus = ($singleDateMode && $day['value'] === $dateFilter);

                            $cls = 'th-future';
                            if ($isDateFocus)  $cls = 'th-date-focus';
                            elseif ($isHol)    $cls = 'th-holiday';
                            elseif ($isToday)  $cls = 'th-today';
                            elseif ($isPastDay)$cls = 'th-past';
                            elseif ($isSat)    $cls = 'th-saturday';
                        ?>
                        <th class="<?php echo $cls; ?>" data-date="<?php echo $day['value']; ?>">
                            <div><?php echo $day['label']; ?></div>
                            <div style="font-size:0.7rem;font-weight:400;opacity:0.9;"><?php echo $day['date']; ?></div>
                            <?php if ($isHol): ?>
                            <div class="holiday-header-tag" title="<?php echo htmlspecialchars($holName); ?>">🎉 <?php echo htmlspecialchars($holName); ?></div>
                            <?php endif; ?>
                        </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($interns)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-5 text-muted">
                            <i class="fa fa-search fa-2x mb-2 d-block text-muted"></i>
                            No interns found matching selected filters.
                        </td>
                    </tr>
                <?php else: foreach ($interns as $iid => $intern):
                    $name_s  = addslashes($intern['fullName']);
                    $iid_s   = addslashes($iid);
                    $wSt     = $intern['workStartTime'] ?: '09:00';
                    $wEt     = $intern['workEndTime']   ?: '17:00';
                    $hasAnyWeek = false;
                    
                    $stKeys = [];
                    $isJr   = $intern['isJuniorIntern'] ?? false;
                    foreach ($weekDays as $day) {
                        $dVal = $day['value'];
                        if (!empty($byDay[$iid][$dVal])) $hasAnyWeek = true;

                        $dow          = (int)date('N', strtotime($dVal));
                        $isJrWeekend  = ($isJr && ($dow === 6 || $dow === 7));

                        $onLeave      = !empty($leaveByDay[$iid][$dVal]);
                        $rosterOff    = !empty($rosterOffByDay[$iid][$dVal]);
                        $onTour       = !empty($tourByDay[$iid][$dVal]);
                        $projectAllocs= $byDay[$iid][$dVal] ?? [];
                        $isHolDay     = isset($holidayByDay[$dVal]) || ($isJrWeekend && empty($projectAllocs) && !$onLeave && !$onTour && !$rosterOff);

                        if ($isHolDay)                          $stKeys['holiday'] = true;
                        elseif ($onLeave)                       $stKeys['leave']   = true;
                        elseif ($onTour)                        $stKeys['tour']    = true;
                        elseif ($rosterOff)                     $stKeys['roster']  = true;
                        else {
                            $lunchItems = $lunchByDay[$iid][$dVal] ?? [];
                            $allocs     = array_merge($projectAllocs, $lunchItems);
                            $usedMins   = calcDayUsedMins($projectAllocs, $lunchItems);
                            $totalMins  = $isJrWeekend ? 0 : $intern['totalMinutes'];
                            $freeMins   = max(0, $totalMins - $usedMins);
                            if ($totalMins > 0 && $usedMins > $totalMins) $stKeys['overcap'] = true;
                            elseif (empty($allocs))                       $stKeys['free']    = true;
                            elseif ($freeMins > 0)                        $stKeys['partial'] = true;
                            else                                          $stKeys['full']    = true;
                        }
                    }
                    $statusAttr = implode(' ', array_keys($stKeys));
                ?>
                    <tr data-statuses="<?php echo $statusAttr; ?>">
                        <td style="width:270px;min-width:270px;max-width:270px;padding:10px 12px !important;">
                            <div style="display:flex;align-items:flex-start;gap:10px;min-width:0;width:100%;">
                                <div style="width:32px;height:32px;border-radius:50%;background:<?php echo $hasAnyWeek?'#EEEDFE':'#FAEEDA'; ?>;display:flex;align-items:center;justify-content:center;font-size:0.7rem;font-weight:700;color:<?php echo $hasAnyWeek?'#3C3489':'#633806'; ?>;flex-shrink:0;margin-top:2px;">
                                    <?php echo strtoupper(substr($intern['fullName'],0,1)); ?>
                                </div>
                                <div style="min-width:0;flex:1;overflow:hidden;">
                                    <div class="intern-name"><?php echo htmlspecialchars($intern['fullName']); ?></div>
                                    <div class="intern-meta">
                                        <?php echo htmlspecialchars($intern['branch']); ?>
                                        <?php if ($intern['mode']): ?> · <?php echo ucfirst($intern['mode']); ?><?php endif; ?>
                                        <?php if ($intern['batch']): ?> · <?php echo htmlspecialchars($intern['batch']); ?><?php endif; ?>
                                    </div>
                                    <div class="intern-hours">⏰ <?php echo date('h:i A', strtotime($wSt)) . ' – ' . date('h:i A', strtotime($wEt)) . ' (' . fmtHours($intern['totalMinutes']) . ')'; ?></div>
                                </div>
                            </div>
                        </td>
                        <?php foreach ($weekDays as $day):
                            $isJr         = $intern['isJuniorIntern'] ?? false;
                            $dow          = (int)date('N', strtotime($day['value']));
                            $isJrWeekend  = ($isJr && ($dow === 6 || $dow === 7));

                            $projectAllocs = $byDay[$iid][$day['value']] ?? [];
                            $lunchItems   = $lunchByDay[$iid][$day['value']] ?? [];
                            $allocs       = array_merge($projectAllocs, $lunchItems);
                            $usedMins     = calcDayUsedMins($projectAllocs, $lunchItems);

                            $onLeave      = !empty($leaveByDay[$iid][$day['value']]);
                            $rosterOff    = !empty($rosterOffByDay[$iid][$day['value']]);
                            $onTour       = !empty($tourByDay[$iid][$day['value']]);
                            $isHolDay     = isset($holidayByDay[$day['value']]) || ($isJrWeekend && empty($projectAllocs) && !$onLeave && !$onTour && !$rosterOff);
                            $holTitle     = $isHolDay ? ($holidayByDay[$day['value']]['title'] ?? 'Holiday') : '';

                            $totalMins    = $isJrWeekend ? 0 : $intern['totalMinutes'];
                            $freeMins     = max(0, $totalMins - $usedMins);

                            $isToday      = ($day['value'] === $todayDate);
                            $isPastDay    = ($day['value'] < $todayDate);
                            $isSat        = ($dow === 6);
                            $dateVal      = $day['value'];
                            $isDateFocus  = ($singleDateMode && $dateVal === $dateFilter);

                            $cellClass  = 'day-cell';
                            if ($isDateFocus)    $cellClass .= ' day-cell-date-focus';
                            elseif ($isHolDay)   $cellClass .= ' day-cell-holiday';
                            elseif ($isToday)    $cellClass .= ' day-cell-today';
                            elseif ($isPastDay)  $cellClass .= ' day-cell-past';
                            elseif ($isSat)      $cellClass .= ' day-cell-saturday';
                        ?>
                        <td class="<?php echo $cellClass; ?>"
                            data-intern="<?php echo $iid_s; ?>"
                            data-date="<?php echo $dateVal; ?>">
                            <?php echo renderPill($allocs,$usedMins,$freeMins,$totalMins,$isPastDay,$name_s,$iid_s,$dateVal,$onLeave,$rosterOff,$onTour,$isHolDay,$holTitle); ?>
                        </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
            </div>

        </div></div></div></div>
        </div></div></div></div>
</div>

<!-- JS Calendar Overlay, Panel & Code -->
<div class="wk-overlay" id="wkOverlay">
  <div class="wk-cal">
    <div class="wk-cal-head">
      <span class="wk-title"><i class="fa fa-calendar-o me-1"></i> Select a Week</span>
      <button class="wk-close" onclick="closeWkPicker()">&#x2715;</button>
    </div>
    <div class="wk-nav">
      <button class="wk-nav-btn" onclick="wkNavMonth(-1)"><i class="fa fa-chevron-left"></i></button>
      <span class="wk-month-lbl" id="wkMonthLbl" onclick="wkGoToday()">—</span>
      <button class="wk-nav-btn" onclick="wkNavMonth(1)"><i class="fa fa-chevron-right"></i></button>
    </div>
    <div class="wk-dow-row"><span></span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span><span>Su</span></div>
    <div class="wk-grid" id="wkGrid"></div>
    <div class="wk-footer">
      <button class="wk-today-btn" onclick="wkGoToday()">Today</button>
      <button class="wk-go" id="wkGoBtn" onclick="applyWkPicker()" disabled>Go to week</button>
      <button class="wk-cancel" onclick="closeWkPicker()">Cancel</button>
    </div>
  </div>
</div>

<div class="panel-backdrop" id="panelBackdrop" onclick="closePanel()"></div>
<div id="dayPanel">
    <div class="panel-header" id="p-header">
        <div class="panel-header-top">
            <div>
                <div class="panel-intern-name" id="p-name">—</div>
                <div class="panel-day-label" id="p-day">—</div>
            </div>
            <button class="panel-close" onclick="closePanel()">&#x2715;</button>
        </div>
        <div class="panel-hrs-bar-wrap" id="p-hrs-bar-wrap">
            <div class="panel-hrs-bar"><div class="panel-hrs-fill" id="p-bar" style="width:0%"></div></div>
            <span class="panel-hrs-label" id="p-hrs-label">0 / 0h</span>
        </div>
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:4px;">
            <span class="panel-free-badge" id="p-free-badge"></span>
            <span class="panel-work-window" id="p-work-window"></span>
        </div>
    </div>
    <div class="panel-body" id="p-body">
        <div class="panel-empty"><i class="fa fa-calendar-o"></i>Select a day cell to see details.</div>
    </div>
</div>

<script src="../vendors/js/vendor.bundle.base.js"></script>
<script src="../js/off-canvas.js"></script>
<script src="../js/hoverable-collapse.js"></script>
<script src="../js/template.js"></script>
<script src="../js/settings.js"></script>
<script src="../js/todolist.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
const SCHEDULE       = <?php echo json_encode($scheduleJson,  JSON_UNESCAPED_UNICODE); ?>;
const WEEK_DAYS      = <?php echo json_encode($weekDays,      JSON_UNESCAPED_UNICODE); ?>;
const HOLIDAY_BY_DAY = <?php echo json_encode($holidayByDay,  JSON_UNESCAPED_UNICODE); ?>;
const PROJECT_LIST   = <?php echo json_encode($projectList,   JSON_UNESCAPED_UNICODE); ?>;
const USER_ID        = "<?php echo htmlspecialchars($userId); ?>";
const TODAY          = "<?php echo $todayDate; ?>";
const WEEK_OFFSET    = <?php echo $weekOffset; ?>;
const BRANCH_FILTER  = "<?php echo addslashes($branchFilter); ?>";
const MODE_FILTER    = "<?php echo addslashes($modeFilter); ?>";
const BATCH_FILTER   = "<?php echo addslashes($batchFilter); ?>";
const DATE_FILTER    = "<?php echo addslashes($dateFilter); ?>";
const PROJECT_FILTER = "<?php echo addslashes($projectFilter); ?>";
const AVAIL_STATUS_FILTER = "<?php echo addslashes($availStatusFilter); ?>";
const CAN_DIRECT_ASSIGN = <?php echo $canDirectAssign ? 'true' : 'false'; ?>;
const DOT_COLORS = ['#4b49ac','#3B6D11','#854F0B','#E24B4A','#0c5460'];

let _internId = null, _dateVal = null, dtTable = null;

function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function isPast(d)     { return d < TODAY; }
function fmtMins(m)    { if (m<=0) return '0h'; var h=Math.floor(m/60),mn=m%60; return mn===0?h+'h':h+'h '+mn+'m'; }
function timeToMins(t) { if (!t) return 0; var p=t.split(':'); return parseInt(p[0])*60+parseInt(p[1]); }
function minsToTime(m) { var h=Math.floor(m/60),mn=m%60; return String(h).padStart(2,'0')+':'+String(mn).padStart(2,'0'); }
function formatTime12Hour(t) {
    if (!t) return '';
    var parts = t.split(':');
    var h = parseInt(parts[0], 10);
    var m = parts[1] ? parts[1].substring(0,2) : '00';
    if (isNaN(h)) return t;
    var ampm = h >= 12 ? 'PM' : 'AM';
    h = h % 12;
    if (h === 0) h = 12;
    var hStr = String(h).padStart(2, '0');
    return hStr + ':' + m + ' ' + ampm;
}
function minsToTime12(m) {
    var h = Math.floor(m / 60);
    var mn = m % 60;
    var ampm = h >= 12 ? 'PM' : 'AM';
    h = h % 12;
    if (h === 0) h = 12;
    return String(h).padStart(2, '0') + ':' + String(mn).padStart(2, '0') + ' ' + ampm;
}

var _activeLegend = AVAIL_STATUS_FILTER || '';

$.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
    if (settings.nTable.id !== 'weeklyTable') return true;
    if (!_activeLegend || _activeLegend === 'all') return true;

    var targetPillClass = 'pill-' + (_activeLegend === 'overcap' ? 'over' : _activeLegend);

    // 1. Check data-statuses attribute from row node
    var rowData = settings.aoData[dataIndex];
    var node = rowData ? rowData.nTr : null;
    if (node) {
        var statuses = node.getAttribute('data-statuses') || '';
        if (statuses) {
            var list = statuses.trim().split(/\s+/);
            if (list.indexOf(_activeLegend) !== -1) return true;
        }
    }

    // 2. Fallback: check cell HTML content in row data array
    var rowHtml = data.join(' ');
    return rowHtml.indexOf(targetPillClass) !== -1;
});

$(document).ready(function() {
    dtTable = $('#weeklyTable').DataTable({
        stateSave: false,
        paging:true, pageLength:15, lengthMenu:[[10,15,25,50,-1],[10,15,25,50,'All']],
        ordering:true, order:[[0,'asc']], searching:true, info:true, responsive:false, autoWidth:false,
        columnDefs:[
            {orderable:true,  targets:0},
            {orderable:false, targets:[1,2,3,4,5,6]},
            {className:'dt-center', targets:[1,2,3,4,5,6]}
        ],
        language:{
            info:'Showing _START_ to _END_ of _TOTAL_ interns',
            infoEmpty:'No interns found', infoFiltered:'(filtered from _MAX_ total)',
            lengthMenu:'Show _MENU_ per page',
            paginate:{previous:'<i class="fa fa-chevron-left" style="font-size:.7rem;"></i>',
                      next:'<i class="fa fa-chevron-right" style="font-size:.7rem;"></i>'}
        },
        initComplete: function() {
            if (_activeLegend && dtTable) {
                dtTable.draw();
            }
        },
        drawCallback: function() {
            var info = this.api().page.info();
            $('#dtRowCount').html('Showing <strong>'+info.recordsDisplay+'</strong> interns');

            if (_activeLegend) {
                var targetPillClass = '';
                if (_activeLegend === 'free')         targetPillClass = 'pill-free';
                else if (_activeLegend === 'partial') targetPillClass = 'pill-partial';
                else if (_activeLegend === 'full')    targetPillClass = 'pill-full';
                else if (_activeLegend === 'overcap') targetPillClass = 'pill-over';
                else if (_activeLegend === 'leave')   targetPillClass = 'pill-leave';
                else if (_activeLegend === 'tour')    targetPillClass = 'pill-tour';
                else if (_activeLegend === 'roster')  targetPillClass = 'pill-roster';
                else if (_activeLegend === 'holiday') targetPillClass = 'pill-holiday';

                $('#weeklyTable tbody tr').each(function() {
                    $(this).find('.cell-pill').each(function() {
                        if ($(this).hasClass(targetPillClass)) {
                            $(this).css('opacity', '1.0').css('filter', 'drop-shadow(0 0 5px rgba(75,73,172,0.45))');
                        } else {
                            $(this).css('opacity', '0.2').css('filter', 'grayscale(80%)');
                        }
                    });
                });
            } else {
                $('#weeklyTable tbody .cell-pill').css('opacity', '1.0').css('filter', 'none');
            }
        }
    });

    var savedScroll = sessionStorage.getItem('wpoScrollPos');
    if (savedScroll !== null) {
        sessionStorage.removeItem('wpoScrollPos');
        setTimeout(function() {
            window.scrollTo(0, parseInt(savedScroll, 10));
        }, 150);
    }

    if (_activeLegend) {
        $('.legend-btn').removeClass('active-legend');
        $('.legend-btn[data-status="' + _activeLegend + '"]').addClass('active-legend');
    }

    $('#projectSelect').select2({
        placeholder: 'All Projects',
        allowClear: true,
        width: '210px'
    }).on('change', function() {
        applyFilters();
    });

    if (DATE_FILTER) applyDateFocusStyle(DATE_FILTER);
});

function filterTable(val) { if (dtTable) dtTable.search(val).draw(); }

function buildFilterURL(overrides) {
    var params = {w:WEEK_OFFSET, branch:BRANCH_FILTER, mode:MODE_FILTER, batch:BATCH_FILTER, project:PROJECT_FILTER, avail_status:AVAIL_STATUS_FILTER, date:DATE_FILTER};
    Object.assign(params, overrides);
    var u = new URL(window.location.href);
    Object.keys(params).forEach(function(k){ u.searchParams.set(k, params[k]); });
    return u.toString();
}

function applyFilters() {
    var branch = document.getElementById('branchSelect').value;
    var mode   = document.getElementById('modeSelect').value;
    var batch  = document.getElementById('batchSelect').value;
    var proj   = document.getElementById('projectSelect').value;
    var avail  = document.getElementById('availStatusSelect').value;
    window.location.href = buildFilterURL({branch:branch, mode:mode, batch:batch, project:proj, avail_status:avail});
}

function filterByLegend(statusKey) {
    // Toggle off if clicking the active legend again, or if clicking 'all'
    if (statusKey === 'all' || _activeLegend === statusKey) {
        _activeLegend = '';
    } else {
        _activeLegend = statusKey;
    }

    // Update active legend button highlight
    $('.legend-btn').removeClass('active-legend');
    if (_activeLegend) {
        $('.legend-btn[data-status="' + _activeLegend + '"]').addClass('active-legend');
        $('#availStatusSelect').val(_activeLegend);
    } else {
        $('.legend-btn[data-status="all"]').addClass('active-legend');
        $('#availStatusSelect').val('');
    }

    // Re-draw the DataTable with the updated custom search
    if (dtTable) {
        dtTable.draw();
    }
}

function clearAllFilters() {
    window.location.href = buildFilterURL({branch:'', mode:'', batch:'', project:'', avail_status:''});
}

function applyDateFilter(val) {
    window.location.href = buildFilterURL({date: val || ''});
}

function clearDateFilter() {
    window.location.href = buildFilterURL({date:''});
}

function applyDateFocusStyle(focusDate) {
    document.querySelectorAll('#weeklyTable thead th[data-date]').forEach(function(th) {
        if (th.dataset.date !== focusDate) th.style.opacity = '0.45';
    });
    document.querySelectorAll('#weeklyTable tbody td[data-date]').forEach(function(td) {
        if (td.dataset.date !== focusDate) td.style.opacity = '0.3';
    });
}

function toggleDlMenu(e) { e.stopPropagation(); document.getElementById('dlMenu').classList.toggle('open'); }
document.addEventListener('click', function() { document.getElementById('dlMenu').classList.remove('open'); });
function doDownload(fmt) {
    document.getElementById('dlMenu').classList.remove('open');
    window.location.href = 'weeklyPlanDownload.php?format=' + fmt + '&w=' + WEEK_OFFSET
        + '&branch='      + encodeURIComponent(BRANCH_FILTER)
        + '&mode='        + encodeURIComponent(MODE_FILTER)
        + '&batch='       + encodeURIComponent(BATCH_FILTER)
        + '&project='     + encodeURIComponent(PROJECT_FILTER)
        + '&avail_status='+ encodeURIComponent(AVAIL_STATUS_FILTER)
        + '&date='        + encodeURIComponent(DATE_FILTER);
}

function closePanel() {
    document.getElementById('dayPanel').classList.remove('open');
    document.getElementById('panelBackdrop').classList.remove('open');
    _internId = null; _dateVal = null;
}

function buildTimeline(data, dayData, workStartMins, workEndMins) {
    var totalMins = workEndMins - workStartMins;
    if (totalMins <= 0) return '';
    var html = '<div class="timeline-wrap"><div class="timeline-label"><i class="fa fa-clock-o me-1"></i>Time Allocation — '
        + minsToTime12(workStartMins) + ' to ' + minsToTime12(workEndMins) + '</div>'
        + '<div class="timeline-bar">';
    var allocs = (dayData && dayData.allocs) ? dayData.allocs : [];
    allocs.forEach(function(a,i) {
        var am = timeToMins(a.startTime), bm = timeToMins(a.endTime);
        var left  = Math.max(0,((am - workStartMins)/totalMins)*100);
        var width = Math.min(100-left,((bm-am)/totalMins)*100);
        var col   = a.isLunch ? '#f59e0b' : DOT_COLORS[i % DOT_COLORS.length];
        var slotTitle = a.isLunch ? '🍱 Lunch Break ('+formatTime12Hour(a.startTime)+'–'+formatTime12Hour(a.endTime)+')' : esc(a.project)+' ('+formatTime12Hour(a.startTime)+'–'+formatTime12Hour(a.endTime)+')';
        var slotLabel = a.isLunch ? (width>8 ? '🍱 Lunch' : '') : (width>8 ? esc(a.project).substring(0,12) : '');
        html += '<div class="timeline-slot" style="left:'+left+'%;width:'+width+'%;background:'+col+';" title="'+slotTitle+'">'+slotLabel+' </div>';
    });
    html += '</div><div class="timeline-ticks"><span>'+minsToTime12(workStartMins)+'</span>';
    html += '<span>'+minsToTime12(workStartMins+Math.floor(totalMins/2))+'</span>';
    html += '<span>'+minsToTime12(workEndMins)+'</span></div></div>';
    return html;
}

function refreshTableCell(internId, dateVal) {
    var data      = SCHEDULE[internId];
    var dayData   = data ? data.days[dateVal] : null;
    var usedMins  = dayData ? dayData.usedMins  : 0;
    var allocs    = (dayData && dayData.allocs) ? dayData.allocs : [];

    var isJr      = data ? data.isJuniorIntern : false;
    var dObj      = new Date(dateVal + 'T00:00:00');
    var dow       = dObj.getDay(); // 0 = Sun, 6 = Sat
    var isJrWeekend = isJr && (dow === 0 || dow === 6);

    var totalMins = isJrWeekend ? 0 : (data ? data.totalMins : 0);
    var freeMins  = isJrWeekend ? 0 : (dayData ? dayData.freeMins : (data ? data.totalMins : 0));

    var onLeave   = dayData ? dayData.onLeave   : false;
    var rosterOff = dayData ? dayData.rosterOff : false;
    var onTour    = dayData ? dayData.onTour    : false;
    var isHoliday = (dayData && dayData.isHoliday) || (HOLIDAY_BY_DAY[dateVal] !== undefined) || (isJrWeekend && allocs.length === 0 && !onLeave && !onTour && !rosterOff);
    var holidayTitle = (dayData && dayData.holidayTitle) ? dayData.holidayTitle : (HOLIDAY_BY_DAY[dateVal] ? (HOLIDAY_BY_DAY[dateVal].title || 'Holiday') : 'Holiday');
    var over      = totalMins>0 && usedMins>totalMins;
    var pct       = totalMins>0 ? Math.min(100, Math.round(usedMins/totalMins*100)) : 0;
    var name      = data ? data.name : internId;
    var nameEsc   = esc(name).replace(/'/g, "&#39;");
    var pillClass, pillInner, extraBar, onclickStr;

    if (isHoliday) {
        var htLabel = holidayTitle||'Holiday';
        pillClass='pill-holiday'; pillInner='<span class="pill-dot dot-holiday"></span> 🎉 '+esc(htLabel); extraBar='';
        onclickStr='onclick="openPanel(\''+internId+'\',\''+nameEsc+'\',\''+dateVal+'\')"';
    } else if (onLeave) {
        pillClass='pill-leave'; pillInner='<span class="pill-dot dot-leave"></span> On Leave'; extraBar='';
        onclickStr='onclick="openPanel(\''+internId+'\',\''+nameEsc+'\',\''+dateVal+'\')"';
    } else if (onTour) {
        pillClass='pill-tour'; pillInner='<span class="pill-dot dot-tour"></span> ✈️ On Tour'; extraBar='';
        onclickStr='onclick="openPanel(\''+internId+'\',\''+nameEsc+'\',\''+dateVal+'\')"';
    } else if (rosterOff) {
        pillClass='pill-roster'; pillInner='<span class="pill-dot dot-roster"></span> Rostering Off'; extraBar='';
        onclickStr='onclick="openPanel(\''+internId+'\',\''+nameEsc+'\',\''+dateVal+'\')"';
    } else if (over) {
        pillClass='pill-over'; pillInner='<span class="pill-dot dot-over"></span> '+fmtMins(usedMins)+' · over cap';
        extraBar='<span class="pill-progress"><span class="pill-progress-fill" style="width:100%;background:#E24B4A;"></span></span>';
        onclickStr='onclick="openPanel(\''+internId+'\',\''+nameEsc+'\',\''+dateVal+'\')"';
    } else if (allocs.length===0) {
        pillClass='pill-free'; pillInner='<span class="pill-dot dot-free"></span> '+fmtMins(totalMins)+' available';
        extraBar=''; onclickStr='onclick="openPanel(\''+internId+'\',\''+nameEsc+'\',\''+dateVal+'\')"';
    } else if (freeMins>0) {
        pillClass='pill-partial'; pillInner='<span class="pill-dot dot-partial"></span> '+fmtMins(freeMins)+' free';
        extraBar='<span class="pill-progress"><span class="pill-progress-fill" style="width:'+pct+'%;background:#4b49ac;"></span></span>';
        onclickStr='onclick="openPanel(\''+internId+'\',\''+nameEsc+'\',\''+dateVal+'\')"';
    } else {
        pillClass='pill-full'; pillInner='<span class="pill-dot dot-full"></span> '+fmtMins(usedMins)+' · full';
        extraBar='<span class="pill-progress"><span class="pill-progress-fill" style="width:100%;background:#3B6D11;"></span></span>';
        onclickStr='onclick="openPanel(\''+internId+'\',\''+nameEsc+'\',\''+dateVal+'\')"';
    }

    var td = document.querySelector('td[data-intern="'+internId+'"][data-date="'+dateVal+'"]');
    if (td) td.innerHTML = '<div class="cell-pill '+pillClass+'" '+onclickStr+'><div class="pill-main">'+pillInner+'</div>'+extraBar+'</div>';
}

function refreshAllCellsForIntern(internId) {
    WEEK_DAYS.forEach(function(day) { if (!isPast(day.value)) refreshTableCell(internId, day.value); });
}

function openPanel(internId, internName, dateVal) {
    _internId = internId; _dateVal = dateVal;
    var data    = SCHEDULE[internId];
    var dayData = data ? data.days[dateVal] : null;
    var meta    = WEEK_DAYS.find(function(d){ return d.value===dateVal; });
    var dayLabel= meta ? meta.label+', '+meta.date : dateVal;
    var past    = isPast(dateVal);
    var onLeave   = dayData ? dayData.onLeave   : false;
    var rosterOff = dayData ? dayData.rosterOff : false;
    var onTour    = dayData ? dayData.onTour    : false;
    var isHoliday = dayData ? dayData.isHoliday : (HOLIDAY_BY_DAY[dateVal] !== undefined);
    var holidayTitle = dayData ? dayData.holidayTitle : (HOLIDAY_BY_DAY[dateVal] ? (HOLIDAY_BY_DAY[dateVal].title || 'Holiday') : 'Holiday');
    var leaveData   = dayData ? dayData.leaveData   : null;
    var rosterData  = dayData ? dayData.rosterData  : null;
    var tourData    = dayData ? dayData.tourData    : null;
    var holidayData = dayData ? dayData.holidayData : null;
    var workStartMins = data ? timeToMins(data.workStartTime) : 9*60;
    var workEndMins   = data ? timeToMins(data.workEndTime)   : 17*60;
    var totalMins     = data ? data.totalMins : 0;
    var usedMins = dayData ? dayData.usedMins : 0;
    var freeMins = dayData ? dayData.freeMins : totalMins;
    var over     = totalMins>0 && usedMins>totalMins;
    var pct      = totalMins>0 ? Math.min(100, Math.round(usedMins/totalMins*100)) : 0;
    var allocs   = (dayData && dayData.allocs) ? dayData.allocs : [];

    document.getElementById('p-name').textContent = internName;
    document.getElementById('p-day').textContent  = dayLabel + (past ? '  ·  Past Date' : '');

    var headerBg = past?'#6c757d':isHoliday?'#7F1D1D':onLeave?'#c0392b':onTour?'#1E3A8A':rosterOff?'#7f8c8d':'#4b49ac';
    document.getElementById('p-header').style.background = headerBg;
    document.getElementById('p-work-window').textContent = data ? '⏰ Working: '+formatTime12Hour(data.workStartTime)+' – '+formatTime12Hour(data.workEndTime)+' ('+fmtMins(totalMins)+')' : '';

    var hbw = document.getElementById('p-hrs-bar-wrap');
    if (isHoliday||onLeave||rosterOff||onTour) { hbw.style.display='none'; }
    else {
        hbw.style.display='flex';
        var bar = document.getElementById('p-bar');
        bar.style.width = pct+'%';
        bar.className   = 'panel-hrs-fill'+(over?' over':'');
        document.getElementById('p-hrs-label').textContent = fmtMins(usedMins)+' / '+fmtMins(totalMins)+' used';
    }

    var fb = document.getElementById('p-free-badge'); fb.removeAttribute('style');
    if (isHoliday)      { fb.textContent='🎉 '+(holidayTitle||'Holiday');       fb.style.background='rgba(127,29,29,.25)'; fb.style.borderColor='rgba(127,29,29,.4)'; }
    else if (onLeave)   { fb.textContent='🏖️ Approved Leave';                   fb.style.background='rgba(192,57,43,.25)'; fb.style.borderColor='rgba(192,57,43,.4)'; }
    else if (onTour)    { fb.textContent='✈️ On Approved Tour';                  fb.style.background='rgba(29,78,216,.2)';  fb.style.borderColor='rgba(29,78,216,.35)'; }
    else if (rosterOff) { fb.textContent='🔒 Rostering Off Day';                fb.style.background='rgba(127,140,141,.2)'; fb.style.borderColor='rgba(127,140,141,.4)'; }
    else if (past)      { fb.textContent='Past date';               fb.style.background='rgba(255,255,255,.15)'; fb.style.borderColor='rgba(255,255,255,.3)'; }
    else if (over)      { fb.textContent=fmtMins(usedMins-totalMins)+' over capacity'; fb.style.background='rgba(220,53,69,.25)'; fb.style.borderColor='rgba(220,53,69,.4)'; }
    else if (freeMins>0){ fb.textContent=fmtMins(freeMins)+' available'; }
    else                { fb.textContent='Fully assigned'; }

    var bodyEl = document.getElementById('p-body');
    bodyEl.innerHTML = '';

    if (isHoliday) {
        var hTitle = holidayData ? holidayData.title : (holidayTitle || 'Holiday');
        var hFrom  = holidayData ? holidayData.fromDate : dateVal;
        var hTo    = holidayData ? holidayData.toDate : dateVal;
        bodyEl.innerHTML = '<div style="background:#FFF5F5;border:1px solid #FECACA;border-radius:12px;padding:20px;margin-bottom:15px;box-shadow:0 2px 8px rgba(185,28,28,0.06);">'
            + '<div style="font-size:1.1rem;font-weight:700;color:#991B1B;margin-bottom:12px;display:flex;align-items:center;gap:8px;">'
            + '<i class="fa fa-calendar-check-o" style="font-size:1.3rem;"></i>🎉 ' + esc(hTitle) + '</div>'
            + '<div style="font-size:0.88rem;color:#7F1D1D;line-height:1.7;">'
            + '<div style="margin-bottom:6px;"><strong style="color:#991B1B;"><i class="fa fa-calendar me-1"></i> Date Range:</strong> ' + esc(hFrom) + ' to ' + esc(hTo) + '</div>'
            + '<div style="margin-top:10px;padding-top:10px;border-top:1px dashed #FCA5A5;color:#B91C1C;font-size:0.82rem;">'
            + '<i class="fa fa-info-circle me-1"></i> Company holiday. No work allocations scheduled for this date.</div></div></div>';
        _show(); return;
    }

    if (onLeave) {
        var lName = leaveData ? leaveData.leaveName : 'Approved Leave';
        var lType = (leaveData && leaveData.leaveType) ? ' (' + leaveData.leaveType + ')' : '';
        var lAppr = leaveData ? leaveData.approvedBy : 'Management / HR';
        var lReason = leaveData ? leaveData.reason : 'No reason specified';
        var lFrom = leaveData ? leaveData.fromDate : dateVal;
        var lTo   = leaveData ? leaveData.toDate : dateVal;

        bodyEl.innerHTML = '<div style="background:#FDF2F2;border:1px solid #F87171;border-radius:12px;padding:20px;margin-bottom:15px;box-shadow:0 2px 8px rgba(185,28,28,0.06);">'
            + '<div style="font-size:1.1rem;font-weight:700;color:#991B1B;margin-bottom:14px;display:flex;align-items:center;gap:8px;">'
            + '<i class="fa fa-plane" style="font-size:1.3rem;"></i>🏖️ ' + esc(lName + lType) + '</div>'
            + '<div style="font-size:0.88rem;color:#7F1D1D;line-height:1.8;">'
            + '<div style="margin-bottom:6px;"><strong style="color:#991B1B;"><i class="fa fa-user-check me-1"></i> Approved By:</strong> <span class="badge bg-white text-dark border px-2 py-1" style="font-size:0.82rem;">' + esc(lAppr) + '</span></div>'
            + '<div style="margin-bottom:6px;"><strong style="color:#991B1B;"><i class="fa fa-file-text-o me-1"></i> Leave Reason:</strong> ' + esc(lReason) + '</div>'
            + '<div><strong style="color:#991B1B;"><i class="fa fa-calendar me-1"></i> Leave Duration:</strong> ' + esc(lFrom) + ' to ' + esc(lTo) + '</div>'
            + '<div style="margin-top:12px;padding-top:10px;border-top:1px dashed #FCA5A5;color:#B91C1C;font-size:0.82rem;">'
            + '<i class="fa fa-info-circle me-1"></i> Person is on approved leave. Direct task assignments are disabled.</div></div></div>';
        _show(); return;
    }

    if (onTour) {
        var tProj   = tourData ? tourData.projectName : 'Official Tour';
        var tAppr   = tourData ? tourData.approvedBy : 'Management / HR';
        var tReason = tourData ? tourData.reason : 'Official Tour Work';
        var tFrom   = tourData ? tourData.fromDate : dateVal;
        var tTo     = tourData ? tourData.toDate : dateVal;

        bodyEl.innerHTML = '<div style="background:#EFF6FF;border:1px solid #60A5FA;border-radius:12px;padding:20px;margin-bottom:15px;box-shadow:0 2px 8px rgba(30,58,138,0.06);">'
            + '<div style="font-size:1.1rem;font-weight:700;color:#1E40AF;margin-bottom:14px;display:flex;align-items:center;gap:8px;">'
            + '<i class="fa fa-suitcase" style="font-size:1.3rem;"></i>✈️ Official Tour — ' + esc(tProj) + '</div>'
            + '<div style="font-size:0.88rem;color:#1E3A8A;line-height:1.8;">'
            + '<div style="margin-bottom:6px;"><strong style="color:#1E40AF;"><i class="fa fa-user-check me-1"></i> Approved By:</strong> <span class="badge bg-white text-primary border px-2 py-1" style="font-size:0.82rem;">' + esc(tAppr) + '</span></div>'
            + '<div style="margin-bottom:6px;"><strong style="color:#1E40AF;"><i class="fa fa-bullseye me-1"></i> Purpose / Details:</strong> ' + esc(tReason) + '</div>'
            + '<div><strong style="color:#1E40AF;"><i class="fa fa-calendar me-1"></i> Tour Duration:</strong> ' + esc(tFrom) + ' to ' + esc(tTo) + '</div>'
            + '<div style="margin-top:12px;padding-top:10px;border-top:1px dashed #93C5FD;color:#1D4ED8;font-size:0.82rem;">'
            + '<i class="fa fa-info-circle me-1"></i> Person is on approved tour work. Task assignments are disabled.</div></div></div>';
        _show(); return;
    }

    if (rosterOff) {
        var rAppr   = rosterData ? rosterData.approvedBy : 'Management / HR';
        var rReason = rosterData ? rosterData.reason : 'Rostering Off Day';
        var rFrom   = rosterData ? rosterData.fromDate : dateVal;
        var rTo     = rosterData ? rosterData.toDate : dateVal;

        bodyEl.innerHTML = '<div style="background:#F8FAFC;border:1px solid #94A3B8;border-radius:12px;padding:20px;margin-bottom:15px;box-shadow:0 2px 8px rgba(71,85,105,0.06);">'
            + '<div style="font-size:1.1rem;font-weight:700;color:#334155;margin-bottom:14px;display:flex;align-items:center;gap:8px;">'
            + '<i class="fa fa-moon-o" style="font-size:1.3rem;"></i>🔒 Rostering Off Day</div>'
            + '<div style="font-size:0.88rem;color:#475569;line-height:1.8;">'
            + '<div style="margin-bottom:6px;"><strong style="color:#334155;"><i class="fa fa-user-check me-1"></i> Approved By:</strong> <span class="badge bg-white text-secondary border px-2 py-1" style="font-size:0.82rem;">' + esc(rAppr) + '</span></div>'
            + '<div style="margin-bottom:6px;"><strong style="color:#334155;"><i class="fa fa-info-circle me-1"></i> Details:</strong> ' + esc(rReason) + '</div>'
            + '<div><strong style="color:#334155;"><i class="fa fa-calendar me-1"></i> Off Period:</strong> ' + esc(rFrom) + ' to ' + esc(rTo) + '</div>'
            + '<div style="margin-top:12px;padding-top:10px;border-top:1px dashed #CBD5E1;color:#64748B;font-size:0.82rem;">'
            + '<i class="fa fa-info-circle me-1"></i> Rostering Off Day. Task assignments are disabled.</div></div></div>';
        _show(); return;
    }

    if (data) bodyEl.innerHTML += buildTimeline(data, dayData, workStartMins, workEndMins);

    if (allocs.length > 0) {
        var lbl = document.createElement('div'); lbl.className = 'panel-section-label';
        lbl.textContent = 'Assignments on this day'; bodyEl.appendChild(lbl);
        allocs.forEach(function(a,i) {
            var allocMins = timeToMins(a.endTime) - timeToMins(a.startTime);
            var dotColor  = a.isLunch ? '#f59e0b' : DOT_COLORS[i%5];
            var rowInfoHtml = a.isLunch
                ? '<div class="proj-name">🍱 Lunch Break</div>'
                    + '<div class="proj-client"><span class="badge bg-warning text-dark me-1" style="font-size:0.68rem;">Lunch Break</span> (' + esc(a.status || 'Finished') + ')</div>'
                    + '<div class="proj-hrs-note">Calculated as Working Hours</div>'
                : '<div class="proj-name">'+esc(a.project)+'</div>'
                    + '<div class="proj-client">'+esc(a.client)+'</div>'
                    + '<div class="proj-hrs-note">'+esc(a.startDate)+' – '+esc(a.endDate)+'</div>';
            var actionBtnsHtml = (CAN_DIRECT_ASSIGN && !a.isLunch)
                ? '<div><button class="btn-edit-toggle" onclick="toggleEditForm('+i+','+a.id+')">&#9998; Edit</button>'
                    + '<button class="btn-delete-alloc" onclick="deleteAlloc('+a.id+')">🗑 Delete</button></div>'
                : '';

            var row = document.createElement('div'); row.className = 'proj-row';
            row.innerHTML = '<div class="proj-dot" style="background:'+dotColor+';"></div>'
                + '<div class="proj-info">' + rowInfoHtml + '</div>'
                + '<div style="display:flex;flex-direction:column;align-items:flex-end;gap:4px;">'
                + '<span class="proj-time-badge">'+formatTime12Hour(a.startTime)+' – '+formatTime12Hour(a.endTime)+'</span>'
                + '<span style="font-size:.7rem;color:#9090a8;">'+fmtMins(allocMins)+'</span>'
                + actionBtnsHtml
                + '</div>';
            bodyEl.appendChild(row);

            if (CAN_DIRECT_ASSIGN) {
                var editDefStartDate = dateVal || a.startDate;
                var editDefEndDate   = dateVal || a.endDate;

                var eb = document.createElement('div'); 
                eb.className = 'edit-form-box'; 
                eb.id = 'ef-'+i;
                eb.setAttribute('data-start-date', editDefStartDate);
                eb.setAttribute('data-end-date', editDefEndDate);
                
                eb.innerHTML = '<div class="row mb-2"><div class="col-12"><label class="form-label">Project Name</label>'
                    + '<select class="form-control js-ep-project" id="ep-'+i+'" required></select></div></div>'
                    + '<div class="row mb-2"><div class="col-6"><label class="form-label">Alloc Start Time <span style="color:red">*</span></label>'
                    + '<input type="time" class="form-control" id="est-'+i+'" value="'+a.startTime+'"></div>'
                    + '<div class="col-6"><label class="form-label">Alloc End Time <span style="color:red">*</span></label>'
                    + '<input type="time" class="form-control" id="eet-'+i+'" value="'+a.endTime+'"></div></div>'
                    + '<div class="row mb-2"><div class="col-6"><label class="form-label">From Date <span style="color:red">*</span></label>'
                    + '<input type="date" class="form-control" id="esdate-'+i+'" value="'+editDefStartDate+'"></div>'
                    + '<div class="col-6"><label class="form-label">To Date <span style="color:red">*</span></label>'
                    + '<input type="date" class="form-control" id="eedate-'+i+'" value="'+editDefEndDate+'"></div></div>'
                    + '<div class="time-conflict-warning" id="ecw-'+i+'"></div>'
                    + '<div style="display:flex;gap:8px;margin-top:8px;">'
                    + '<button class="btn-save-edit" onclick="saveEditAlloc('+i+','+a.id+')">Save</button>'
                    + '<button class="btn-cancel-edit" onclick="toggleEditForm('+i+')">Cancel</button></div>';
                bodyEl.appendChild(eb);

                setTimeout(function() {
                    $('#ep-'+i).select2({width:'100%', placeholder:'-- Select Project --', dropdownParent:$('#dayPanel')});
                    $('#ep-'+i).empty().append('<option value="" disabled>-- Select Project --</option>');
                    PROJECT_LIST.forEach(function(p) {
                        var label = (p.client && p.client.trim() !== '' && p.client.trim().toUpperCase() !== 'NA') ? p.projectName + ' - ' + p.client.trim() : p.projectName;
                        var sel = (p.projectName === a.project) ? 'selected' : '';
                        $('#ep-'+i).append($('<option '+sel+'></option>').val(p.projectName).text(label));
                    });
                }, 50);
            }
        });
    } else if (!CAN_DIRECT_ASSIGN) {
        var empBox = document.createElement('div'); empBox.className = 'panel-empty';
        empBox.style.cssText = 'text-align:center;padding:24px 16px;color:#64748b;';
        empBox.innerHTML = '<i class="fa fa-info-circle" style="font-size:1.8rem;color:#94a3b8;margin-bottom:8px;display:block;"></i>'
            + 'No project allocations on this day.'
            + '<div style="font-size:0.75rem;color:#94a3b8;margin-top:4px;">(View Only Mode)</div>';
        bodyEl.appendChild(empBox);
    }

    if (CAN_DIRECT_ASSIGN) {
        var ab = document.createElement('div'); ab.className = 'panel-assign-box';
        ab.innerHTML = '<div class="panel-assign-title"><i class="fa fa-plus-circle"></i> Direct Assign</div>'
            + '<div class="row mb-2"><div class="col-12"><label class="form-label">Project <span style="color:red">*</span></label>'
            + '<select class="form-control js-qa-project" id="qa-project" required></select></div></div>'
            + '<input type="hidden" id="qa-client">'
            + '<div class="row mb-2"><div class="col-6"><label class="form-label">Start Time <span style="color:red">*</span></label>'
            + '<input type="time" class="form-control" id="qa-start" value="'+(data?data.workStartTime:'09:00')+'"></div>'
            + '<div class="col-6"><label class="form-label">End Time <span style="color:red">*</span></label>'
            + '<input type="time" class="form-control" id="qa-end" value="'+(data?data.workEndTime:'17:00')+'"></div></div>'
            + '<div class="row mb-2"><div class="col-6"><label class="form-label">From Date <span style="color:red">*</span></label>'
            + '<input type="date" class="form-control" id="qa-sdate" value="'+dateVal+'"></div>'
            + '<div class="col-6"><label class="form-label">To Date <span style="color:red">*</span></label>'
            + '<input type="date" class="form-control" id="qa-edate" value="'+dateVal+'"></div></div>'
            + '<div class="time-conflict-warning" id="qa-warn"></div>'
            + '<button class="btn-assign-panel" id="qa-btn" onclick="submitQuickAssign()"><i class="fa fa-check me-1"></i> Assign Directly</button>';
        bodyEl.appendChild(ab);

        setTimeout(function() {
            $('#qa-project').select2({width:'100%', placeholder:'-- Select Project --', dropdownParent:$('#dayPanel')});
            $('#qa-project').empty().append('<option value="" disabled selected>-- Select Project --</option>');
            PROJECT_LIST.forEach(function(p) {
                var label = (p.client && p.client.trim() !== '' && p.client.trim().toUpperCase() !== 'NA') ? p.projectName + ' - ' + p.client.trim() : p.projectName;
                $('#qa-project').append($('<option></option>').val(p.projectName).attr('data-client', p.client || '').text(label));
            });
            $('#qa-project').on('change', function() {
                $('#qa-client').val($(this).find('option:selected').data('client') || '');
            });
        }, 100);
    } else {
        var voNotice = document.createElement('div');
        voNotice.style.cssText = 'background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px 14px;margin-top:14px;font-size:0.78rem;color:#64748b;text-align:center;display:flex;align-items:center;justify-content:center;gap:6px;';
        voNotice.innerHTML = '<i class="fa fa-lock" style="color:#94a3b8;"></i><span><strong>View Only Mode:</strong> Direct assignment and editing are restricted to Resource Managers.</span>';
        bodyEl.appendChild(voNotice);
    }

    if (past) {
        var pn = document.createElement('div'); pn.className = 'panel-past-notice';
        pn.innerHTML = CAN_DIRECT_ASSIGN
            ? '<i class="fa fa-info-circle me-1"></i> This is a past date. You can still add and edit assignments.'
            : '<i class="fa fa-info-circle me-1"></i> This is a past date (View Only mode).';
        bodyEl.appendChild(pn);
    }
    _show();
}

function _show() {
    document.getElementById('dayPanel').classList.add('open');
    document.getElementById('panelBackdrop').classList.add('open');
}

function toggleEditForm(i, allocId, startTime, endTime, projectName, startDate, endDate) {
    var box = document.getElementById('ef-'+i);
    if (box) {
        box.classList.toggle('open');
    }
}

function saveEditAlloc(i, allocId) {
    if (!CAN_DIRECT_ASSIGN) { alert('No permission.'); return; }
    
    var proj  = $('#ep-'+i).val() || document.getElementById('ep-'+i).value;
    var st    = (document.getElementById('est-'+i).value || '').substring(0,5);
    var et    = (document.getElementById('eet-'+i).value || '').substring(0,5);
    var sdate = (document.getElementById('esdate-'+i).value || '').trim();
    var edate = (document.getElementById('eedate-'+i).value || '').trim();
    var cwEl  = document.getElementById('ecw-'+i);
    var btn   = document.querySelector('#ef-'+i+' .btn-save-edit');
    var box   = document.getElementById('ef-'+i);
    
    cwEl.classList.remove('show'); 
    cwEl.textContent = '';
    
    if (!proj) { 
        cwEl.textContent='Project required.'; 
        cwEl.classList.add('show'); 
        return; 
    }
    if (!st||!et) { 
        cwEl.textContent='Both times required.'; 
        cwEl.classList.add('show'); 
        return; 
    }
    if (!sdate||!edate) { 
        cwEl.textContent='Both dates required.'; 
        cwEl.classList.add('show'); 
        return; 
    }
    if (edate < sdate) { 
        cwEl.textContent='End date cannot be before start date.'; 
        cwEl.classList.add('show'); 
        return; 
    }
    
    var sm=timeToMins(st), em=timeToMins(et);
    if (em<=sm) { 
        cwEl.textContent='End time must be after start.'; 
        cwEl.classList.add('show'); 
        return; 
    }
    
    btn.disabled=true; 
    btn.textContent='Saving…';
    
    var fd = new FormData();
    fd.append('allocationId', allocId); 
    fd.append('internId', _internId);
    fd.append('projectName', proj); 
    fd.append('allocStartTime', st); 
    fd.append('allocEndTime', et);
    fd.append('startDate', sdate);
    fd.append('endDate', edate);
    
    fetch('directEditProcess.php', {method:'POST', body:fd})
    .then(function(r){ return r.json().then(function(data){ return {ok:r.ok, data:data}; }); })
    .then(function(result) {
        var res = result.data;
        if (!result.ok) {
            cwEl.textContent = res.message || 'Update failed. Please try again.';
            cwEl.classList.add('show');
            btn.disabled = false; btn.textContent = 'Save';
            return;
        }
        if (res.status==='success') {
            var allocMins = em - sm;
            if (SCHEDULE[_internId]) {
                WEEK_DAYS.forEach(function(day) {
                    var dd = SCHEDULE[_internId].days[day.value];
                    if (dd && dd.allocs) {
                        var found = false;
                        dd.allocs.forEach(function(a) {
                            if (a.id == allocId) {
                                a.project   = proj;
                                a.startTime = st;
                                a.endTime   = et;
                                a.minutes   = allocMins;
                                a.startDate = sdate;
                                a.endDate   = edate;
                                found = true;
                            }
                        });

                        if (!found && day.value >= sdate && day.value <= edate) {
                            dd.allocs.push({id: allocId, project: proj, client: '', startTime: st, endTime: et, minutes: allocMins, startDate: sdate, endDate: edate});
                        } else if (found && (day.value < sdate || day.value > edate)) {
                            dd.allocs = dd.allocs.filter(function(a) { return a.id != allocId; });
                        }

                        dd.usedMins = calcJsDayUsedMins(dd.allocs);
                        dd.freeMins = Math.max(0, dd.totalMins - dd.usedMins);
                    }
                });
                refreshAllCellsForIntern(_internId);
            }
            openPanel(_internId, SCHEDULE[_internId].name, _dateVal);
        } else { 
            cwEl.textContent = res.message || 'Update failed.'; 
            cwEl.classList.add('show'); 
            btn.disabled = false; 
            btn.textContent = 'Save'; 
        }
    })
    .catch(function(err) {
        cwEl.textContent = 'Something went wrong. Please try again.';
        cwEl.classList.add('show');
        btn.disabled = false;
        btn.textContent = 'Save';
    });
}

function calcJsDayUsedMins(allocs) {
    if (!Array.isArray(allocs)) return 0;
    var projectMins = 0;
    var lunchItems = [];
    var projectAllocs = [];

    allocs.forEach(function(a) {
        if (a.isLunch || a.project === '🍱 Lunch Break') {
            lunchItems.push(a);
        } else {
            projectAllocs.push(a);
            projectMins += (parseInt(a.minutes) || 0);
        }
    });

    var nonOverlapLunchMins = 0;
    lunchItems.forEach(function(lb) {
        var lStart = timeToMins(lb.startTime);
        var lEnd   = timeToMins(lb.endTime);
        var lMins  = parseInt(lb.minutes) || 0;
        if (lMins <= 0 || lEnd <= lStart) return;

        var overlapMins = 0;
        projectAllocs.forEach(function(pa) {
            var pStart = timeToMins(pa.startTime);
            var pEnd   = timeToMins(pa.endTime);
            var maxStart = Math.max(lStart, pStart);
            var minEnd   = Math.min(lEnd, pEnd);
            if (maxStart < minEnd) {
                overlapMins += (minEnd - maxStart);
            }
        });

        var effectiveOverlap = Math.min(lMins, overlapMins);
        nonOverlapLunchMins += Math.max(0, lMins - effectiveOverlap);
    });

    return projectMins + nonOverlapLunchMins;
}

function deleteAlloc(allocId) {
    if (!CAN_DIRECT_ASSIGN) { alert('No permission.'); return; }
    if (!confirm('Are you sure you want to delete this allocation?')) return;
    var fd = new FormData();
    fd.append('allocationId', allocId);
    fd.append('internId', _internId);
    fd.append('action', 'delete');
    fetch('directEditProcess.php', {method:'POST', body:fd})
    .then(function(r){ if(!r.ok) throw new Error('Server '+r.status); return r.json(); })
    .then(function(res){
        if (res.status === 'success') {
            if (SCHEDULE[_internId]) {
                WEEK_DAYS.forEach(function(day){
                    var d2 = SCHEDULE[_internId].days[day.value];
                    if (d2 && d2.allocs) {
                        d2.allocs = d2.allocs.filter(function(a){ return a.id !== allocId; });
                        d2.usedMins = calcJsDayUsedMins(d2.allocs);
                        d2.freeMins = Math.max(0, SCHEDULE[_internId].totalMins - d2.usedMins);
                    }
                });
                refreshAllCellsForIntern(_internId);
            }
            openPanel(_internId, SCHEDULE[_internId].name, _dateVal);
        } else {
            alert('Delete failed: ' + (res.message || 'Unknown error'));
        }
    })
    .catch(function(err){ alert('Error: ' + err.message); });
}

function submitQuickAssign() {
    if (!CAN_DIRECT_ASSIGN) { alert('No permission.'); return; }
    var proj   = $('#qa-project').val();
    var client = (document.getElementById('qa-client').value || '').trim();
    var st     = (document.getElementById('qa-start').value || '').substring(0,5);
    var et     = (document.getElementById('qa-end').value || '').substring(0,5);
    var sd     = document.getElementById('qa-sdate').value;
    var ed     = document.getElementById('qa-edate').value;
    var warnEl = document.getElementById('qa-warn');
    var btn    = document.getElementById('qa-btn');
    warnEl.classList.remove('show'); warnEl.textContent='';
    if (!proj)    { warnEl.textContent='Project required.';        warnEl.classList.add('show'); return; }
    if (!st||!et) { warnEl.textContent='Both times required.';     warnEl.classList.add('show'); return; }
    if (!sd||!ed) { warnEl.textContent='Both dates required.';     warnEl.classList.add('show'); return; }
    if (ed<sd)    { warnEl.textContent='End date must be ≥ start.'; warnEl.classList.add('show'); return; }
    var sm=timeToMins(st), em=timeToMins(et);
    if (em<=sm)   { warnEl.textContent='End time must be after start.'; warnEl.classList.add('show'); return; }
    btn.disabled=true; btn.innerHTML='<i class="fa fa-spinner fa-spin me-1"></i> Assigning…';
    var fd=new FormData();
    fd.append('internId',_internId); fd.append('projectName',proj); fd.append('clientName',client);
    fd.append('allocStartTime',st); fd.append('allocEndTime',et);
    fd.append('startDate',sd); fd.append('endDate',ed);
    fd.append('assignedBy',USER_ID); fd.append('directAssign','1');
    fetch('directAssignProcess.php',{method:'POST',body:fd})
    .then(function(r){ return r.json().then(function(data){ return {ok:r.ok, data:data}; }); })
    .then(function(result){
        var res = result.data;
        if (!result.ok) {
            warnEl.textContent = res.message || 'Assignment failed. Please try again.';
            warnEl.classList.add('show');
            btn.disabled=false; btn.innerHTML='<i class="fa fa-check me-1"></i> Assign Directly';
            return;
        }
        if (res.status==='success') {
            var allocMins=em-sm;
            if (SCHEDULE[_internId]) {
                WEEK_DAYS.forEach(function(day){
                    if (day.value>=sd && day.value<=ed) {
                        var dd=SCHEDULE[_internId].days[day.value];
                        if (!dd) { dd={allocs:[],usedMins:0,freeMins:SCHEDULE[_internId].totalMins,totalMins:SCHEDULE[_internId].totalMins,onLeave:false,rosterOff:false,onTour:false,isHoliday:false,holidayTitle:''}; SCHEDULE[_internId].days[day.value]=dd; }
                        dd.allocs.push({id:res.id||0,project:proj,client:client,startTime:st,endTime:et,minutes:allocMins,startDate:sd,endDate:ed});
                        dd.usedMins = calcJsDayUsedMins(dd.allocs);
                        dd.freeMins = Math.max(0, dd.totalMins - dd.usedMins);
                    }
                });
                refreshAllCellsForIntern(_internId);
            }
            openPanel(_internId, SCHEDULE[_internId].name, _dateVal);
        } else { warnEl.textContent=res.message||'Assignment failed.'; warnEl.classList.add('show'); btn.disabled=false; btn.innerHTML='<i class="fa fa-check me-1"></i> Assign Directly'; }
    })
    .catch(function(err){warnEl.textContent='Something went wrong. Please try again.'; warnEl.classList.add('show'); btn.disabled=false; btn.innerHTML='<i class="fa fa-check me-1"></i> Assign Directly';});
}

var wkPickerDate = new Date(), wkSelectedOffset = null;

function openWkPicker()  { wkPickerDate=new Date(); wkSelectedOffset=null; document.getElementById('wkGoBtn').disabled=true; renderWkGrid(); document.getElementById('wkOverlay').classList.add('open'); }
function closeWkPicker() { document.getElementById('wkOverlay').classList.remove('open'); }
function wkGoToday()     { wkPickerDate=new Date(); renderWkGrid(); }
function wkNavMonth(d)   { wkPickerDate=new Date(wkPickerDate.getFullYear(), wkPickerDate.getMonth()+d, 1); renderWkGrid(); }

function renderWkGrid() {
    var year=wkPickerDate.getFullYear(), month=wkPickerDate.getMonth();
    document.getElementById('wkMonthLbl').textContent=new Date(year,month,1).toLocaleString('default',{month:'long',year:'numeric'});
    var grid=document.getElementById('wkGrid'); grid.innerHTML='';
    var firstDay=new Date(year,month,1), startDow=(firstDay.getDay()+6)%7;
    var realToday=new Date(); realToday.setHours(0,0,0,0);
    var realDow=(realToday.getDay()+6)%7;
    var realWeekMon=new Date(realToday); realWeekMon.setDate(realToday.getDate()-realDow);
    var cur=new Date(firstDay); cur.setDate(1-startDow);
    for (var w=0;w<6;w++) {
        var rowDate=new Date(cur), diffMs=rowDate-realWeekMon, diffWks=Math.round(diffMs/(7*86400000));
        var rowEl=document.createElement('div'); rowEl.className='wk-row';
        if (diffWks===WEEK_OFFSET) rowEl.classList.add('wk-selected');
        else if (diffWks===0) rowEl.classList.add('wk-current-row');
        var numEl=document.createElement('div'); numEl.className='wk-day wk-num'; numEl.textContent='W'+getISOWeek(rowDate);
        rowEl.appendChild(numEl);
        for (var d=0;d<7;d++) {
            var dayEl=document.createElement('div'); dayEl.className='wk-day';
            if (cur.getMonth()!==month) dayEl.classList.add('other-month');
            var cc=new Date(cur); cc.setHours(0,0,0,0);
            var rt=new Date(realToday); rt.setHours(0,0,0,0);
            if (cc.getTime()===rt.getTime()) dayEl.classList.add('today-cell');
            dayEl.textContent=cur.getDate(); rowEl.appendChild(dayEl); cur.setDate(cur.getDate()+1);
        }
        (function(offset){ rowEl.addEventListener('click',function(){ document.querySelectorAll('.wk-row').forEach(function(r){r.classList.remove('wk-selected');}); rowEl.classList.add('wk-selected'); wkSelectedOffset=offset; document.getElementById('wkGoBtn').disabled=false; }); })(diffWks);
        grid.appendChild(rowEl);
        var rowEnd=new Date(cur); rowEnd.setDate(rowEnd.getDate()-1);
        if (rowEnd.getMonth()>month && w>=3) break;
    }
}

function getISOWeek(d) {
    var date=new Date(Date.UTC(d.getFullYear(),d.getMonth(),d.getDate())), day=date.getUTCDay()||7;
    date.setUTCDate(date.getUTCDate()+4-day);
    var yearStart=new Date(Date.UTC(date.getUTCFullYear(),0,1));
    return Math.ceil((((date-yearStart)/86400000)+1)/7);
}

function applyWkPicker() {
    if (wkSelectedOffset===null) return;
    closeWkPicker();
    window.location.href = buildFilterURL({w: wkSelectedOffset});
}
</script>
</body>
</html>
