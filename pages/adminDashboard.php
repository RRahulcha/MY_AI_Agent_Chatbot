<?php
session_start();
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}
if ($_SESSION['userRole'] !== 'admin' && $_SESSION['userRole'] !== 'hr') {
    echo '<script>alert("You are not authorized to access this page."); window.location.href = "login.php";</script>';
    die();
}

include('../include/db_config.php');
require_once('availabilityEngine.php');
date_default_timezone_set('Asia/Kolkata');
$today = date('Y-m-d');
$todayMD = date('m-d');

// ── Birthdays ──
$todayBirthdays = [];
$sql = "SELECT fullName, mobileNo FROM signup WHERE DATE_FORMAT(dob,'%m-%d') = '$todayMD' AND status = 'Active'";
$result = mysqli_query($con, $sql);
if ($result && mysqli_num_rows($result) > 0) {
    while ($row = mysqli_fetch_assoc($result)) {
        $todayBirthdays[] = $row;
    }
}

// ── Session Filters (Type & Branch) ──
$intType = $_SESSION['int_type'] ?? null;
$intBranch = $_SESSION['int_branch'] ?? null;

$typeCondition = '';
if (!empty($intType) && $intType !== 'Reset' && $intType !== 'All') {
    $typeCondition = " AND s.type = '" . mysqli_real_escape_string($con, $intType) . "'";
}
$typeConditionPlain = '';
if (!empty($intType) && $intType !== 'Reset' && $intType !== 'All') {
    $typeConditionPlain = " AND type = '" . mysqli_real_escape_string($con, $intType) . "'";
}

$branchCondition = '';
if (!empty($intBranch) && $intBranch !== 'Reset' && $intBranch !== 'All') {
    $escBranch = mysqli_real_escape_string($con, $intBranch);
    $branchCondition = " AND (s.branch = '$escBranch' OR s.office = '$escBranch')";
}
$branchConditionPlain = '';
if (!empty($intBranch) && $intBranch !== 'Reset' && $intBranch !== 'All') {
    $escBranch = mysqli_real_escape_string($con, $intBranch);
    $branchConditionPlain = " AND (branch = '$escBranch' OR office = '$escBranch')";
}

// Dynamic Branches list for filter UI
$availableBranches = [];
$qBranch = mysqli_query($con, "SELECT DISTINCT branch FROM signup WHERE branch IS NOT NULL AND branch != '' AND status = 'Active' ORDER BY branch ASC");
if ($qBranch) {
    while ($bRow = mysqli_fetch_assoc($qBranch)) {
        $availableBranches[] = $bRow['branch'];
    }
}
if (empty($availableBranches)) {
    $availableBranches = ['Pune', 'Kinwat'];
}

// ══════════════════════════════════════════════════
// WORKFORCE STATS
// ══════════════════════════════════════════════════
$qApproved = mysqli_query($con, "SELECT COUNT(*) AS c FROM signup WHERE designation != 'admin' AND status = 'Active' $branchConditionPlain $typeConditionPlain");
$approved = (int) (mysqli_fetch_assoc($qApproved)['c'] ?? 0);

$qPending = mysqli_query($con, "SELECT COUNT(*) AS c FROM signup WHERE designation != 'admin' AND regstatus = 'Pending' $branchConditionPlain $typeConditionPlain");
$pending = (int) (mysqli_fetch_assoc($qPending)['c'] ?? 0);

// ── Present Today ──
$present = 0;
$presentUserIds = [];
$qPres = mysqli_query($con, "SELECT DISTINCT a.userId
    FROM attendance a
    JOIN signup s ON a.userId = s.userId
    WHERE a.date = '$today'
      AND s.designation != 'admin'
      AND s.regstatus = 'Approved'
      $typeCondition
      $branchCondition");
while ($r = mysqli_fetch_assoc($qPres)) {
    $presentUserIds[] = $r['userId'];
    $present++;
}

// ── On Tour ──
$on_tour = 0;
$tourUserIds = [];
$qTour = mysqli_query($con, "SELECT DISTINCT ta.userId
    FROM tourAttendance ta
    JOIN signup s ON ta.userId = s.userId
    WHERE ta.date = '$today'
      AND s.designation != 'admin'
      AND s.regstatus = 'Approved'
      $typeCondition
      $branchCondition");
while ($r = mysqli_fetch_assoc($qTour)) {
    $tourUserIds[] = $r['userId'];
    $on_tour++;
}

// ── On Leave ──
$on_leave = 0;
$leaveUserIds = [];
$qLeave = mysqli_query($con, "SELECT DISTINCT l.userId
    FROM leaves l
    JOIN signup s ON l.userId = s.userId
    WHERE '$today' BETWEEN l.from_date AND l.to_date
      AND l.leave_type != 'Auto'
      AND l.status = 'Approved'
      AND s.designation != 'admin'
      AND s.regstatus = 'Approved'
      $typeCondition
      $branchCondition");
while ($r = mysqli_fetch_assoc($qLeave)) {
    $leaveUserIds[] = $r['userId'];
    $on_leave++;
}

// ── Late Today ──
$late = 0;
$lateUserIds = [];
if (!empty($presentUserIds)) {
    $escapedIds = implode("','", array_map(fn($id) => mysqli_real_escape_string($con, $id), $presentUserIds));
    $qLate = mysqli_query($con, "
        SELECT a.userId,
               MIN(a.punchInTime) AS punchInTime,
               s.expectedPunchIn
        FROM attendance a
        JOIN signup s ON a.userId = s.userId
        WHERE a.date = '$today'
          AND a.userId IN ('$escapedIds')
          AND s.expectedPunchIn IS NOT NULL
          AND s.expectedPunchIn != ''
        GROUP BY a.userId, s.expectedPunchIn
    ");
    while ($lRow = mysqli_fetch_assoc($qLate)) {
        if (!empty($lRow['punchInTime']) && $lRow['punchInTime'] > $lRow['expectedPunchIn']) {
            $lateUserIds[] = $lRow['userId'];
            $late++;
        }
    }
}

// ── Absent Today ──
$presentPlaceholder = !empty($presentUserIds)
    ? "AND s.userId NOT IN ('" . implode("','", array_map(fn($id) => mysqli_real_escape_string($con, $id), $presentUserIds)) . "')"
    : '';
$tourPlaceholder = !empty($tourUserIds)
    ? "AND s.userId NOT IN ('" . implode("','", array_map(fn($id) => mysqli_real_escape_string($con, $id), $tourUserIds)) . "')"
    : '';

$qAbsent = mysqli_query($con, "
    SELECT COUNT(DISTINCT s.userId) AS absentCount
    FROM signup s
    LEFT JOIN leaves l ON l.userId = s.userId
        AND '$today' BETWEEN l.from_date AND l.to_date
        AND l.status = 'Approved'
        AND l.leave_type != 'Auto'
    WHERE s.designation != 'admin'
      AND s.regstatus = 'Approved'
      $typeCondition
      $branchCondition
      $presentPlaceholder
      $tourPlaceholder
      AND l.userId IS NULL
");
$absent = (int) (mysqli_fetch_assoc($qAbsent)['absentCount'] ?? 0);

// ══════════════════════════════════════════════════
// CAPACITY UTILIZATION & WORKLOAD METRICS
// ══════════════════════════════════════════════════
$totalWorkedSec = 0;
if (!empty($presentUserIds)) {
    $escapedIds = implode("','", array_map(fn($id) => mysqli_real_escape_string($con, $id), $presentUserIds));
    $qMH = mysqli_query($con, "
        SELECT SUM(TIME_TO_SEC(manHours)) AS totalSec
        FROM attendance
        WHERE date = '$today' AND userId IN ('$escapedIds')
    ");
    if ($qMH) {
        $totalWorkedSec = (int) (mysqli_fetch_assoc($qMH)['totalSec'] ?? 0);
    }
}
$totalWorkedHours = round($totalWorkedSec / 3600, 1);
$targetCapacityHours = $approved * 8;
$capacityUtilization = $targetCapacityHours > 0 ? min(100, round(($totalWorkedHours / $targetCapacityHours) * 100, 1)) : 0;

// Task Health Summary
$qTaskSummary = mysqli_query($con, "
    SELECT 
        COUNT(*) AS totalTasks,
        SUM(t.status = 'Running')  AS runningTasks,
        SUM(t.status = 'Paused')   AS pausedTasks,
        SUM(t.status = 'Finished') AS finishedTasks
    FROM TaskEntryNew t
    JOIN signup s ON t.userId = s.userId
    WHERE s.designation != 'admin'
      AND s.status = 'Active'
      $typeCondition
      $branchCondition
");
$taskSum = mysqli_fetch_assoc($qTaskSummary) ?? [];
$orgTotalTasks = (int) ($taskSum['totalTasks'] ?? 0);
$orgRunningTasks = (int) ($taskSum['runningTasks'] ?? 0);
$orgPausedTasks = (int) ($taskSum['pausedTasks'] ?? 0);
$orgFinishedTasks = (int) ($taskSum['finishedTasks'] ?? 0);
$taskCompletionRate = $orgTotalTasks > 0 ? round(($orgFinishedTasks / $orgTotalTasks) * 100, 1) : 0;

// ══════════════════════════════════════════════════
// PROJECTS & RESOURCE ENGAGEMENT ALLOCATION
// ══════════════════════════════════════════════════
// 1. Fetch allocated team member headcount per project from allocatedProject table (Resource Management)
$allocatedTeamMap = [];
$qAlloc = mysqli_query($con, "
    SELECT ap.projectId, pm.projectName, COUNT(DISTINCT ap.userId) AS allocCount
    FROM allocatedProject ap
    JOIN projectMaster pm ON ap.projectId = pm.projectId
    JOIN signup s ON ap.userId = s.userId
    WHERE s.status = 'Active' AND pm.status = 1
    GROUP BY ap.projectId, pm.projectName
");
if ($qAlloc) {
    while ($aRow = mysqli_fetch_assoc($qAlloc)) {
        $allocatedTeamMap[$aRow['projectId']] = (int) $aRow['allocCount'];
        $allocatedTeamMap['name_' . trim(strtolower($aRow['projectName']))] = (int) $aRow['allocCount'];
    }
}

// 2. Fetch task allocation headcount from task_allocation table
$taskAllocTeamMap = [];
$qTaskAlloc = mysqli_query($con, "
    SELECT project_name, COUNT(DISTINCT receiver_id) AS taskTeamCount
    FROM task_allocation
    WHERE project_name IS NOT NULL AND TRIM(project_name) != ''
    GROUP BY project_name
");
if ($qTaskAlloc) {
    while ($taRow = mysqli_fetch_assoc($qTaskAlloc)) {
        $taskAllocTeamMap[trim(strtolower($taRow['project_name']))] = (int) $taRow['taskTeamCount'];
    }
}

// 3. Fetch logged duration per project from TaskEntryNew
$projectTaskMap = [];
$qProjTasks = mysqli_query($con, "
    SELECT projectName,
           COUNT(DISTINCT userId) AS assignedTeamCount,
           COALESCE(SUM(totalDuration), 0) AS totalSec,
           COUNT(taskId) AS totalProjectTasks
    FROM TaskEntryNew
    WHERE projectName IS NOT NULL AND TRIM(projectName) != ''
    GROUP BY projectName
");
if ($qProjTasks) {
    while ($ptRow = mysqli_fetch_assoc($qProjTasks)) {
        $pKey = trim(strtolower($ptRow['projectName']));
        $projectTaskMap[$pKey] = [
            'assignedTeamCount' => (int) ($ptRow['assignedTeamCount'] ?? 0),
            'loggedHours' => round(((int) ($ptRow['totalSec'] ?? 0)) / 3600, 1),
            'totalProjectTasks' => (int) ($ptRow['totalProjectTasks'] ?? 0)
        ];
    }
}

// 4. Query active projects allocated in Resource Management (allocatedProject or internAllocations)
$projResult = mysqli_query($con, "
    SELECT pm.projectId, pm.projectName, pm.deadLine, pm.priority,
           COUNT(DISTINCT COALESCE(ap.userId, ia.internId)) AS resTeamSize,
           COALESCE(MAX(pp.progress), 0) AS progress
    FROM projectMaster pm
    LEFT JOIN allocatedProject ap ON ap.projectId = pm.projectId
    LEFT JOIN internAllocations ia ON ia.projectName = pm.projectName AND ia.endDate >= '$today'
    LEFT JOIN signup s ON (s.userId = ap.userId OR s.internId = ia.internId OR s.userId = ia.internId)
    LEFT JOIN projectProgress pp ON pm.projectId = pp.projectId
    WHERE pm.status = 1 
      AND (ap.id IS NOT NULL OR ia.id IS NOT NULL)
      AND (s.status IS NULL OR s.status = 'Active')
    GROUP BY pm.projectId, pm.projectName, pm.deadLine, pm.priority
    ORDER BY
        CASE LOWER(pm.priority) WHEN 'p1' THEN 1 WHEN 'p2' THEN 2 WHEN 'p3' THEN 3 WHEN 'p4' THEN 4 ELSE 5 END,
        pm.deadLine ASC
");

$topProjects = [];
$urgentProjectsCount = 0;
if ($projResult && mysqli_num_rows($projResult) > 0) {
    while ($r = mysqli_fetch_assoc($projResult)) {
        $pId = $r['projectId'];
        $pName = trim($r['projectName'] ?? '');
        $pKey = strtolower($pName);

        $resCnt = (int) ($r['resTeamSize'] ?? 0);
        $allocCnt = $allocatedTeamMap[$pId] ?? ($allocatedTeamMap['name_' . $pKey] ?? 0);
        $taCnt = $taskAllocTeamMap[$pKey] ?? 0;
        $tData = $projectTaskMap[$pKey] ?? ['assignedTeamCount' => 0, 'loggedHours' => 0, 'totalProjectTasks' => 0];

        $r['assignedTeamCount'] = max($resCnt, $allocCnt, $taCnt, $tData['assignedTeamCount']);
        $r['loggedHours'] = $tData['loggedHours'];
        $r['totalProjectTasks'] = $tData['totalProjectTasks'];

        $deadline = $r['deadLine'] ?? '';
        if (!empty($deadline)) {
            $daysLeft = (strtotime($deadline) - strtotime($today)) / 86400;
            if ($daysLeft <= 7 && $daysLeft >= 0)
                $urgentProjectsCount++;
        }
        $topProjects[] = $r;
    }
}

// ══════════════════════════════════════════════════
// INTERN DATA & WORKLOAD MATRIX
// ══════════════════════════════════════════════════
$internTypeFilter = '';
if (!empty($intType) && $intType !== 'Reset' && $intType !== 'All') {
    $internTypeFilter = "AND type = '" . mysqli_real_escape_string($con, $intType) . "'";
} else {
    $internTypeFilter = "AND type IN ('Online','Offline')";
}

$allInterns = [];
$internIds = [];
$qSignup = mysqli_query($con, "
    SELECT userId, fullName, type, branch, office, totalWorkingHours
    FROM signup s
    WHERE designation != 'admin'
      AND status = 'Active'
      AND regstatus = 'Approved'
      $internTypeFilter
      $branchCondition
    ORDER BY fullName
");
while ($row = mysqli_fetch_assoc($qSignup)) {
    $allInterns[$row['userId']] = $row;
    $internIds[] = $row['userId'];
}

$internData = [];
$overloadedCount = 0;
$optimalCount = 0;
$benchCount = 0;

if (!empty($internIds)) {
    $escapedInternIds = implode("','", array_map(fn($id) => mysqli_real_escape_string($con, $id), $internIds));
    $currentMonth = date('Y-m');

    $attendanceMap = [];
    $qAtt = mysqli_query($con, "
        SELECT userId,
               MIN(punchInTime)  AS punchInTime,
               MAX(punchOutTime) AS punchOutTime,
               SEC_TO_TIME(SUM(TIME_TO_SEC(manHours))) AS totalMH
        FROM attendance
        WHERE date = '$today'
          AND userId IN ('$escapedInternIds')
        GROUP BY userId
    ");
    while ($row = mysqli_fetch_assoc($qAtt)) {
        $attendanceMap[$row['userId']] = $row;
    }

    $taskMap = [];
    $qTask = mysqli_query($con, "
        SELECT userId,
               COUNT(*) AS totalTasks,
               SUM(status IN ('Running','Paused')) AS pendingTasks,
               SUM(status = 'Finished') AS completedTasks
        FROM TaskEntryNew
        WHERE userId IN ('$escapedInternIds')
        GROUP BY userId
    ");
    while ($row = mysqli_fetch_assoc($qTask)) {
        $taskMap[$row['userId']] = $row;
    }

    $lateMarkMap = [];
    $qLateMark = mysqli_query($con, "
        SELECT userId, COUNT(*) AS lateCount
        FROM attendance
        WHERE lateMark = 'yes'
          AND DATE_FORMAT(date,'%Y-%m') = '$currentMonth'
          AND userId IN ('$escapedInternIds')
        GROUP BY userId
    ");
    while ($row = mysqli_fetch_assoc($qLateMark)) {
        $lateMarkMap[$row['userId']] = (int) $row['lateCount'];
    }

    foreach ($internIds as $uid) {
        $intern = $allInterns[$uid];
        $att = $attendanceMap[$uid] ?? [];
        $task = $taskMap[$uid] ?? [];

        $pIn = (!empty($att['punchInTime']) && $att['punchInTime'] !== '00:00:00') ? date('h:i A', strtotime($att['punchInTime'])) : '-';
        $pOut = (!empty($att['punchOutTime']) && $att['punchOutTime'] !== '00:00:00') ? date('h:i A', strtotime($att['punchOutTime'])) : '-';
        $mh = (!empty($att['totalMH']) && $att['totalMH'] !== '00:00:00') ? date('G:i', strtotime($att['totalMH'])) : '0:00';

        $actualWH = $intern['totalWorkingHours'] ?? '08:00:00';
        $actualWHp = explode(':', $actualWH);
        $actualWHdec = (int) $actualWHp[0] + ((int) ($actualWHp[1] ?? 0) / 60);

        if (!empty($att['punchInTime']) && $att['punchInTime'] !== '00:00:00') {
            $inDT = date_create($att['punchInTime']);
            $outDT = (!empty($att['punchOutTime']) && $att['punchOutTime'] !== '00:00:00')
                ? date_create($att['punchOutTime'])
                : date_create(date('H:i:s'));
            $diff = date_diff($inDT, $outDT);
            $todayHours = $diff->h + ($diff->i / 60);
        } else {
            $todayHours = 0;
        }
        $todayPerc = $actualWHdec > 0 ? min(100, round(($todayHours / $actualWHdec) * 100)) : 0;
        $actualWHdisplay = date('G:i', strtotime($actualWH)) . ' hrs';

        $pendingCount = (int) ($task['pendingTasks'] ?? 0);
        if ($pendingCount > 4) {
            $overloadedCount++;
            $loadStatus = 'Overloaded';
            $loadBadge = 'background:#fef2f2;color:#dc2626;border:1px solid #fecaca;';
        } elseif ($pendingCount > 0) {
            $optimalCount++;
            $loadStatus = 'Optimal';
            $loadBadge = 'background:#ecfdf5;color:#059669;border:1px solid #a7f3d0;';
        } else {
            $benchCount++;
            $loadStatus = 'Bench / Available';
            $loadBadge = 'background:#eff6ff;color:#1977e1;border:1px solid #bfdbfe;';
        }

        $internData[] = [
            'userId' => $uid,
            'fullName' => $intern['fullName'],
            'type' => $intern['type'],
            'branch' => $intern['branch'] ?: ($intern['office'] ?: 'Main'),
            'pIn' => $pIn,
            'pOut' => $pOut,
            'manHours' => $mh,
            'actualWH' => $actualWHdisplay,
            'totalTasks' => (int) ($task['totalTasks'] ?? 0),
            'pendingTasks' => $pendingCount,
            'completedTasks' => (int) ($task['completedTasks'] ?? 0),
            'todayPerc' => $todayPerc,
            'lateCount' => $lateMarkMap[$uid] ?? 0,
            'loadStatus' => $loadStatus,
            'loadBadge' => $loadBadge
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php include('../partials/_links.php'); ?>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        :root {
            --brand: #1977e1;
            --brand-dark: #1254b0;
            --brand-bg: #eef4fd;
            --green: #059669;
            --green-bg: #ecfdf5;
            --amber: #d97706;
            --amber-bg: #fffbeb;
            --red: #dc2626;
            --red-bg: #fef2f2;
            --purple: #7c3aed;
            --purple-bg: #f5f3ff;
            --teal: #0891b2;
            --teal-bg: #ecfeff;
            --ink: #111827;
            --ink-2: #374151;
            --ink-3: #6b7280;
            --border: #e5e7eb;
            --surface: #f9fafb;
            --radius: 10px;
            --radius-lg: 14px;
            --shadow: 0 2px 12px rgba(25, 119, 225, 0.07), 0 1px 3px rgba(0, 0, 0, 0.04);
        }

        body {
            background: #f0f2f7;
        }

        .dash-card {
            background: #fff;
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            padding: 14px 16px;
        }

        .dash-card .card-accent {
            height: 3px;
            border-radius: 99px;
            margin-bottom: 14px;
            background: linear-gradient(135deg, var(--brand), var(--brand-dark));
        }

        .dash-card .c-title {
            font-size: 14px;
            font-weight: 700;
            color: var(--brand);
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .stat-tile {
            background: #fff;
            border-radius: var(--radius);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            padding: 14px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            transition: box-shadow .15s, transform .15s;
            cursor: pointer;
        }

        .stat-tile:hover {
            box-shadow: 0 6px 20px rgba(25, 119, 225, 0.13);
            transform: translateY(-2px);
            text-decoration: none;
        }

        .stat-tile .ico {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 19px;
            flex-shrink: 0;
        }

        .stat-tile .num {
            font-size: 24px;
            font-weight: 700;
            line-height: 1;
        }

        .stat-tile .lbl {
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .4px;
            margin-top: 3px;
        }

        .t-blue .ico {
            background: #eff6ff;
        }

        .t-blue .num {
            color: var(--brand);
        }

        .t-blue .lbl {
            color: #6b9fe4;
        }

        .t-green .ico {
            background: var(--green-bg);
        }

        .t-green .num {
            color: var(--green);
        }

        .t-green .lbl {
            color: #34a87a;
        }

        .t-red .ico {
            background: var(--red-bg);
        }

        .t-red .num {
            color: var(--red);
        }

        .t-red .lbl {
            color: #e05c5c;
        }

        .t-amber .ico {
            background: var(--amber-bg);
        }

        .t-amber .num {
            color: var(--amber);
        }

        .t-amber .lbl {
            color: #d4a030;
        }

        .t-purple .ico {
            background: var(--purple-bg);
        }

        .t-purple .num {
            color: var(--purple);
        }

        .t-purple .lbl {
            color: #9b6ae0;
        }

        .t-teal .ico {
            background: var(--teal-bg);
        }

        .t-teal .num {
            color: var(--teal);
        }

        .t-teal .lbl {
            color: #0e9abd;
        }

        .t-gray .ico {
            background: #f3f4f6;
        }

        .t-gray .num {
            color: var(--ink);
        }

        .t-gray .lbl {
            color: var(--ink-3);
        }

        .filter-header-bar {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 12px 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            box-shadow: var(--shadow);
        }

        .filter-group {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .filter-label {
            font-size: 11px;
            font-weight: 700;
            color: var(--ink-3);
            text-transform: uppercase;
            letter-spacing: .4px;
        }

        .pill-btn {
            font-size: 12px;
            font-weight: 600;
            padding: 5px 14px;
            border-radius: 8px;
            border: 1px solid var(--border);
            color: var(--ink-2);
            background: #f9fafb;
            text-decoration: none;
            transition: all .15s;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .pill-btn:hover {
            background: var(--brand-bg);
            color: var(--brand);
            border-color: #bfdbfe;
            text-decoration: none;
        }

        .pill-btn.active {
            background: var(--brand);
            color: #fff;
            border-color: var(--brand);
            font-weight: 700;
        }

        .pill-btn.reset {
            border-color: #fecaca;
            color: var(--red);
            background: var(--red-bg);
        }

        .pill-btn.reset:hover {
            background: var(--red);
            color: #fff;
        }

        .active-filter-badge {
            font-size: 11px;
            background: var(--brand-bg);
            color: var(--brand);
            border: 1px solid #bfdbfe;
            border-radius: 99px;
            padding: 4px 12px;
            font-weight: 600;
        }

        .bday-bar {
            background: #fff;
            border: 1px solid #fde68a;
            border-radius: var(--radius);
            padding: 8px 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            overflow: hidden;
        }

        .bday-bar .bi {
            color: #f59e0b;
            font-size: 16px;
            flex-shrink: 0;
        }

        .bday-bar marquee {
            font-size: 13px;
            color: var(--ink-2);
        }

        .bday-bar marquee strong {
            color: #d35400;
        }

        .bday-bar .wish-btn {
            display: inline-block;
            font-size: 11px;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 5px;
            background: var(--brand);
            color: #fff;
            text-decoration: none;
            margin-left: 6px;
        }

        .bday-bar .wish-btn:hover {
            background: var(--brand-dark);
        }

        .prog-track {
            height: 6px;
            background: #eef1f7;
            border-radius: 99px;
            overflow: hidden;
        }

        .prog-fill {
            height: 100%;
            border-radius: 99px;
        }

        .proj-table thead th {
            background: linear-gradient(135deg, var(--brand), var(--brand-dark));
            color: #fff;
            font-size: 11px;
            font-weight: 600;
            border: none;
            padding: 8px 12px;
            position: sticky;
            top: 0;
            z-index: 1;
        }

        .proj-table tbody td {
            font-size: 11px;
            padding: 8px 12px;
            vertical-align: middle;
        }

        .proj-table tbody tr:hover {
            background: #eef4fd;
        }

        .proj-scroll {
            max-height: 220px;
            overflow-y: auto;
            border: 1px solid var(--border);
            border-radius: 8px;
        }

        .proj-scroll::-webkit-scrollbar {
            width: 5px;
        }

        .proj-scroll::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 99px;
        }

        .proj-scroll::-webkit-scrollbar-thumb {
            background: #bfdbfe;
            border-radius: 99px;
        }

        .pri {
            display: inline-block;
            font-size: 10px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 99px;
        }

        .pri-p1 {
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fecaca;
        }

        .pri-p2 {
            background: #fff7ed;
            color: #ea580c;
            border: 1px solid #fed7aa;
        }

        .pri-p3 {
            background: #fffbeb;
            color: #d97706;
            border: 1px solid #fde68a;
        }

        .pri-p4 {
            background: #ecfdf5;
            color: #059669;
            border: 1px solid #a7f3d0;
        }

        .deadline-urgent {
            color: #dc2626;
            font-weight: 700;
        }

        .intern-search-wrap {
            position: relative;
            margin-bottom: 14px;
        }

        .intern-search-wrap input {
            width: 100%;
            padding: 8px 14px 8px 36px;
            border-radius: 8px;
            border: 1.5px solid var(--border);
            font-size: 13px;
            outline: none;
            transition: border-color .15s;
        }

        .intern-search-wrap input:focus {
            border-color: var(--brand);
        }

        .intern-search-wrap .search-icon {
            position: absolute;
            left: 11px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--ink-3);
            font-size: 15px;
            pointer-events: none;
        }

        .intern-search-wrap .clear-search {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--ink-3);
            font-size: 16px;
            cursor: pointer;
            display: none;
            background: none;
            border: none;
            padding: 0;
            line-height: 1;
        }

        .intern-card-wrap {
            display: none;
        }

        .intern-card-wrap.visible {
            display: block;
        }

        .intern-card {
            background: var(--surface);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            padding: 14px;
        }

        .intern-nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 10px;
            flex-wrap: wrap;
            gap: 8px;
        }

        .intern-nav-btn {
            background: var(--brand-bg);
            border: 1px solid #bfdbfe;
            color: var(--brand);
            border-radius: 7px;
            padding: 5px 14px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: background .13s;
        }

        .intern-nav-btn:hover {
            background: var(--brand);
            color: #fff;
        }

        .intern-nav-btn:disabled {
            opacity: 0.35;
            cursor: not-allowed;
        }

        .intern-nav-info {
            font-size: 12px;
            color: var(--ink-3);
            font-weight: 500;
        }

        .intern-stat {
            border-radius: 10px;
            padding: 12px 8px;
            text-align: center;
        }

        .intern-stat .is-num {
            font-size: 22px;
            font-weight: 700;
            line-height: 1.1;
        }

        .intern-stat .is-icon {
            font-size: 15px;
            margin-bottom: 4px;
        }

        .intern-stat .is-lbl {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .4px;
            margin-top: 3px;
        }

        .intern-stat .is-sub {
            font-size: 9px;
            color: var(--ink-3);
            margin-top: 2px;
            line-height: 1.2;
        }

        .empty-intern {
            text-align: center;
            padding: 32px 16px;
            color: var(--ink-3);
            font-size: 13px;
        }

        .empty-intern i {
            font-size: 1.8rem;
            display: block;
            margin-bottom: 8px;
            color: var(--ink-3);
            opacity: .4;
        }

        .sec-label {
            font-size: 11px;
            font-weight: 800;
            color: var(--ink-3);
            letter-spacing: .6px;
            text-transform: uppercase;
            margin-bottom: 10px;
            margin-top: 6px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .sec-label::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--border);
        }

        .date-pill {
            font-size: 12px;
            background: #fff;
            border: 1px solid var(--border);
            padding: 5px 14px;
            border-radius: 7px;
            color: var(--ink-2);
            font-weight: 600;
        }
    </style>
</head>

<body>
    <?php
    if (isset($_SESSION['alert_icon'])) {
        $icon = $_SESSION['alert_icon'];
        $text = $_SESSION['alert_text'];
        echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
    <script>document.addEventListener('DOMContentLoaded',function(){
        Swal.mixin({toast:true,position:'top-end',showConfirmButton:false,timer:2500,timerProgressBar:true})
        .fire({icon:'$icon',title:'$text'});
    });</script>";
        unset($_SESSION['alert_icon'], $_SESSION['alert_title'], $_SESSION['alert_text']);
    }
    ?>

    <div class="container-scroller">
        <?php include('../partials/_navbar.php'); ?>
        <div class="container-fluid page-body-wrapper">
            <?php include('../partials/_sidebar.php'); ?>

            <div class="main-panel">
                <div class="content-wrapper" style="background:#f0f2f7;">

                    <!-- Birthday bar -->
                    <?php if (!empty($todayBirthdays)): ?>
                        <div class="bday-bar mb-3">
                            <i class="bi bi-gift-fill"></i>
                            <marquee behavior="scroll" direction="left" scrollamount="4" onmouseover="this.stop();"
                                onmouseout="this.start();" style="flex:1;">
                                <strong>Today's Birthdays:</strong>
                                <?php foreach ($todayBirthdays as $b):
                                    $fn = $b['fullName'];
                                    $msg = urlencode("Happy Birthday " . explode(' ', $fn)[0] . " 🎉");
                                    $wa = "https://wa.me/?text=$msg";
                                    ?>
                                    &nbsp;&nbsp;🎂 <strong><?= htmlspecialchars($fn) ?></strong>
                                    <a class="wish-btn" href="<?= $wa ?>" target="_blank">Wish</a>
                                    &nbsp;&nbsp;&nbsp;
                                <?php endforeach; ?>
                            </marquee>
                        </div>
                    <?php endif; ?>

                    <!-- ══════════════════════════════════════════════════
                     DUAL FILTER HEADER BAR (TYPE & BRANCH)
                     ══════════════════════════════════════════════════ -->
                    <div class="filter-header-bar mb-3">
                        <!-- Intern Type Filter -->
                        <div class="filter-group">
                            <span class="filter-label"><i class="bi bi-people-fill mr-1"></i>Intern Type:</span>
                            <a href="SET_SESSION.php?type=All"
                                class="pill-btn <?= (empty($intType) || $intType === 'Reset' || $intType === 'All') ? 'active' : '' ?>">All
                                Types</a>
                            <a href="SET_SESSION.php?type=Online"
                                class="pill-btn <?= ($intType === 'Online') ? 'active' : '' ?>"><i class="bi bi-wifi"></i>
                                Online</a>
                            <a href="SET_SESSION.php?type=Offline"
                                class="pill-btn <?= ($intType === 'Offline') ? 'active' : '' ?>"><i
                                    class="bi bi-building"></i> Offline</a>
                        </div>

                        <!-- Branch Filter -->
                        <div class="filter-group">
                            <span class="filter-label"><i class="bi bi-geo-alt-fill mr-1"></i>Branch:</span>
                            <a href="SET_SESSION.php?branch=All"
                                class="pill-btn <?= (empty($intBranch) || $intBranch === 'Reset' || $intBranch === 'All') ? 'active' : '' ?>">All
                                Branches</a>
                            <?php foreach ($availableBranches as $bName): ?>
                                <a href="SET_SESSION.php?branch=<?= urlencode($bName) ?>"
                                    class="pill-btn <?= ($intBranch === $bName) ? 'active' : '' ?>"><?= htmlspecialchars($bName) ?></a>
                            <?php endforeach; ?>
                            <?php if (!empty($intType) || !empty($intBranch)): ?>
                                <a href="SET_SESSION.php?type=Reset&branch=Reset" class="pill-btn reset"
                                    title="Clear all filters"><i class="bi bi-x-circle"></i> Reset Filters</a>
                            <?php endif; ?>
                        </div>

                        <!-- Date Badge -->
                        <div class="date-pill ml-auto">
                            <i class="bi bi-calendar3 mr-1 text-primary"></i><?= date('d M Y') ?>
                        </div>
                    </div>

                    <!-- ══════════════════════════════════════════════════
                     ROW 1: WORKFORCE HEADCOUNT TILES
                     ══════════════════════════════════════════════════ -->
                    <div class="sec-label">Workforce Overview</div>
                    <div class="row mb-3" style="row-gap:10px;">
                        <div class="col-6 col-md-4 col-lg">
                            <a href="active-interns.php?back=adminDashboard.php" class="stat-tile t-blue d-block">
                                <div class="ico"><i class="bi bi-person-check-fill"></i></div>
                                <div>
                                    <div class="num"><?= $approved ?></div>
                                    <div class="lbl">Total Interns</div>
                                </div>
                            </a>
                        </div>
                        <div class="col-6 col-md-4 col-lg">
                            <a href="down_tracking.php?type=present&back=adminDashboard.php"
                                class="stat-tile t-green d-block">
                                <div class="ico"><i class="bi bi-door-open-fill"></i></div>
                                <div>
                                    <div class="num"><?= $present ?></div>
                                    <div class="lbl">Present Today</div>
                                </div>
                            </a>
                        </div>
                        <div class="col-6 col-md-4 col-lg">
                            <a href="down_tracking.php?type=absent&back=adminDashboard.php"
                                class="stat-tile t-red d-block">
                                <div class="ico"><i class="bi bi-person-x-fill"></i></div>
                                <div>
                                    <div class="num"><?= $absent ?></div>
                                    <div class="lbl">Absent Today</div>
                                </div>
                            </a>
                        </div>
                        <div class="col-6 col-md-4 col-lg">
                            <a href="down_tracking.php?type=leave&back=adminDashboard.php"
                                class="stat-tile t-amber d-block">
                                <div class="ico"><i class="bi bi-calendar-minus-fill"></i></div>
                                <div>
                                    <div class="num"><?= $on_leave ?></div>
                                    <div class="lbl">On Leave</div>
                                </div>
                            </a>
                        </div>
                        <div class="col-6 col-md-4 col-lg">
                            <a href="down_tracking.php?type=tour&back=adminDashboard.php"
                                class="stat-tile t-purple d-block">
                                <div class="ico"><i class="bi bi-geo-alt-fill"></i></div>
                                <div>
                                    <div class="num"><?= $on_tour ?></div>
                                    <div class="lbl">On Tour</div>
                                </div>
                            </a>
                        </div>
                        <div class="col-6 col-md-4 col-lg">
                            <a href="down_tracking.php?type=late&back=adminDashboard.php"
                                class="stat-tile t-teal d-block">
                                <div class="ico"><i class="bi bi-clock-history"></i></div>
                                <div>
                                    <div class="num"><?= $late ?></div>
                                    <div class="lbl">Late Today</div>
                                </div>
                            </a>
                        </div>
                    </div>

                    <!-- ══════════════════════════════════════════════════
                     ROW 2: WORKLOAD & CAPACITY INTELLIGENCE CARDS
                     ══════════════════════════════════════════════════ -->
                    <div class="sec-label">Executive Workload & Capacity Intelligence</div>
                    <div class="row mb-3" style="row-gap:12px;">
                        <!-- Capacity Utilization -->
                        <div class="col-md-4">
                            <div class="dash-card h-100">
                                <div class="card-accent"></div>
                                <div class="c-title">
                                    <span><i class="bi bi-lightning-charge-fill text-warning mr-1"></i>Capacity
                                        Utilization</span>
                                    <span
                                        class="badge badge-pill <?= $capacityUtilization >= 75 ? 'badge-success' : ($capacityUtilization >= 40 ? 'badge-warning' : 'badge-danger') ?>">
                                        <?= $capacityUtilization ?>%
                                    </span>
                                </div>
                                <div class="text-center py-2">
                                    <div style="font-size:32px;font-weight:800;color:var(--brand);line-height:1;">
                                        <?= $totalWorkedHours ?> <small
                                            style="font-size:14px;color:var(--ink-3);">hrs</small></div>
                                    <div style="font-size:11px;color:var(--ink-3);margin-top:4px;">Worked Today out of
                                        <strong><?= $targetCapacityHours ?> hrs</strong> Target</div>
                                </div>
                                <div class="mt-2">
                                    <div class="prog-track" style="height:8px;">
                                        <div class="prog-fill"
                                            style="width:<?= $capacityUtilization ?>%;background:<?= $capacityUtilization >= 75 ? 'var(--green)' : ($capacityUtilization >= 40 ? 'var(--amber)' : 'var(--red)') ?>;">
                                        </div>
                                    </div>
                                    <div class="d-flex justify-content-between mt-2"
                                        style="font-size:10px;color:var(--ink-3);">
                                        <span>Target: <?= $targetCapacityHours ?>h</span>
                                        <span>Efficiency Rate</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Task Health & Velocity -->
                        <div class="col-md-4">
                            <div class="dash-card h-100">
                                <div class="card-accent"></div>
                                <div class="c-title">
                                    <span><i class="bi bi-list-check text-primary mr-1"></i>Organization Task
                                        Health</span>
                                    <span class="badge badge-pill badge-info"><?= $orgTotalTasks ?> Tasks</span>
                                </div>
                                <div class="row text-center py-2" style="row-gap:6px;">
                                    <div class="col-4">
                                        <div style="font-size:20px;font-weight:700;color:var(--green);">
                                            <?= $orgFinishedTasks ?></div>
                                        <div
                                            style="font-size:9px;font-weight:700;color:var(--ink-3);text-transform:uppercase;">
                                            Finished</div>
                                    </div>
                                    <div class="col-4">
                                        <div style="font-size:20px;font-weight:700;color:var(--brand);">
                                            <?= $orgRunningTasks ?></div>
                                        <div
                                            style="font-size:9px;font-weight:700;color:var(--ink-3);text-transform:uppercase;">
                                            Running</div>
                                    </div>
                                    <div class="col-4">
                                        <div style="font-size:20px;font-weight:700;color:var(--amber);">
                                            <?= $orgPausedTasks ?></div>
                                        <div
                                            style="font-size:9px;font-weight:700;color:var(--ink-3);text-transform:uppercase;">
                                            Paused</div>
                                    </div>
                                </div>
                                <div class="mt-2">
                                    <div class="d-flex justify-content-between mb-1"
                                        style="font-size:11px;color:var(--ink-3);">
                                        <span>Completion Rate</span>
                                        <strong><?= $taskCompletionRate ?>%</strong>
                                    </div>
                                    <div class="prog-track" style="height:8px;">
                                        <div class="prog-fill"
                                            style="width:<?= $taskCompletionRate ?>%;background:var(--brand);"></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Resource Load Balance -->
                        <div class="col-md-4">
                            <div class="dash-card h-100">
                                <div class="card-accent"></div>
                                <div class="c-title">
                                    <span><i class="bi bi-cpu-fill text-danger mr-1"></i>Resource Load Matrix</span>
                                    <a href="freeInternPool.php"
                                        style="font-size:11px;color:var(--brand);text-decoration:none;">Bench Pool →</a>
                                </div>
                                <div class="row text-center py-2" style="row-gap:6px;">
                                    <div class="col-4">
                                        <div style="font-size:20px;font-weight:700;color:var(--red);">
                                            <?= $overloadedCount ?></div>
                                        <div
                                            style="font-size:9px;font-weight:700;color:var(--ink-3);text-transform:uppercase;">
                                            Overloaded</div>
                                    </div>
                                    <div class="col-4">
                                        <div style="font-size:20px;font-weight:700;color:var(--green);">
                                            <?= $optimalCount ?></div>
                                        <div
                                            style="font-size:9px;font-weight:700;color:var(--ink-3);text-transform:uppercase;">
                                            Optimal</div>
                                    </div>
                                    <div class="col-4">
                                        <div style="font-size:20px;font-weight:700;color:var(--brand);">
                                            <?= $benchCount ?></div>
                                        <div
                                            style="font-size:9px;font-weight:700;color:var(--ink-3);text-transform:uppercase;">
                                            Free / Bench</div>
                                    </div>
                                </div>
                                <div class="mt-2 text-center" style="font-size:11px;color:var(--ink-3);">
                                    <?= $benchCount > 0 ? "⚡ <strong class='text-primary'>$benchCount resources</strong> ready for project allocation" : "✓ All active resources fully engaged" ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ══════════════════════════════════════════════════
                     ROW 3: 4 INTERACTIVE ANALYTICAL CHARTS
                     ══════════════════════════════════════════════════ -->
                    <div class="sec-label">Workload & Productivity Analytics Charts</div>
                    <div class="row mb-3" style="row-gap:14px;">
                        <!-- Chart 1: Workforce Presence -->
                        <div class="col-md-6 col-lg-3">
                            <div class="dash-card h-100">
                                <div class="card-accent"></div>
                                <div class="c-title" style="font-size:13px;">Workforce Distribution</div>
                                <div style="position:relative;height:180px;">
                                    <canvas id="chartWorkforce"></canvas>
                                </div>
                            </div>
                        </div>

                        <!-- Chart 2: Task Status Distribution -->
                        <div class="col-md-6 col-lg-3">
                            <div class="dash-card h-100">
                                <div class="card-accent"></div>
                                <div class="c-title" style="font-size:13px;">Task Health Breakdown</div>
                                <div style="position:relative;height:180px;">
                                    <canvas id="chartTaskStatus"></canvas>
                                </div>
                            </div>
                        </div>

                        <!-- Chart 3: Top Projects Logged Hours -->
                        <div class="col-md-6 col-lg-3">
                            <div class="dash-card h-100">
                                <div class="card-accent"></div>
                                <div class="c-title" style="font-size:13px;">Project Workload (Hours)</div>
                                <div style="position:relative;height:180px;">
                                    <canvas id="chartProjectWorkload"></canvas>
                                </div>
                            </div>
                        </div>

                        <!-- Chart 4: Resource Load Balance -->
                        <div class="col-md-6 col-lg-3">
                            <div class="dash-card h-100">
                                <div class="card-accent"></div>
                                <div class="c-title" style="font-size:13px;">Resource Load Balance</div>
                                <div style="position:relative;height:180px;">
                                    <canvas id="chartResourceLoad"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ══════════════════════════════════════════════════
                     ROW 4: PROJECT TRACKER & ANALYTICS
                     ══════════════════════════════════════════════════ -->
                    <div class="sec-label">Project Health & Workload Tracker</div>
                    <div class="dash-card mb-3" style="padding-bottom:14px;">
                        <div class="card-accent"></div>
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <div class="c-title mb-0">Project Portfolio & Resource Engagement</div>
                            <div style="font-size:12px;">
                                <a href="projectUtilizationReport.php" class="mr-3"
                                    style="color:var(--brand);font-weight:600;text-decoration:none;"><i
                                        class="bi bi-graph-up mr-1"></i>Utilization Report</a>
                                <a href="allocationVarianceReport.php" class="mr-3"
                                    style="color:var(--brand);font-weight:600;text-decoration:none;"><i
                                        class="bi bi-sliders mr-1"></i>Variance Report</a>
                                <a href="projectMaster.php"
                                    style="color:var(--brand);font-weight:600;text-decoration:none;">Manage →</a>
                            </div>
                        </div>
                        <div class="proj-scroll">
                            <table class="table table-borderless proj-table mb-0">
                                <thead>
                                    <tr>
                                        <th style="width:28%;">Project</th>
                                        <th style="width:14%;">Deadline</th>
                                        <th style="width:10%;">Priority</th>
                                        <th style="width:14%;">Assigned Team</th>
                                        <th style="width:14%;">Logged Hours</th>
                                        <th style="width:20%;">Progress</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($topProjects)):
                                        foreach ($topProjects as $row):
                                            $prog = (int) ($row['progress'] ?? 0);
                                            $deadline = $row['deadLine'] ?? '';
                                            if (!empty($deadline)) {
                                                $daysLeft = (strtotime($deadline) - strtotime($today)) / 86400;
                                                $dlClass = $daysLeft <= 7 ? 'deadline-urgent' : '';
                                                $formattedDeadline = date('d M y', strtotime($deadline));
                                            } else {
                                                $daysLeft = 999;
                                                $dlClass = '';
                                                $formattedDeadline = 'No Deadline';
                                            }
                                            $barColor = $prog >= 75 ? '#059669' : ($prog >= 50 ? '#d97706' : ($prog >= 25 ? '#ea580c' : '#dc2626'));
                                            $priority = strtolower($row['priority'] ?? 'p4');
                                            $priClass = 'pri-' . $priority;
                                            $loggedH = round((float) ($row['loggedHours'] ?? 0), 1);
                                            $teamCnt = (int) ($row['assignedTeamCount'] ?? 0);
                                            ?>
                                            <tr>
                                                <td style="font-weight:600;color:var(--ink-2);">
                                                    <?= htmlspecialchars($row['projectName']) ?>
                                                </td>
                                                <td class="<?= $dlClass ?>">
                                                    <?= $formattedDeadline ?>
                                                    <?= ($daysLeft <= 7 && $daysLeft >= 0) ? ' <small class="text-danger">(' . (int) $daysLeft . 'd)</small>' : '' ?>
                                                </td>
                                                <td><span
                                                        class="pri <?= $priClass ?>"><?= strtoupper($row['priority'] ?? 'N/A') ?></span>
                                                </td>
                                                <td><span class="badge badge-light border"><i
                                                            class="bi bi-people mr-1"></i><?= $teamCnt ?> members</span></td>
                                                <td><strong class="text-primary"><?= $loggedH ?></strong> hrs</td>
                                                <td>
                                                    <div class="d-flex align-items-center" style="gap:8px;">
                                                        <div class="prog-track" style="flex:1;">
                                                            <div class="prog-fill"
                                                                style="width:<?= $prog ?>%;background:<?= $barColor ?>;"></div>
                                                        </div>
                                                        <span
                                                            style="font-size:11px;font-weight:700;color:var(--ink-3);min-width:32px;"><?= $prog ?>%</span>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; else: ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-muted py-3" style="font-size:12px;">No
                                                active projects found</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- ══════════════════════════════════════════════════
                     ROW 5: INTERN STATUS & DETAILS
                     ══════════════════════════════════════════════════ -->
                    <div class="sec-label">Individual Intern Workload & Attendance Cards</div>
                    <div class="dash-card mb-3">
                        <div class="card-accent"></div>

                        <div class="d-flex align-items-center justify-content-between mb-2 flex-wrap" style="gap:8px;">
                            <div class="c-title mb-0">Intern Performance Directory</div>
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge badge-pill badge-primary">Total: <?= count($internData) ?></span>
                            </div>
                        </div>

                        <!-- Search box -->
                        <div class="intern-search-wrap">
                            <i class="bi bi-search search-icon"></i>
                            <input type="text" id="internSearch" placeholder="Search intern by name…"
                                autocomplete="off">
                            <button class="clear-search" id="clearSearch" title="Clear">×</button>
                        </div>

                        <!-- No results message -->
                        <div id="internNoResults" class="empty-intern" style="display:none;">
                            <i class="bi bi-person-x"></i>
                            No intern found matching your search.
                        </div>

                        <!-- Navigation -->
                        <div class="intern-nav" id="internNav">
                            <button class="intern-nav-btn" id="internPrev"><i class="bi bi-chevron-left"></i>
                                Prev</button>
                            <span class="intern-nav-info" id="internNavInfo">— / —</span>
                            <button class="intern-nav-btn" id="internNext">Next <i
                                    class="bi bi-chevron-right"></i></button>
                        </div>

                        <!-- Intern cards -->
                        <div id="internCardsContainer">
                            <?php foreach ($internData as $intern):
                                $sc = $intern['todayPerc'] >= 80 ? '#059669' : ($intern['todayPerc'] >= 50 ? '#d97706' : '#dc2626');
                                $taskPerc = $intern['totalTasks'] > 0
                                    ? round(($intern['completedTasks'] / $intern['totalTasks']) * 100)
                                    : 0;
                                ?>
                                <div class="intern-card-wrap"
                                    data-name="<?= htmlspecialchars(strtolower($intern['fullName'])) ?>">
                                    <div class="intern-card">

                                        <!-- Header -->
                                        <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap"
                                            style="gap:8px;">
                                            <div>
                                                <div class="d-flex align-items-center gap-2">
                                                    <div style="font-size:15px;font-weight:700;color:var(--brand);">
                                                        <?= htmlspecialchars($intern['fullName']) ?></div>
                                                    <span class="badge"
                                                        style="<?= $intern['loadBadge'] ?>"><?= $intern['loadStatus'] ?></span>
                                                </div>
                                                <div style="font-size:11px;color:var(--ink-3);margin-top:3px;">
                                                    <span
                                                        style="display:inline-block;font-size:10px;font-weight:600;padding:1px 8px;border-radius:99px;
                                            background:<?= $intern['type'] === 'Online' ? 'var(--green-bg)' : '#fff7ed' ?>;
                                            color:<?= $intern['type'] === 'Online' ? 'var(--green)' : '#ea580c' ?>;
                                            border:1px solid <?= $intern['type'] === 'Online' ? '#a7f3d0' : '#fed7aa' ?>;">
                                                        <?= $intern['type'] ?>
                                                    </span>
                                                    &nbsp;
                                                    <span class="badge badge-light border"><i class="bi bi-building"></i>
                                                        <?= htmlspecialchars($intern['branch']) ?></span>
                                                    &nbsp;
                                                    <i class="bi bi-box-arrow-in-right" style="color:var(--green);"></i>
                                                    In: <strong><?= $intern['pIn'] ?></strong>
                                                    &nbsp;
                                                    <i class="bi bi-box-arrow-right" style="color:var(--red);"></i>
                                                    Out: <strong><?= $intern['pOut'] ?></strong>
                                                </div>
                                            </div>
                                            <div
                                                style="width:54px;height:54px;border-radius:50%;border:3px solid <?= $sc ?>;display:flex;align-items:center;justify-content:center;flex-direction:column;flex-shrink:0;">
                                                <span
                                                    style="font-size:13px;font-weight:700;color:<?= $sc ?>;line-height:1;"><?= $intern['todayPerc'] ?>%</span>
                                                <span style="font-size:9px;color:var(--ink-3);">today</span>
                                            </div>
                                        </div>

                                        <!-- 4 stat tiles -->
                                        <div class="row mb-3" style="row-gap:8px;">
                                            <div class="col-3">
                                                <div class="intern-stat" style="background:#eff6ff;">
                                                    <div class="is-icon"><i class="bi bi-clock-fill"
                                                            style="color:var(--brand);"></i></div>
                                                    <div class="is-num" style="color:var(--brand);">
                                                        <?= $intern['manHours'] ?></div>
                                                    <div class="is-lbl" style="color:#6b9fe4;">Worked Hrs</div>
                                                    <div class="is-sub">Target: <?= $intern['actualWH'] ?></div>
                                                </div>
                                            </div>
                                            <div class="col-3">
                                                <div class="intern-stat" style="background:#f3f4f6;">
                                                    <div class="is-icon"><i class="bi bi-list-task"
                                                            style="color:var(--ink-2);"></i></div>
                                                    <div class="is-num" style="color:var(--ink);">
                                                        <?= $intern['totalTasks'] ?></div>
                                                    <div class="is-lbl" style="color:var(--ink-3);">Total Tasks</div>
                                                    <div class="is-sub">All assigned</div>
                                                </div>
                                            </div>
                                            <div class="col-3">
                                                <div class="intern-stat" style="background:var(--amber-bg);">
                                                    <div class="is-icon"><i class="bi bi-hourglass-split"
                                                            style="color:var(--amber);"></i></div>
                                                    <div class="is-num" style="color:var(--amber);">
                                                        <?= $intern['pendingTasks'] ?></div>
                                                    <div class="is-lbl" style="color:#d4a030;">Pending Tasks</div>
                                                    <div class="is-sub">Active load</div>
                                                </div>
                                            </div>
                                            <div class="col-3">
                                                <div class="intern-stat" style="background:var(--green-bg);">
                                                    <div class="is-icon"><i class="bi bi-check-circle-fill"
                                                            style="color:var(--green);"></i></div>
                                                    <div class="is-num" style="color:var(--green);">
                                                        <?= $intern['completedTasks'] ?></div>
                                                    <div class="is-lbl" style="color:#34a87a;">Completed</div>
                                                    <div class="is-sub">Finished tasks</div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Hours progress bar -->
                                        <div>
                                            <div class="d-flex justify-content-between mb-1"
                                                style="font-size:11px;color:var(--ink-3);">
                                                <span><i class="bi bi-clock mr-1"></i>Today's working hours</span>
                                                <span><?= $intern['manHours'] ?> / <?= $intern['actualWH'] ?></span>
                                            </div>
                                            <div class="prog-track">
                                                <div class="prog-fill"
                                                    style="width:<?= $intern['todayPerc'] ?>%;background:<?= $intern['todayPerc'] >= 80 ? 'var(--green)' : ($intern['todayPerc'] >= 50 ? 'var(--amber)' : 'var(--red)') ?>;">
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Task completion bar -->
                                        <div class="mt-2">
                                            <div class="d-flex justify-content-between mb-1"
                                                style="font-size:11px;color:var(--ink-3);">
                                                <span><i class="bi bi-check2-square mr-1"></i>Task completion rate</span>
                                                <span><?= $intern['completedTasks'] ?> / <?= $intern['totalTasks'] ?>
                                                    (<?= $taskPerc ?>%)</span>
                                            </div>
                                            <div class="prog-track">
                                                <div class="prog-fill"
                                                    style="width:<?= $taskPerc ?>%;background:var(--brand);"></div>
                                            </div>
                                        </div>

                                        <!-- Late marks -->
                                        <div class="mt-2 d-flex align-items-center justify-content-between"
                                            style="font-size:11px;">
                                            <span style="color:var(--ink-3);"><i class="bi bi-alarm mr-1"></i>Late marks
                                                this month:</span>
                                            <span
                                                style="font-weight:700;color:<?= $intern['lateCount'] > 0 ? 'var(--red)' : 'var(--green)' ?>;">
                                                <?= $intern['lateCount'] > 0 ? $intern['lateCount'] . ' late mark' : '✓ None' ?>
                                            </span>
                                        </div>

                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div><!-- /#internCardsContainer -->

                        <?php if (empty($internData)): ?>
                            <div class="empty-intern">
                                <i class="bi bi-people"></i>
                                No active interns found matching the selected filters.
                            </div>
                        <?php endif; ?>

                    </div><!-- /.dash-card -->

                    <?php include('../partials/_freeInternsWidget.php'); ?>

                </div>
            </div>
        </div>
    </div>

    <!-- JS -->
    <script src="../vendors/js/vendor.bundle.base.js" defer></script>
    <script src="../vendors/chart.js/Chart.min.js" defer></script>
    <script src="../js/off-canvas.js" defer></script>
    <script src="../js/hoverable-collapse.js" defer></script>
    <script src="../js/template.js" defer></script>
    <script src="../js/settings.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11" defer></script>

    <script>
        window.addEventListener('load', function () {

            /* ── Chart 1: Workforce Distribution ── */
            var ctx1 = document.getElementById('chartWorkforce');
            if (ctx1) {
                new Chart(ctx1, {
                    type: 'doughnut',
                    data: {
                        labels: ['Present', 'Absent', 'Leave', 'Tour', 'Late'],
                        datasets: [{
                            data: [<?= $present ?>, <?= $absent ?>, <?= $on_leave ?>, <?= $on_tour ?>, <?= $late ?>],
                            backgroundColor: ['#059669', '#dc2626', '#d97706', '#7c3aed', '#0891b2'],
                            borderWidth: 2, borderColor: '#fff'
                        }]
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false, cutoutPercentage: 65,
                        legend: { display: false }
                    }
                });
            }

            /* ── Chart 2: Task Health Breakdown ── */
            var ctx2 = document.getElementById('chartTaskStatus');
            if (ctx2) {
                new Chart(ctx2, {
                    type: 'doughnut',
                    data: {
                        labels: ['Finished', 'Running', 'Paused'],
                        datasets: [{
                            data: [<?= $orgFinishedTasks ?>, <?= $orgRunningTasks ?>, <?= $orgPausedTasks ?>],
                            backgroundColor: ['#059669', '#1977e1', '#d97706'],
                            borderWidth: 2, borderColor: '#fff'
                        }]
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false, cutoutPercentage: 65,
                        legend: { display: false }
                    }
                });
            }

            /* ── Chart 3: Top Projects Workload ── */
            var ctx3 = document.getElementById('chartProjectWorkload');
            if (ctx3) {
                <?php
                $pNames = [];
                $pHours = [];
                $pColors = ['#1977e1', '#059669', '#d97706', '#7c3aed', '#0891b2', '#ea580c'];
                $i = 0;
                foreach (array_slice($topProjects, 0, 5) as $p) {
                    $pNames[] = substr($p['projectName'], 0, 12) . '..';
                    $pHours[] = round((float) ($p['loggedHours'] ?? 0), 1);
                    $i++;
                }
                ?>
                new Chart(ctx3, {
                    type: 'bar',
                    data: {
                        labels: <?= json_encode($pNames) ?>,
                        datasets: [{
                            label: 'Logged Hrs',
                            data: <?= json_encode($pHours) ?>,
                            backgroundColor: ['#1977e1', '#059669', '#d97706', '#7c3aed', '#0891b2'],
                            borderRadius: 4
                        }]
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        legend: { display: false },
                        scales: {
                            xAxes: [{ gridLines: { display: false } }],
                            yAxes: [{ ticks: { beginAtZero: true } }]
                        }
                    }
                });
            }

            /* ── Chart 4: Resource Load Balance ── */
            var ctx4 = document.getElementById('chartResourceLoad');
            if (ctx4) {
                new Chart(ctx4, {
                    type: 'doughnut',
                    data: {
                        labels: ['Overloaded', 'Optimal', 'Bench / Available'],
                        datasets: [{
                            data: [<?= $overloadedCount ?>, <?= $optimalCount ?>, <?= $benchCount ?>],
                            backgroundColor: ['#dc2626', '#059669', '#1977e1'],
                            borderWidth: 2, borderColor: '#fff'
                        }]
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false, cutoutPercentage: 65,
                        legend: { display: false }
                    }
                });
            }

            /* ══ INTERN SECTION — Search + Navigation ══ */
            var allCards = Array.from(document.querySelectorAll('.intern-card-wrap'));
            var searchVal = '';
            var filteredCards = [];
            var currentIdx = 0;

            var searchInput = document.getElementById('internSearch');
            var clearBtn = document.getElementById('clearSearch');
            var prevBtn = document.getElementById('internPrev');
            var nextBtn = document.getElementById('internNext');
            var navInfo = document.getElementById('internNavInfo');
            var noResults = document.getElementById('internNoResults');
            var internNav = document.getElementById('internNav');

            function applyFilter() {
                filteredCards = allCards.filter(function (card) {
                    return searchVal === '' || card.getAttribute('data-name').indexOf(searchVal) !== -1;
                });
                allCards.forEach(function (c) { c.classList.remove('visible'); });
                if (filteredCards.length === 0) {
                    noResults.style.display = 'block';
                    internNav.style.display = 'none';
                } else {
                    noResults.style.display = 'none';
                    internNav.style.display = 'flex';
                    if (currentIdx >= filteredCards.length) currentIdx = 0;
                    showCard(currentIdx);
                }
            }

            function showCard(idx) {
                filteredCards.forEach(function (c) { c.classList.remove('visible'); });
                if (filteredCards[idx]) filteredCards[idx].classList.add('visible');
                currentIdx = idx;
                navInfo.textContent = filteredCards.length > 0 ? (idx + 1) + ' / ' + filteredCards.length : '— / —';
                prevBtn.disabled = (idx === 0);
                nextBtn.disabled = (idx >= filteredCards.length - 1);
            }

            if (searchInput) {
                searchInput.addEventListener('input', function () {
                    searchVal = this.value.trim().toLowerCase();
                    currentIdx = 0;
                    clearBtn.style.display = searchVal ? 'block' : 'none';
                    applyFilter();
                });
            }

            if (clearBtn) {
                clearBtn.addEventListener('click', function () {
                    searchInput.value = ''; searchVal = '';
                    clearBtn.style.display = 'none'; currentIdx = 0;
                    applyFilter();
                });
            }

            if (prevBtn) prevBtn.addEventListener('click', function () { if (currentIdx > 0) { currentIdx--; showCard(currentIdx); } });
            if (nextBtn) nextBtn.addEventListener('click', function () { if (currentIdx < filteredCards.length - 1) { currentIdx++; showCard(currentIdx); } });

            document.addEventListener('keydown', function (e) {
                if (document.activeElement === searchInput) return;
                if (e.key === 'ArrowLeft' && !prevBtn.disabled) { currentIdx--; showCard(currentIdx); }
                if (e.key === 'ArrowRight' && !nextBtn.disabled) { currentIdx++; showCard(currentIdx); }
            });

            applyFilter();
        });
    </script>

    <?php
    require_once('../include/sweetAlert.php');
    if (isset($_SESSION['msg'])) {
        $status = $_SESSION['status'];
        $msg = $_SESSION['msg'];
        if ($status != true) {
            toastMsg('error', $msg);
        } else {
            toastMsg('success', $msg);
        }
        unset($_SESSION['status'], $_SESSION['msg']);
    }
    ?>
    <?php include('../include/checkPasswordStatus.php'); ?>
    <?php include('../include/passwordChangeAlert.php'); ?>
</body>

</html>