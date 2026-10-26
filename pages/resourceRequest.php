<?php
session_start();
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}

// ── AJAX: batch slot validation ───────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'validate_batch_slot') {
    if (ob_get_length()) { ob_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    include('../include/db_config.php');
    require_once('availabilityEngine.php');

    $rawIds    = $_GET['internIds'] ?? [];
    $internIds = array_values(array_filter(array_map('trim', (array)$rawIds)));
    $startDate = trim($_GET['startDate'] ?? date('Y-m-d'));
    $endDate   = trim($_GET['endDate']   ?? $startDate);
    $st        = trim($_GET['allocStartTime'] ?? '');
    $et        = trim($_GET['allocEndTime']   ?? '');

    if (empty($internIds) || empty($st) || empty($et)) {
        echo json_encode(['success' => false, 'error' => 'Missing parameters']);
        exit;
    }

    $results = [];
    $allAvailable = true;

    foreach ($internIds as $iid) {
        $val = validateAllocationRequest($con, $iid, $startDate, $endDate, $st, $et, null, false);
        $payload = buildAvailabilityPayload($con, $iid, $startDate, $startDate, $endDate);

        $qName = mysqli_query($con, "SELECT fullName FROM signup WHERE internId='$iid' OR userId='$iid' LIMIT 1");
        $rName = mysqli_fetch_assoc($qName);
        $fullName = $rName['fullName'] ?? $iid;

        $isOk = $val['valid'];
        if (!$isOk) $allAvailable = false;

        $results[] = [
            'internId' => $iid,
            'fullName' => $fullName,
            'valid'    => $isOk,
            'errors'   => $val['errors'] ?? [],
            'freeSlots'=> $payload['freeSlots'] ?? []
        ];
    }

    echo json_encode([
        'success'      => true,
        'allAvailable' => $allAvailable,
        'results'      => $results
    ]);
    exit;
}

// ── AJAX: fetch free interns for date range ─────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'fetch_free_interns') {
    if (ob_get_length()) { ob_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    include('../include/db_config.php');
    require_once('availabilityEngine.php');

    $startDate = trim($_GET['startDate'] ?? date('Y-m-d'));
    $endDate   = trim($_GET['endDate']   ?? $startDate);
    $st        = trim($_GET['allocStartTime'] ?? '');
    $et        = trim($_GET['allocEndTime']   ?? '');

    $today = date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) $startDate = $today;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate))   $endDate = $startDate;
    if ($endDate < $startDate) $endDate = $startDate;

    $internResult = mysqli_query(
        $con,
        "SELECT COALESCE(NULLIF(TRIM(s.internId),''), s.userId) AS internId, s.fullName,
                COALESCE(NULLIF(i.role,''), NULLIF(s.designation,''), NULLIF(s.userRole,''), 'Intern') AS rawRole,
                COALESCE(TIME_FORMAT(s.expectedPunchIn,'%H:%i'),'09:00')  AS workStartTime,
                COALESCE(TIME_FORMAT(s.expectedPunchOut,'%H:%i'),'17:00') AS workEndTime
         FROM   signup s
         LEFT   JOIN interns i ON (s.internId COLLATE utf8mb4_unicode_ci = i.internId COLLATE utf8mb4_unicode_ci OR s.userId COLLATE utf8mb4_unicode_ci = i.internId COLLATE utf8mb4_unicode_ci)
         WHERE  (TRIM(LOWER(s.status)) = 'active' OR s.status = '1' OR s.status = 1)
           AND  (s.regstatus = 'Approved' OR s.regstatus IS NULL OR s.regstatus = '')
           AND  (s.designation IS NULL OR LOWER(s.designation) NOT IN ('admin', 'hr', 'superadmin', 'developer', 'md'))
           AND  (s.userRole IS NULL OR LOWER(s.userRole) NOT IN ('admin', 'hr', 'superadmin', 'md'))
         ORDER  BY s.fullName ASC"
    );

    $allList = [];
    if ($internResult) {
        while ($r = mysqli_fetch_assoc($internResult)) {
            $iid = $r['internId'];
            $ws = $r['workStartTime'];
            $we = $r['workEndTime'];

            $rawRole = trim($r['rawRole'] ?? '');
            if (strtolower($rawRole) === 'seniorintern' || strtolower($rawRole) === 'senior_intern') {
                $roleLabel = 'Senior Intern';
            } elseif (strtolower($rawRole) === 'juniorintern' || strtolower($rawRole) === 'junior_intern') {
                $roleLabel = 'Junior Intern';
            } else {
                $roleLabel = !empty($rawRole) ? ucwords(str_replace('_', ' ', $rawRole)) : 'Intern';
            }

            $dailyRange = calculateAvailabilityForDateRange($con, $iid, $startDate, $endDate);
            $summary    = summariseDateRange($dailyRange);

            $minFreeMins = $summary['minFreeMinutes'];
            $hasBlocked  = !empty($summary['blockedDates']);

            $allList[] = [
                'internId'    => $iid,
                'fullName'    => $r['fullName'],
                'role'        => $roleLabel,
                'ws'          => $ws,
                'we'          => $we,
                'minFreeMins' => $minFreeMins,
                'isFull'      => ($minFreeMins <= 0 || $hasBlocked),
                'hasBlocked'  => $hasBlocked
            ];
        }
    }

    echo json_encode([
        'success'   => true,
        'startDate' => $startDate,
        'endDate'   => $endDate,
        'interns'   => $allList
    ]);
    exit;
}

// ── AJAX: availability request (self-handled, no extra file needed) ───────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'availability') {
    if (ob_get_length()) { ob_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    include('../include/db_config.php');
    require_once('availabilityEngine.php');

    $internId = trim($_GET['internId'] ?? '');
    $startDate = trim($_GET['startDate'] ?? '');
    $endDate = trim($_GET['endDate'] ?? '');
    $forDate = trim($_GET['forDate'] ?? $startDate);
    $today = date('Y-m-d');

    if ($internId === '') {
        echo json_encode(['error' => 'internId required']);
        exit;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate))
        $startDate = $today;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate))
        $endDate = $startDate;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $forDate))
        $forDate = $startDate;
    if ($endDate < $startDate)
        $endDate = $startDate;
    // cap at 365 days to prevent heavy queries
    if ((strtotime($endDate) - strtotime($startDate)) / 86400 > 365)
        $endDate = date('Y-m-d', strtotime($startDate) + 365 * 86400);

    echo json_encode(
        buildAvailabilityPayload($con, $internId, $forDate, $startDate, $endDate),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

// Consume flash messages
$flashStatus = null;
$flashMsg = null;
if (isset($_SESSION['flash_page']) && $_SESSION['flash_page'] === 'resourceRequest') {
    $flashStatus = $_SESSION['status'] ?? null;
    $flashMsg = $_SESSION['msg'] ?? null;
}
unset($_SESSION['status'], $_SESSION['msg'], $_SESSION['flash_page']);

include('../include/db_config.php');
require_once('availabilityEngine.php');

$userId   = $_SESSION['userId'];
$userRow  = mysqli_fetch_assoc(mysqli_query($con, "
    SELECT userId, internId, username, fullName 
    FROM signup 
    WHERE userId = '" . esc($con, $userId) . "' 
       OR username = '" . esc($con, $userId) . "' 
       OR internId = '" . esc($con, $userId) . "' 
    LIMIT 1
"));

$userName     = $userRow ? htmlspecialchars($userRow['fullName']) : 'Unknown';
$userUserId   = $userRow['userId']   ?? $userId;
$userInternId = $userRow['internId'] ?? '';
$userUsername = $userRow['username'] ?? '';
$userFullName = $userRow['fullName'] ?? '';

$matchIds = array_filter(array_unique([
    (string)$userId,
    (string)$userUserId,
    (string)$userInternId,
    (string)$userUsername,
    (string)$userFullName
]));

$whereConditions = [];
foreach ($matchIds as $mid) {
    if (trim($mid) !== '') {
        $escMid = esc($con, trim($mid));
        $whereConditions[] = "r.requestedBy COLLATE utf8mb4_unicode_ci = '$escMid'";
        $whereConditions[] = "LOWER(TRIM(r.requestedBy)) = LOWER('$escMid')";
    }
}

$whereClause = !empty($whereConditions) ? '(' . implode(' OR ', $whereConditions) . ')' : "r.requestedBy = '" . esc($con, $userId) . "'";

// ── Project list ─────────────────────────────────────────────────────────────
$projectList = [];
$pq = mysqli_query($con, "SELECT DISTINCT projectId, projectName, client FROM projectMaster WHERE status=1 ORDER BY projectName ASC");
if ($pq) {
    while ($row = mysqli_fetch_assoc($pq))
        $projectList[] = $row;
}

$today = date('Y-m-d');
$preInternId = isset($_GET['internId']) ? trim($_GET['internId']) : '';

// ── Intern list (basic data only — availability fetched via AJAX) ─────────────
$internResult = mysqli_query(
    $con,
    "SELECT COALESCE(NULLIF(TRIM(s.internId),''), s.userId) AS internId, s.fullName,
            COALESCE(NULLIF(i.role,''), NULLIF(s.designation,''), NULLIF(s.userRole,''), 'Intern') AS rawRole,
            COALESCE(TIME_FORMAT(s.expectedPunchIn,'%H:%i'),'09:00')  AS workStartTime,
            COALESCE(TIME_FORMAT(s.expectedPunchOut,'%H:%i'),'17:00') AS workEndTime
     FROM   signup s
     LEFT   JOIN interns i ON (s.internId COLLATE utf8mb4_unicode_ci = i.internId COLLATE utf8mb4_unicode_ci OR s.userId COLLATE utf8mb4_unicode_ci = i.internId COLLATE utf8mb4_unicode_ci)
     WHERE  (TRIM(LOWER(s.status)) = 'active' OR s.status = '1' OR s.status = 1)
       AND  (s.regstatus = 'Approved' OR s.regstatus IS NULL OR s.regstatus = '')
       AND  (s.designation IS NULL OR LOWER(s.designation) NOT IN ('admin', 'hr', 'superadmin', 'developer', 'md'))
       AND  (s.userRole IS NULL OR LOWER(s.userRole) NOT IN ('admin', 'hr', 'superadmin', 'md'))
     ORDER  BY s.fullName ASC"
);
if (!$internResult)
    die("Intern query failed: " . mysqli_error($con));

$internList = [];
$internJsonMap = [];   // lightweight — just work-window meta; live data via AJAX

while ($r = mysqli_fetch_assoc($internResult)) {
    $iid = $r['internId'];

    // Today's snapshot for the dropdown label (fast — no range calc)
    $snap = calculateAvailabilityForDate($con, $iid, $today);
    $freeMins = $snap['available'] ? $snap['freeMinutes'] : 0;
    $isFull = !$snap['available'] || $freeMins === 0;

    // Showcase ONLY free interns (skip fully assigned interns)
    if ($isFull || $freeMins <= 0) {
        if ($preInternId === '' || $iid !== $preInternId) {
            continue;
        }
    }

    $ws = $r['workStartTime'];
    $we = $r['workEndTime'];
    $wsMins = timeToMinutes($ws);
    $weMins = timeToMinutes($we);

    $rawRole = trim($r['rawRole'] ?? '');
    if (strtolower($rawRole) === 'seniorintern' || strtolower($rawRole) === 'senior_intern') {
        $roleLabel = 'Senior Intern';
    } elseif (strtolower($rawRole) === 'juniorintern' || strtolower($rawRole) === 'junior_intern') {
        $roleLabel = 'Junior Intern';
    } else {
        $roleLabel = !empty($rawRole) ? ucwords(str_replace('_', ' ', $rawRole)) : 'Intern';
    }

    $internList[] = [
        'internId' => $iid,
        'fullName' => $r['fullName'],
        'role' => $roleLabel,
        'freeMins' => $freeMins,
        'isFull' => $isFull,
        'ws' => $ws,
        'we' => $we,
    ];

    $internJsonMap[$iid] = [
        'workStartTime' => $ws,
        'workEndTime' => $we,
        'workStart12' => to12hr($ws),
        'workEnd12' => to12hr($we),
        'workStartMins' => $wsMins,
        'workEndMins' => $weMins,
        'totalMins' => max(0, $weMins - $wsMins),
    ];
}


$selectedIntern = null;
if ($preInternId !== '') {
    foreach ($internList as $il) {
        if ($il['internId'] === $preInternId) {
            $selectedIntern = $il;
            break;
        }
    }
}

// ── User's own request history ───────────────────────────────────────────────
$myRequestsQuery = "
    SELECT 
        r.id,
        r.internId,
        COALESCE(i.fullName, 
            (SELECT si.fullName FROM signup si WHERE (si.internId COLLATE utf8mb4_unicode_ci = r.internId COLLATE utf8mb4_unicode_ci OR si.userId COLLATE utf8mb4_unicode_ci = r.internId COLLATE utf8mb4_unicode_ci) LIMIT 1), 
            r.internId
        ) AS internName,
        r.projectName,
        r.clientName,
        r.startDate,
        r.endDate,
        r.allocStartTime,
        r.allocEndTime,
        r.dailyHours,
        r.status,
        r.createdAt
    FROM resourceRequests r
    LEFT JOIN interns i ON r.internId COLLATE utf8mb4_unicode_ci = i.internId COLLATE utf8mb4_unicode_ci
    WHERE $whereClause
    ORDER BY r.createdAt DESC
";
$myRequestsResult = mysqli_query($con, $myRequestsQuery);

// ── KPI Analytics (user-scoped) ──────────────────────────────────────────────
$kpiQ = mysqli_query($con, "
    SELECT
        COUNT(*) AS totalReq,
        SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) AS approvedReq,
        SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) AS rejectedReq,
        SUM(CASE WHEN status = 'Pending'  OR status IS NULL OR status = '' THEN 1 ELSE 0 END) AS pendingReq,
        COALESCE(SUM(dailyHours * DATEDIFF(
            LEAST(endDate, CURDATE()),
            GREATEST(startDate, DATE_SUB(CURDATE(), INTERVAL 30 DAY))
        )), 0) AS hoursThisMonth
    FROM resourceRequests r
    WHERE $whereClause
");
$kpi = $kpiQ ? mysqli_fetch_assoc($kpiQ) : [];
$kpiTotal    = (int)($kpi['totalReq']    ?? 0);
$kpiApproved = (int)($kpi['approvedReq'] ?? 0);
$kpiRejected = (int)($kpi['rejectedReq'] ?? 0);
$kpiPending  = (int)($kpi['pendingReq']  ?? 0);

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <title>Resource Request</title>
    <?php include('../partials/_links.php'); ?>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.17.0/xlsx.full.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.4.0/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.23/jspdf.plugin.autotable.min.js"></script>

    <style>
        .required {
            color: red;
            font-weight: bold;
        }
    

        /* ── Select2 & Form Control Styling ──────────────────────────────────────── */
        .select2-container .select2-selection--single {
            height: 42px !important;
            border: 1px solid #ced4da !important;
            border-radius: 8px !important;
            padding: 0 36px 0 14px !important;
            background-color: #fff !important;
            display: flex !important;
            align-items: center !important;
            box-shadow: none !important;
        }

        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 40px !important;
            padding-left: 0 !important;
            color: #495057 !important;
            font-size: 0.875rem !important;
            font-weight: 500 !important;
        }

        .select2-container--default .select2-selection--single .select2-selection__placeholder {
            color: #6c757d !important;
        }

        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 40px !important;
            right: 12px !important;
            display: flex !important;
            align-items: center !important;
        }

        .select2-container--default.select2-container--focus .select2-selection--single,
        .select2-container--default.select2-container--open .select2-selection--single {
            border-color: #4b49ac !important;
            box-shadow: 0 0 0 3px rgba(75, 73, 172, 0.15) !important;
        }

        .select2-dropdown {
            border: 1px solid #ced4da !important;
            border-radius: 8px !important;
            font-size: 0.875rem !important;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12) !important;
        }

        .select2-container--default .select2-results__option--highlighted[aria-selected] {
            background-color: #4b49ac !important;
            color: #ffffff !important;
        }

        /* ── Select2 Multi-Select Choice Pills Styling ── */
        .select2-container--default .select2-selection--multiple {
            min-height: 44px !important;
            border: 1px solid #ced4da !important;
            border-radius: 8px !important;
            padding: 3px 8px !important;
            background-color: #fff !important;
        }

        .select2-container--default.select2-container--focus .select2-selection--multiple {
            border-color: #4b49ac !important;
            box-shadow: 0 0 0 3px rgba(75, 73, 172, 0.15) !important;
        }

        .select2-container--default .select2-selection--multiple .select2-selection__choice {
            background-color: #4b49ac !important;
            color: #ffffff !important;
            border: none !important;
            border-radius: 18px !important;
            padding: 3px 12px 4px 26px !important;
            font-size: 0.82rem !important;
            font-weight: 600 !important;
            position: relative !important;
            box-shadow: 0 2px 5px rgba(75, 73, 172, 0.2) !important;
            margin: 4px 4px 4px 0 !important;
        }

        .select2-container--default .select2-selection--multiple .select2-selection__choice__remove {
            color: #ffffff !important;
            border: none !important;
            position: absolute !important;
            left: 8px !important;
            top: 50% !important;
            transform: translateY(-50%) !important;
            font-size: 0.95rem !important;
            font-weight: bold !important;
            opacity: 0.85 !important;
            transition: opacity 0.15s !important;
        }

        .select2-container--default .select2-selection--multiple .select2-selection__choice__remove:hover {
            opacity: 1 !important;
            background: transparent !important;
            color: #ffc107 !important;
        }

        .form-control {
            height: 42px !important;
            border-radius: 8px !important;
            border: 1px solid #ced4da !important;
            font-size: 0.875rem !important;
            padding: 8px 14px !important;
        }

        .form-control:focus {
            border-color: #4b49ac !important;
            box-shadow: 0 0 0 3px rgba(75, 73, 172, 0.15) !important;
        }

        /* ── Intern info panel (unchanged classes) ──────────────────────────────── */
        .intern-info-panel {
            background: #f8f8ff;
            border: 1px solid #e0dfff;
            border-radius: 10px;
            padding: 16px;
            margin-top: 10px;
            display: none;
        }

        .intern-info-panel.show {
            display: block;
        }

        .intern-info-panel.warn {
            background: #fff8f0;
            border-color: #ffc078;
        }

        .intern-info-panel.danger {
            background: #fdf0f0;
            border-color: #f5c2c7;
        }

        .work-window-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #4b49ac;
            color: #fff;
            border-radius: 20px;
            padding: 4px 12px;
            font-size: 0.78rem;
            font-weight: 600;
            margin-bottom: 10px;
        }

        /* ── Availability bar ───────────────────────────────────────────────────── */
        .avail-bar-wrap {
            margin: 8px 0;
        }

        .avail-bar-bg {
            height: 8px;
            background: #e9ecef;
            border-radius: 99px;
            overflow: hidden;
        }

        .avail-bar-fill {
            height: 100%;
            border-radius: 99px;
            transition: width .3s;
        }

        .avail-stats {
            display: flex;
            justify-content: space-between;
            font-size: 0.72rem;
            margin-top: 3px;
        }

        /* ── Timeline ───────────────────────────────────────────────────────────── */
        .timeline-container {
            margin: 10px 0;
        }

        .timeline-label {
            font-size: 0.74rem;
            font-weight: 700;
            color: #4b49ac;
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .timeline-bar {
            position: relative;
            height: 24px;
            background: #e8e7ff;
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 4px;
        }

        .timeline-slot-used {
            position: absolute;
            height: 100%;
            background: #dc3545;
            opacity: 0.75;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.58rem;
            font-weight: 700;
            color: #fff;
            overflow: hidden;
            white-space: nowrap;
            padding: 0 4px;
        }

        .timeline-slot-pending {
            position: absolute;
            height: 100%;
            background: #fd7e14;
            opacity: 0.80;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.58rem;
            font-weight: 700;
            color: #fff;
            overflow: hidden;
            white-space: nowrap;
            padding: 0 4px;
        }

        .timeline-slot-selected {
            position: absolute;
            height: 100%;
            background: #4b49ac;
            border-radius: 12px;
            opacity: 0.9;
            transition: left .2s, width .2s;
        }

        .timeline-ticks {
            display: flex;
            justify-content: space-between;
            font-size: 0.65rem;
            color: #9090a8;
        }

        /* ── Free slots ─────────────────────────────────────────────────────────── */
        .free-slots-list {
            margin-top: 8px;
        }

        .free-slot-tag {
            display: inline-block;
            background: #d4edda;
            color: #155724;
            border-radius: 12px;
            padding: 2px 10px;
            font-size: 0.72rem;
            font-weight: 600;
            margin: 2px 3px;
            cursor: pointer;
            border: 1px solid #a8d9b7;
            transition: all .13s;
        }

        .free-slot-tag:hover {
            background: #c3e6cb;
        }

        /* ── Range status strip ─────────────────────────────────────────────────── */
        .range-status-strip {
            margin-top: 8px;
            font-size: 0.75rem;
            border-radius: 6px;
            padding: 6px 10px;
            display: none;
        }

        .range-status-strip.show {
            display: block;
        }

        .range-status-strip.ok {
            background: #d4edda;
            color: #155724;
            border: 1px solid #a8d9b7;
        }

        .range-status-strip.warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffc107;
        }

        .range-status-strip.danger {
            background: #fdf0f0;
            color: #721c24;
            border: 1px solid #f5c2c7;
        }

        /* ── Duration / conflict ────────────────────────────────────────────────── */
        .duration-display {
            background: #e8e7ff;
            border-radius: 6px;
            padding: 6px 10px;
            font-size: 0.82rem;
            font-weight: 600;
            color: #4b49ac;
            margin-top: 6px;
            display: none;
        }

        .duration-display.show {
            display: block;
        }

        .conflict-warning {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 6px;
            padding: 8px 10px;
            font-size: 0.78rem;
            color: #856404;
            margin-top: 6px;
            display: none;
        }

        .conflict-warning.show {
            display: block;
        }

        /* ── Blocked-date badge ─────────────────────────────────────────────────── */
        .blocked-badge {
            display: inline-block;
            background: #fde8e8;
            color: #842029;
            border-radius: 12px;
            padding: 2px 9px;
            font-size: 0.70rem;
            font-weight: 600;
            margin: 2px 3px;
            border: 1px solid #f5c2c7;
        }

        .time-input-note {
            font-size: 0.72rem;
            color: #6c757d;
            margin-top: 4px;
        }

        .time-input-note.window-hint {
            color: #4b49ac;
            font-weight: 600;
        }

        /* ── KPI Analytics Cards ─────────────────────────────────────────────── */
        .kpi-card {
            background: #fff;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            padding: 18px 20px;
            transition: transform 0.2s, box-shadow 0.2s;
            height: 100%;
        }
        .kpi-card:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(0,0,0,0.07); }
        .kpi-icon {
            width: 48px; height: 48px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.25rem; flex-shrink: 0;
        }
        .kpi-val { font-size: 1.7rem; font-weight: 800; color: #0f172a; line-height: 1.1; margin-top: 4px; }
        .kpi-lbl { font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; }
        .kpi-sub { font-size: 0.7rem; color: #94a3b8; margin-top: 2px; }

        /* ── Loading spinner ────────────────────────────────────────────────────── */
        .avail-loading {
            display: none;
            font-size: 0.75rem;
            color: #6c757d;
            margin-top: 6px;
        }

        .avail-loading.show {
            display: block;
        }

        /* ── Buttons (unchanged) ────────────────────────────────────────────────── */
        .btn-submit-rr {
            background-color: #4b49ac;
            border-color: #4b49ac;
            color: #fff;
            border-radius: 6px;
            padding: 10px 28px;
            font-size: 0.875rem;
            font-weight: 500;
            transition: background-color 0.2s;
        }

        .btn-submit-rr:hover {
            background-color: #3b3a8f;
            border-color: #3b3a8f;
            color: #fff;
        }

        .btn-cancel-rr {
            background-color: #2d2d2d;
            border-color: #2d2d2d;
            color: #fff;
            border-radius: 6px;
            padding: 10px 28px;
            font-size: 0.875rem;
            font-weight: 500;
        }

        .btn-cancel-rr:hover {
            background-color: #1a1a1a;
            color: #fff;
        }
    </style>
</head>

<body>
    <div class="container-scroller">
        <?php include('../partials/_navbar.php'); ?>
        <div class="container-fluid page-body-wrapper">
            <?php include('../partials/_sidebar.php');
            include('../partials/_settings-panel.php'); ?>
            <div class="main-panel">
                <div class="content-wrapper" style="background:#f4f6fb !important;">

                    <!-- ── Page Header ──────────────────────────────────────────────────── -->
                    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
                        <div>
                            <h3 class="fw-bold text-dark mb-1" style="font-size:1.35rem;">
                                <i class="fa-solid fa-people-arrows text-primary me-2"></i> Resource Requests
                            </h3>
                            <p class="text-muted small mb-0">Request interns for your projects and track approval status.</p>
                        </div>
                        <button type="button" class="btn btn-primary" id="toggleFormBtn"
                            style="background-color:#4b49ac;border-color:#4b49ac;border-radius:8px;padding:9px 22px;font-size:0.875rem;font-weight:600;">
                            <i class="fa-solid fa-plus me-1"></i> New Request
                        </button>
                    </div>

                    <!-- ── KPI Analytics Cards ──────────────────────────────────────────── -->
                    <div class="row mb-4" style="gap:0;">
                        <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-lbl">Total Requests</div>
                                    <div class="kpi-val"><?= $kpiTotal ?></div>
                                    <div class="kpi-sub">All time submitted</div>
                                </div>
                                <div class="kpi-icon" style="background:#EEF2FF; color:#4B49AC;">
                                    <i class="fa-solid fa-file-lines"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-lbl">Approved</div>
                                    <div class="kpi-val text-success"><?= $kpiApproved ?></div>
                                    <div class="kpi-sub">Granted requests</div>
                                </div>
                                <div class="kpi-icon" style="background:#E6F4EA; color:#1E8E3E;">
                                    <i class="fa-solid fa-circle-check"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-lbl">Pending Review</div>
                                    <div class="kpi-val" style="color:#B06000;"><?= $kpiPending ?></div>
                                    <div class="kpi-sub">Awaiting approval</div>
                                </div>
                                <div class="kpi-icon" style="background:#FEF7E0; color:#B06000;">
                                    <i class="fa-solid fa-clock"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-lbl">Rejected</div>
                                    <div class="kpi-val text-danger"><?= $kpiRejected ?></div>
                                    <div class="kpi-sub">Declined requests</div>
                                </div>
                                <div class="kpi-icon" style="background:#FEE2E2; color:#991B1B;">
                                    <i class="fa-solid fa-circle-xmark"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── SECTION 1: My Resource Requests Table ──────────────────────── -->
                    <div class="row mb-4">
                        <div class="col-12 grid-margin stretch-card">
                            <div class="card">
                                <div class="card-body">

                                    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                                        <div>
                                            <h4 class="card-title mb-0">
                                                <i class="fa-solid fa-list-check me-2 text-primary"></i> My Resource Requests
                                            </h4>
                                            <small class="text-muted">All resource requests submitted by you and their current approval status.</small>
                                        </div>
                                        <div class="d-flex gap-2">
                                            <button class="btn btn-success btn-sm text-white px-3" style="border-radius:6px;" onclick="exportRRExcel()">
                                                <i class="fa-solid fa-file-excel me-1"></i> Excel
                                            </button>
                                            <button class="btn btn-danger btn-sm text-white px-3" style="border-radius:6px;" onclick="exportRRPDF()">
                                                <i class="fa-solid fa-file-pdf me-1"></i> PDF
                                            </button>
                                        </div>
                                    </div>


            <div class="table-responsive">
                <table class="table table-bordered table-hover" id="myRequestsTable" style="width:100%;">
                    <thead class="table-dark">
                        <tr>
                            <th style="width:4%">#</th>
                            <th>Intern Name</th>
                            <th>Project Name</th>
                            <th>Client</th>
                            <th>Start Date</th>
                            <th>End Date</th>
                            <th>Time Slot</th>
                            <th>Hrs/Day</th>
                            <th>Submitted On</th>
                            <th>Status</th>
                            <th class="text-center" style="width:8%">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    if ($myRequestsResult && mysqli_num_rows($myRequestsResult) > 0):
                        $rSerial = 1;
                        while ($rRow = mysqli_fetch_assoc($myRequestsResult)):
                            $rInternName  = htmlspecialchars((string)($rRow['internName'] ?? ''));
                            $rProjectName = htmlspecialchars((string)($rRow['projectName'] ?? ''));
                            $rClientName  = htmlspecialchars((string)($rRow['clientName'] ?? ''));
                            $rStartDate   = !empty($rRow['startDate']) ? date('d M Y', strtotime($rRow['startDate'])) : '—';
                            $rEndDate     = !empty($rRow['endDate']) ? date('d M Y', strtotime($rRow['endDate'])) : '—';
                            $rTimeSlot    = (!empty($rRow['allocStartTime']) && !empty($rRow['allocEndTime']))
                                ? to12hr(substr($rRow['allocStartTime'], 0, 5)) . ' – ' . to12hr(substr($rRow['allocEndTime'], 0, 5))
                                : 'Full Day';
                            $rDailyHours  = (float)($rRow['dailyHours'] ?? 0);
                            $rSubmittedOn = !empty($rRow['createdAt']) ? date('d M Y, h:i A', strtotime($rRow['createdAt'])) : '—';
                            $rStatus      = $rRow['status'] ?? 'Pending';
                            $badgeCls     = $rStatus === 'Approved' ? 'badge-success'
                                          : ($rStatus === 'Rejected' ? 'badge-danger' : 'badge-warning');
                            $iconCls      = $rStatus === 'Approved' ? 'fa-check-circle'
                                          : ($rStatus === 'Rejected' ? 'fa-times-circle' : 'fa-clock-o');
                    ?>
                        <tr>
                            <td><?php echo $rSerial++; ?></td>
                            <td><strong><?php echo $rInternName; ?></strong></td>
                            <td><?php echo $rProjectName; ?></td>
                            <td><?php echo $rClientName; ?></td>
                            <td><?php echo $rStartDate; ?></td>
                            <td><?php echo $rEndDate; ?></td>
                            <td><small style="background:#e8e7ff;color:#4b49ac;border-radius:10px;padding:2px 8px;font-weight:600;"><?php echo $rTimeSlot; ?></small></td>
                            <td><?php echo $rDailyHours; ?> hrs</td>
                            <td style="white-space:nowrap;"><?php echo $rSubmittedOn; ?></td>
                            <td>
                                <span class="badge <?php echo $badgeCls; ?>">
                                    <i class="fa <?php echo $iconCls; ?> me-1"></i><?php echo htmlspecialchars((string)$rStatus); ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <?php if ($rStatus === 'Pending'): ?>
                                    <div class="d-flex align-items-center justify-content-center gap-1">
                                        <button type="button" class="btn btn-outline-primary btn-sm py-1 px-2 text-nowrap btn-edit-my-req"
                                                data-reqid="<?php echo (int)$rRow['id']; ?>"
                                                data-internid="<?php echo htmlspecialchars((string)$rRow['internId']); ?>"
                                                data-internname="<?php echo htmlspecialchars((string)$rInternName); ?>"
                                                data-project="<?php echo htmlspecialchars((string)$rRow['projectName']); ?>"
                                                data-client="<?php echo htmlspecialchars((string)$rRow['clientName']); ?>"
                                                data-sdate="<?php echo htmlspecialchars((string)$rRow['startDate']); ?>"
                                                data-edate="<?php echo htmlspecialchars((string)$rRow['endDate']); ?>"
                                                data-st="<?php echo !empty($rRow['allocStartTime']) ? substr($rRow['allocStartTime'], 0, 5) : '09:00'; ?>"
                                                data-et="<?php echo !empty($rRow['allocEndTime'])   ? substr($rRow['allocEndTime'], 0, 5)   : '17:00'; ?>"
                                                title="Edit Request">
                                            <i class="fa fa-pencil me-1"></i> Edit
                                        </button>
                                        <button type="button" class="btn btn-outline-danger btn-sm py-1 px-2 text-nowrap" onclick="deleteRequest(<?php echo (int)$rRow['id']; ?>)" title="Delete Request">
                                            <i class="fa fa-trash me-1"></i> Delete
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; endif; ?>
                    </tbody>
                </table>
            </div>


                    <!-- ── SECTION 2: Create New Request Form (collapsible) ───────────── -->
                    <div id="requestFormCard" style="display:<?php echo ($preInternId !== '') ? 'block' : 'none'; ?>;">
                        <div class="row">
                            <div class="col-12 grid-margin stretch-card">
                                <div class="card" style="border:2px solid #4b49ac;">
                                    <div class="card-body">

                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                            <h4 class="card-title text-primary mb-0">
                                                <i class="fa fa-paper-plane me-1"></i> Create New Resource Request
                                            </h4>
                                            <button type="button" class="btn btn-sm btn-outline-secondary"
                                                id="collapseFormBtn">
                                                <i class="fa fa-chevron-up"></i> Collapse
                                            </button>
                                        </div>
                                        <p class="text-muted"
                                            style="font-size:0.85rem;margin-top:4px;margin-bottom:16px;">
                                            Select a project and intern to see their live availability before
                                            submitting.
                                            <strong style="color:#4b49ac;">Requesting as:
                                                <?php echo $userName; ?></strong>
                                        </p>

                                        <form class="forms-sample" method="POST" action="resourceRequestProcess.php">
                                            <div class="row">

                                                <!-- ── Left column ────────────────────────────────────────── -->
                                                <div class="col-md-7">

                                                    <!-- Project -->
                                                    <div class="form-group">
                                                        <label for="projectName">Project Name <span
                                                                class="required">*</span></label>
                                                        <select class="form-control js-project-select"
                                                            name="projectName" id="projectName" required>
                                                            <option value="" disabled selected>-- Select Project --
                                                            </option>
                                                            <?php foreach ($projectList as $proj): ?>
                                                                <option
                                                                    value="<?php echo htmlspecialchars($proj['projectName'], ENT_QUOTES); ?>"
                                                                    data-client="<?php echo htmlspecialchars($proj['client'], ENT_QUOTES); ?>">
                                                                    <?php echo htmlspecialchars($proj['projectName'], ENT_QUOTES); ?>
                                                                    <?php if (!empty($proj['client'])): ?> — <?php echo htmlspecialchars($proj['client'], ENT_QUOTES); ?><?php endif; ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>

                                                    <!-- Client -->
                                                    <div class="form-group">
                                                        <label for="clientName">Client Name <span
                                                                class="required">*</span></label>
                                                        <input type="text" class="form-control" name="clientName"
                                                            id="clientName"
                                                            placeholder="Select a project to auto-fill client name"
                                                            required readonly
                                                            style="background-color:#e9ecef;cursor:not-allowed;">
                                                    </div>

                                                    <!-- Dates (Step 1: Choose Date Range First) -->
                                                    <div class="row">
                                                        <div class="col-6">
                                                            <div class="form-group">
                                                                <label for="startDate">Start Date <span
                                                                        class="required">*</span></label>
                                                                <input type="date" class="form-control" name="startDate"
                                                                    id="startDate" value="<?php echo $today; ?>" min="<?php echo $today; ?>" required>
                                                            </div>
                                                        </div>
                                                        <div class="col-6">
                                                            <div class="form-group">
                                                                <label for="endDate">End Date <span
                                                                        class="required">*</span></label>
                                                                <input type="date" class="form-control" name="endDate"
                                                                    id="endDate" value="<?php echo $today; ?>" min="<?php echo $today; ?>" required>
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <!-- Intern select -->
                                                    <div class="form-group">
                                                        <label for="internId">Select Intern(s) <span
                                                                class="required">*</span>
                                                            <small class="text-muted ms-2" id="intern-filter-status-badge" style="font-weight:600;color:#4b49ac !important;">
                                                                <i class="fa fa-filter me-1"></i> Dynamically filtered by Date Range
                                                            </small>
                                                        </label>
                                                        <select class="form-control js-intern-select" name="internId[]"
                                                            id="internId" multiple required data-placeholder="-- Select Intern(s) --">
                                                            <?php foreach ($internList as $intern):
                                                                $iid = htmlspecialchars($intern['internId']);
                                                                $name = htmlspecialchars($intern['fullName']);
                                                                $role = htmlspecialchars($intern['role']);
                                                                $sel = ($iid === htmlspecialchars($preInternId)) ? 'selected' : '';
                                                                $avail = $intern['isFull']
                                                                    ? ' — Fully Assigned Today'
                                                                    : ' — ' . formatHours($intern['freeMins']) . ' free today'
                                                                    . ' · ' . to12hr($intern['ws']) . '–' . to12hr($intern['we']);
                                                                ?>
                                                                <option value="<?php echo $iid; ?>"
                                                                        data-ws="<?php echo htmlspecialchars($intern['ws']); ?>"
                                                                        data-we="<?php echo htmlspecialchars($intern['we']); ?>"
                                                                        data-freemins="<?php echo (int)$intern['freeMins']; ?>"
                                                                        <?php echo $sel; ?>>
                                                                    <?php echo $name . ' (' . $role . ')' . $avail; ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <small class="form-text text-muted" id="intern-timing-filter-hint" style="display:none;margin-top:6px;font-weight:600;color:#4b49ac !important;">
                                                            <i class="fa fa-info-circle me-1"></i> Filtering interns matching shift <span id="hint-shift-window"></span> (<span id="hint-free-hours"></span> free). Interns with different timings must be requested separately.
                                                        </small>

                                                        <!-- ── Availability panel ──────────────────────────── -->
                                                        <div class="avail-loading" id="avail-loading">
                                                            <i class="fa fa-spinner fa-spin"></i> Loading availability…
                                                        </div>

                                                        <div class="intern-info-panel" id="intern-info-panel">
                                                            <div class="work-window-badge" id="work-window-badge">
                                                                <i class="fa fa-clock-o"></i>
                                                                <span id="work-window-text">—</span>
                                                            </div>

                                                            <!-- Availability bar -->
                                                            <div class="avail-bar-wrap">
                                                                <div class="avail-bar-bg">
                                                                    <div class="avail-bar-fill" id="avail-bar-fill"
                                                                        style="width:0%;background:#28a745;"></div>
                                                                </div>
                                                                <div class="avail-stats">
                                                                    <span id="avail-used-label"
                                                                        style="color:#dc3545;"></span>
                                                                    <span id="avail-free-label"
                                                                        style="color:#28a745;font-weight:600;"></span>
                                                                </div>
                                                            </div>

                                                            <!-- Timeline -->
                                                            <div class="timeline-container">
                                                                <div
                                                                    class="d-flex justify-content-between align-items-center mb-1 flex-wrap">
                                                                    <div class="timeline-label mb-0"
                                                                        id="timeline-heading">Schedule</div>
                                                                    <div style="font-size:0.72rem;color:#6c757d;">
                                                                        <span class="mr-2"><i class="fa fa-square"
                                                                                style="color:#dc3545;"></i>
                                                                            Approved</span>
                                                                        <span class="mr-2"><i class="fa fa-square"
                                                                                style="color:#fd7e14;"></i>
                                                                            Pending</span>
                                                                        <span><i class="fa fa-square"
                                                                                style="color:#28a745;"></i> Free</span>
                                                                    </div>
                                                                </div>
                                                                <div class="timeline-bar" id="timeline-bar"></div>
                                                                <div class="timeline-ticks" id="timeline-ticks"></div>
                                                            </div>

                                                            <!-- Free slots (from backend) -->
                                                            <div class="free-slots-list" id="free-slots-list"></div>

                                                            <!-- Range status strip -->
                                                            <div class="range-status-strip" id="range-status-strip">
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <!-- Time slot -->
                                                    <div class="form-group" id="time-slot-group" style="display:none;">
                                                        <label>Allocation Time <span class="required">*</span>
                                                            <small id="time-window-hint"
                                                                class="time-input-note window-hint"></small>
                                                        </label>
                                                        <div class="row">
                                                            <div class="col-6">
                                                                <label style="font-size:0.78rem;color:#495057;">From
                                                                    Time</label>
                                                                <input type="time" class="form-control"
                                                                    name="allocStartTime" id="allocStartTime" required
                                                                    oninput="updateTimeDuration()">
                                                            </div>
                                                            <div class="col-6">
                                                                <label style="font-size:0.78rem;color:#495057;">To
                                                                    Time</label>
                                                                <input type="time" class="form-control"
                                                                    name="allocEndTime" id="allocEndTime" required
                                                                    oninput="updateTimeDuration()">
                                                            </div>
                                                        </div>
                                                        <div class="duration-display" id="duration-display">
                                                            <i class="fa fa-hourglass-half me-1"></i>
                                                            <span id="duration-text">—</span>
                                                        </div>
                                                        <div class="conflict-warning" id="conflict-warning">
                                                            <i class="fa fa-exclamation-triangle me-1"></i>
                                                            <span id="conflict-msg"></span>
                                                        </div>
                                                        <div id="batch-slot-validation-alert" style="display:none;border-radius:8px;margin-top:8px;"></div>
                                                    </div>

                                                    <input type="hidden" name="requestedBy"
                                                        value="<?php echo htmlspecialchars($userId); ?>">

                                                    <button type="submit" class="btn btn-submit-rr mr-2">Submit
                                                        Request</button>
                                                    <button type="button" class="btn btn-cancel-rr"
                                                        id="cancelFormBtn">Cancel</button>

                                                </div><!-- /col-md-7 -->

                                                <!-- ── Right column (image) ────────────────────────────── -->
                                                <div class="col-md-5">
                                                    <center>
                                                        <img src="../images/Resource_Request.png"
                                                            style="width:90%;margin-top:20px;" alt="Resource Request">
                                                    </center>
                                                </div>

                                            </div>
                                        </form>

                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>
    </div>

    <!-- ── Vendor scripts ──────────────────────────────────────────────────────── -->
    <script src="../vendors/js/vendor.bundle.base.js"></script>
    <script src="../js/off-canvas.js"></script>
    <script src="../js/hoverable-collapse.js"></script>
    <script src="../js/template.js"></script>
    <script src="../js/settings.js"></script>
    <script src="../js/todolist.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <script>
        // ── Lightweight work-window map (static, loaded once from PHP) ──────────────
        // Full live availability is always fetched via AJAX.
        const INTERN_STATIC = <?php echo json_encode($internJsonMap, JSON_UNESCAPED_UNICODE); ?>;

        // ── Utilities ─────────────────────────────────────────────────────────────────
        function timeToMins(t) {
            if (!t) return 0;
            var p = t.split(':');
            return parseInt(p[0]) * 60 + parseInt(p[1]);
        }
        function minsToTime(m) {
            var h = Math.floor(m / 60), mn = m % 60;
            return String(h).padStart(2, '0') + ':' + String(mn).padStart(2, '0');
        }
        function fmtMins(m) {
            if (m <= 0) return '0h';
            var h = Math.floor(m / 60), mn = m % 60;
            return mn === 0 ? h + 'h' : h + 'h ' + mn + 'm';
        }
        function fmt12(t24) {
            if (!t24) return t24;
            var p = t24.split(':'), h = parseInt(p[0]), m = p[1];
            var ap = h >= 12 ? 'PM' : 'AM';
            h = h % 12 || 12;
            return String(h).padStart(2, '0') + ':' + m + ' ' + ap;
        }
        function reasonLabel(reason) {
            return { holiday: 'Holiday', leave: 'On Leave', tour: 'On Tour', no_work_window: 'No Schedule' }[reason] || reason;
        }

        // ── State ─────────────────────────────────────────────────────────────────────
        var currentData = null;   // last AJAX payload
        var ajaxTimer = null;
        var lastFetchKey = '';     // debounce identical requests

        // ── AJAX fetch ────────────────────────────────────────────────────────────────
        function fetchAvailability() {
            var rawVal = $('#internId').val();
            var internIds = Array.isArray(rawVal) ? rawVal : (rawVal ? [rawVal] : []);
            var startDate = $('#startDate').val();
            var endDate = $('#endDate').val();

            if (!internIds || internIds.length === 0) {
                clearPanel();
                return;
            }

            if (internIds.length > 1) {
                // Multi-select batch mode
                document.getElementById('avail-loading').classList.remove('show');
                var panel = document.getElementById('intern-info-panel');
                panel.className = 'intern-info-panel show';
                document.getElementById('work-window-badge').innerHTML =
                    '<i class="fa fa-users me-1"></i> ' + internIds.length + ' Interns Selected';
                document.getElementById('work-window-text').textContent =
                    internIds.length + ' interns selected for batch request';
                document.getElementById('avail-bar-fill').style.width = '100%';
                document.getElementById('avail-bar-fill').style.background = '#4b49ac';
                document.getElementById('avail-used-label').textContent = '';
                document.getElementById('avail-free-label').textContent =
                    'Individual requests will be created for each intern upon submission.';
                document.getElementById('timeline-heading').textContent = 'Batch Request Mode';
                document.getElementById('timeline-bar').innerHTML =
                    '<div style="line-height:24px;text-align:center;font-size:0.75rem;font-weight:600;color:#4b49ac;">Multiple Intern Selection Active</div>';
                document.getElementById('free-slots-list').innerHTML = '';
                document.getElementById('time-slot-group').style.display = 'block';
                updateTimeDuration();
                return;
            }

            var internId = internIds[0];
            var today = new Date().toISOString().slice(0, 10);
            var sd = startDate || today;
            var ed = endDate || sd;
            if (ed < sd) ed = sd;

            var key = internId + '|' + sd + '|' + ed;
            if (key === lastFetchKey) return;  // nothing changed
            lastFetchKey = key;

            // Show loading
            document.getElementById('avail-loading').classList.add('show');
            document.getElementById('intern-info-panel').classList.remove('show', 'warn', 'danger');

            $.ajax({
                url: 'resourceRequest.php',
                data: { ajax: 'availability', internId: internId, startDate: sd, endDate: ed, forDate: sd },
                dataType: 'json',
                success: function (data) {
                    document.getElementById('avail-loading').classList.remove('show');
                    document.getElementById('time-slot-group').style.display = 'block';
                    if (data.error) {
                        var panel = document.getElementById('intern-info-panel');
                        panel.className = 'intern-info-panel show danger';
                        document.getElementById('free-slots-list').innerHTML = '<span style="font-size:0.76rem;color:#842029;"><i class="fa fa-exclamation-triangle me-1"></i> ' + (data.error || 'Could not fetch availability') + '</span>';
                        return;
                    }
                    currentData = data;
                    renderPanel(data);
                    // Only pre-fill time inputs if user hasn't selected a time slot yet (fixes time resetting on date change)
                    var currentSt = document.getElementById('allocStartTime').value;
                    var currentEt = document.getElementById('allocEndTime').value;
                    if (!currentSt || !currentEt) {
                        if (data.freeSlots && data.freeSlots.length > 0) {
                            document.getElementById('allocStartTime').value = data.freeSlots[0].startTime;
                            document.getElementById('allocEndTime').value = data.freeSlots[0].endTime;
                        } else {
                            document.getElementById('allocStartTime').value = data.workStartTime || '09:00';
                            document.getElementById('allocEndTime').value = data.workEndTime || '17:00';
                        }
                    }
                    updateTimeDuration();
                },
                error: function () {
                    document.getElementById('avail-loading').classList.remove('show');
                    clearPanel();
                }
            });
        }

        function scheduleFetch() {
            clearTimeout(ajaxTimer);
            ajaxTimer = setTimeout(fetchAvailability, 250);
        }

        // ── Render availability panel from AJAX data ──────────────────────────────────
        function renderPanel(d) {
            var panel = document.getElementById('intern-info-panel');

            // Work window badge
            document.getElementById('work-window-text').textContent =
                d.workStart12 + ' – ' + d.workEnd12 + ' (' + fmtMins(d.totalMins) + ' total)';
            document.getElementById('time-window-hint').textContent =
                'Working hours: ' + d.workStart12 + ' – ' + d.workEnd12;

            // Availability bar
            var usedMins = d.usedMins;
            var usedPct = d.totalMins > 0 ? Math.round(usedMins / d.totalMins * 100) : 0;
            var barFill = document.getElementById('avail-bar-fill');
            barFill.style.width = Math.min(usedPct, 100) + '%';
            barFill.style.background = usedPct >= 100 ? '#dc3545' : (usedPct > 60 ? '#fd7e14' : '#28a745');

            document.getElementById('avail-used-label').textContent = fmtMins(usedMins) + ' used';
            document.getElementById('avail-free-label').textContent = fmtMins(d.freeMins) + ' free';

            // Timeline — show for forDate
            var barEl = document.getElementById('timeline-bar');
            barEl.innerHTML = '';
            var wStart = d.workStartMins, wEnd = d.workEndMins, wTotal = wEnd - wStart;

            document.getElementById('timeline-heading').textContent =
                'Schedule for ' + (document.getElementById('startDate').value || 'today');

            if (!d.dayAvailable) {
                // Full-width blocked bar
                var blocked = document.createElement('div');
                blocked.className = 'timeline-slot-used';
                blocked.style.cssText = 'left:0%;width:100%;background:#6c757d;opacity:0.6;';
                blocked.textContent = reasonLabel(d.dayReason);
                barEl.appendChild(blocked);
            } else if (wTotal > 0) {
                // Render used slots (approved = red, pending = orange)
                (d.usedSlots || []).forEach(function (slot) {
                    var left = Math.max(0, ((slot.s - wStart) / wTotal * 100)).toFixed(2);
                    var width = Math.min(100, ((slot.e - slot.s) / wTotal * 100)).toFixed(2);
                    var div = document.createElement('div');
                    div.className = slot.isPending ? 'timeline-slot-pending' : 'timeline-slot-used';
                    div.style.cssText = 'left:' + left + '%;width:' + width + '%;';
                    div.title = (slot.project || 'Assigned') + ' ('
                        + fmt12(minsToTime(slot.s)) + '–' + fmt12(minsToTime(slot.e)) + ')'
                        + (slot.isPending ? ' [Pending]' : '');
                    if (parseFloat(width) > 8) div.textContent = fmt12(minsToTime(slot.s));
                    barEl.appendChild(div);
                });

                // Preview element for selected slot
                var previewEl = document.createElement('div');
                previewEl.id = 'timeline-selected';
                previewEl.className = 'timeline-slot-selected';
                previewEl.style.display = 'none';
                barEl.appendChild(previewEl);

                // Ticks
                var mid = wStart + Math.floor(wTotal / 2);
                document.getElementById('timeline-ticks').innerHTML =
                    '<span>' + fmt12(minsToTime(wStart)) + '</span>' +
                    '<span>' + fmt12(minsToTime(mid)) + '</span>' +
                    '<span>' + fmt12(minsToTime(wEnd)) + '</span>';
            }

            // Free slots (canonical list from backend — no duplication)
            var fsEl = document.getElementById('free-slots-list');
            fsEl.innerHTML = '';
            if (!d.dayAvailable) {
                fsEl.innerHTML = '<span style="font-size:0.76rem;color:#842029;">'
                    + '<i class="fa fa-ban"></i> ' + d.dayMessage + '</span>';
            } else if (d.freeSlots && d.freeSlots.length > 0) {
                var lbl = document.createElement('div');
                lbl.style.cssText = 'font-size:0.72rem;font-weight:700;color:#155724;margin-bottom:4px;';
                lbl.textContent = 'Available slots on this date (click to fill):';
                fsEl.appendChild(lbl);
                d.freeSlots.forEach(function (slot) {
                    var tag = document.createElement('span');
                    tag.className = 'free-slot-tag';
                    tag.textContent = fmt12(slot.startTime) + ' – ' + fmt12(slot.endTime)
                        + ' (' + fmtMins(slot.duration) + ')';
                    tag.addEventListener('click', function () {
                        document.getElementById('allocStartTime').value = slot.startTime;
                        document.getElementById('allocEndTime').value = slot.endTime;
                        updateTimeDuration();
                    });
                    fsEl.appendChild(tag);
                });
            } else {
                fsEl.innerHTML = '<span style="font-size:0.76rem;color:#842029;">'
                    + '<i class="fa fa-ban"></i> No free slots on this date — you can still request for other dates.</span>';
            }

            // Range status strip
            var strip = document.getElementById('range-status-strip');
            var blocked = d.rangeBlocked || {};
            var blockedKeys = Object.keys(blocked);
            var sd = document.getElementById('startDate').value;
            var ed = document.getElementById('endDate').value;
            strip.innerHTML = '';
            if (sd && ed && sd !== ed) {
                if (blockedKeys.length === 0) {
                    strip.className = 'range-status-strip show ok';
                    strip.innerHTML = '<i class="fa fa-check-circle"></i> All dates in range are workable.';
                } else {
                    strip.className = 'range-status-strip show ' + (blockedKeys.length > 2 ? 'danger' : 'warning');
                    var html = '<strong>⚠ ' + blockedKeys.length + ' blocked date(s) in range:</strong> ';
                    blockedKeys.forEach(function (dt) {
                        html += '<span class="blocked-badge">' + dt + ' – ' + reasonLabel(blocked[dt]) + '</span>';
                    });
                    strip.innerHTML = html;
                }
                strip.classList.add('show');
            } else {
                strip.classList.remove('show');
            }

            // Panel class
            var cls = 'intern-info-panel show';
            if (!d.dayAvailable || d.freeMins === 0) cls += ' danger';
            else if (d.usedMins > 0) cls += ' warn';
            panel.className = cls;
        }

        function clearPanel() {
            currentData = null;
            lastFetchKey = '';
            document.getElementById('intern-info-panel').className = 'intern-info-panel';
            document.getElementById('time-slot-group').style.display = 'none';
            document.getElementById('range-status-strip').classList.remove('show');
            document.getElementById('duration-display').classList.remove('show');
            document.getElementById('conflict-warning').classList.remove('show');
        }

        // ── Duration + conflict display (client-side preview only) ────────────────────
        function updateTimeDuration() {
            var st = document.getElementById('allocStartTime').value;
            var et = document.getElementById('allocEndTime').value;
            var dur = document.getElementById('duration-display');
            var warn = document.getElementById('conflict-warning');
            var msg = document.getElementById('conflict-msg');

            dur.classList.remove('show');
            warn.classList.remove('show');
            msg.textContent = '';

            if (!st || !et) return;

            var sm = timeToMins(st), em = timeToMins(et);

            if (em <= sm) {
                warn.classList.add('show');
                msg.textContent = 'End time must be after start time.';
                return;
            }

            dur.classList.add('show');
            document.getElementById('duration-text').textContent = fmtMins(em - sm) + ' allocated';

            if (currentData) {
                // Outside-hours warning
                if (sm < currentData.workStartMins || em > currentData.workEndMins) {
                    warn.classList.add('show');
                    msg.textContent = '⚠ Outside normal hours ('
                        + currentData.workStart12 + ' – ' + currentData.workEnd12
                        + '). You can still submit.';
                }

                // Update timeline preview
                var wTotal = currentData.workEndMins - currentData.workStartMins;
                var preEl = document.getElementById('timeline-selected');
                if (preEl && wTotal > 0) {
                    var left = Math.max(0, ((sm - currentData.workStartMins) / wTotal * 100)).toFixed(2);
                    var width = Math.min(100, ((em - sm) / wTotal * 100)).toFixed(2);
                    preEl.style.cssText = 'left:' + left + '%;width:' + width + '%;display:block;';
                }
            }

            // Trigger batch slot validation check
            validateBatchSlot(st, et);
        }

        var batchSlotTimer = null;
        function validateBatchSlot(st, et) {
            clearTimeout(batchSlotTimer);
            batchSlotTimer = setTimeout(function() {
                var rawVal = $('#internId').val();
                var internIds = Array.isArray(rawVal) ? rawVal : (rawVal ? [rawVal] : []);
                var sd = $('#startDate').val() || new Date().toISOString().slice(0, 10);
                var ed = $('#endDate').val() || sd;
                var hintBox = document.getElementById('batch-slot-validation-alert');

                if (!internIds || internIds.length === 0 || !st || !et || !hintBox) {
                    if (hintBox) hintBox.style.display = 'none';
                    return;
                }

                $.ajax({
                    url: 'resourceRequest.php',
                    data: {
                        ajax: 'validate_batch_slot',
                        internIds: internIds,
                        startDate: sd,
                        endDate: ed,
                        allocStartTime: st,
                        allocEndTime: et
                    },
                    dataType: 'json',
                    success: function(res) {
                        if (!res.success) return;

                        if (res.allAvailable) {
                            hintBox.className = 'alert alert-success py-2 px-3 small font-weight-bold mb-0 mt-2';
                            hintBox.style.display = 'block';
                            hintBox.innerHTML = '<i class="fa fa-check-circle me-1"></i> All selected intern(s) are free during ' + fmt12(st) + ' – ' + fmt12(et) + '.';
                        } else {
                            hintBox.className = 'alert alert-warning py-2 px-3 small mb-0 mt-2';
                            hintBox.style.display = 'block';
                            var msgHtml = '<i class="fa fa-exclamation-triangle me-1"></i> <strong>Time Slot Conflict:</strong><ul class="mb-0 mt-1 pl-3" style="font-size:0.78rem;">';
                            res.results.forEach(function(item) {
                                if (!item.valid) {
                                    var freeSlotsText = '';
                                    if (item.freeSlots && item.freeSlots.length > 0) {
                                        var slotStrs = item.freeSlots.map(function(s){ return fmt12(s.startTime) + '–' + fmt12(s.endTime); });
                                        freeSlotsText = ' (Free window: ' + slotStrs.join(', ') + ')';
                                    } else {
                                        freeSlotsText = ' (No free hours available)';
                                    }
                                    msgHtml += '<li><strong>' + item.fullName + '</strong> is busy during ' + fmt12(st) + '–' + fmt12(et) + freeSlotsText + '.</li>';
                                }
                            });
                            msgHtml += '</ul>';
                            hintBox.innerHTML = msgHtml;
                        }
                    }
                });
            }, 300);
        }

        var rangeFetchTimer = null;
        function fetchFreeInternsForRange() {
            clearTimeout(rangeFetchTimer);
            rangeFetchTimer = setTimeout(function() {
                var sd = $('#startDate').val();
                var ed = $('#endDate').val();
                if (!sd || !ed) return;

                var badge = $('#intern-filter-status-badge');
                if (badge.length) {
                    badge.html('<i class="fa fa-spinner fa-spin me-1"></i> Filtering available interns…');
                }

                $.ajax({
                    url: 'resourceRequest.php',
                    data: {
                        ajax: 'fetch_free_interns',
                        startDate: sd,
                        endDate: ed
                    },
                    dataType: 'json',
                    success: function(res) {
                        if (!res || !res.success) return;

                        var selectEl = $('#internId');
                        var currentlySelected = selectEl.val() || [];
                        var oldSelectedArray = Array.isArray(currentlySelected) ? currentlySelected : [currentlySelected];

                        selectEl.empty(); // clear options

                        var freeCount = 0;
                        res.interns.forEach(function(item) {
                            var iid = item.internId;
                            var name = item.fullName;
                            var role = item.role;
                            var freeMins = item.minFreeMins;
                            var isFull = item.isFull;
                            var ws = item.ws;
                            var we = item.we;

                            var rangeText = (sd === ed) ? 'on ' + sd : 'for ' + sd + ' to ' + ed;
                            var availText = isFull
                                ? ' — Fully Assigned / Blocked (' + rangeText + ')'
                                : ' — ' + fmtMins(freeMins) + ' free/day · ' + fmt12(ws) + '–' + fmt12(we);

                            if (!isFull) freeCount++;

                            var isSel = oldSelectedArray.indexOf(iid) !== -1;

                            var opt = $('<option>', {
                                value: iid,
                                'data-ws': ws,
                                'data-we': we,
                                'data-freemins': freeMins,
                                text: name + ' (' + role + ')' + availText
                            });

                            if (isSel) {
                                opt.prop('selected', true);
                            }

                            selectEl.append(opt);
                        });

                        selectEl.trigger('change.select2');

                        if (badge.length) {
                            var rangeLabel = (sd === ed) ? sd : (sd + ' to ' + ed);
                            badge.html(
                                '<i class="fa fa-check-circle me-1" style="color:#28a745;"></i> ' + freeCount + ' intern(s) available for ' + rangeLabel
                            );
                        }
                    },
                    error: function() {
                        if (badge.length) {
                            badge.html('<i class="fa fa-filter me-1"></i> Dynamically filtered by Date Range');
                        }
                    }
                });
            }, 250);
        }

        // ── Date change listeners ──────────────────────────────────────────────────────
        document.getElementById('startDate').addEventListener('change', function () {
            var ed = document.getElementById('endDate');
            ed.min = this.value;
            if (ed.value && ed.value < this.value) ed.value = this.value;
            lastFetchKey = '';   // force re-fetch
            fetchFreeInternsForRange();
            scheduleFetch();
        });

        document.getElementById('endDate').addEventListener('change', function () {
            lastFetchKey = '';   // force re-fetch
            fetchFreeInternsForRange();
            scheduleFetch();
        });

        // ── jQuery ready ──────────────────────────────────────────────────────────────
        $(document).ready(function () {
            fetchFreeInternsForRange();

            $('.js-intern-select').select2({
                width: '100%',
                placeholder: '-- Select Intern(s) --',
                allowClear: true,
                templateSelection: function(data) {
                    if (!data.id) return data.text;
                    var fullText = data.text || '';
                    if (fullText.indexOf('—') !== -1) {
                        return fullText.split('—')[0].trim();
                    }
                    return fullText;
                }
            });
            $('.js-project-select').select2({ width: '100%', placeholder: '-- Select Project --', allowClear: true });
            $('.js-edit-project-select').select2({
                dropdownParent: $('#modalEditMyResourceRequest'),
                width: '100%',
                placeholder: '-- Select Project --'
            });

            // Auto-fill client from project
            $('#projectName').on('change', function() {
                var client = $(this).find('option:selected').data('client');
                if (client) $('#clientName').val(client);
            });

            // Intern change → enforce same timing & free hours for multi-select
            $('#internId').on('change', function() {
                var selectedValues = $(this).val() || [];
                
                if (selectedValues.length > 0) {
                    var firstOpt = $(this).find('option[value="' + selectedValues[0] + '"]');
                    var baseWs = firstOpt.data('ws');
                    var baseWe = firstOpt.data('we');
                    var baseFreeMins = firstOpt.data('freemins');

                    // Filter out options that do not match baseWs, baseWe, baseFreeMins
                    $(this).find('option').each(function() {
                        var optVal = $(this).attr('value');
                        if (!optVal) return;
                        var optWs = $(this).data('ws');
                        var optWe = $(this).data('we');
                        var optFree = $(this).data('freemins');

                        var isMatch = (optWs === baseWs && optWe === baseWe && optFree === baseFreeMins);
                        if (!isMatch) {
                            $(this).prop('disabled', true);
                        } else {
                            $(this).prop('disabled', false);
                        }
                    });

                    // Update hint text
                    var rawText = firstOpt.text() || '';
                    var shiftText = rawText.indexOf('·') !== -1 ? rawText.split('·')[1].trim() : (baseWs + '–' + baseWe);
                    $('#hint-shift-window').text(shiftText);
                    $('#hint-free-hours').text(fmtMins(baseFreeMins));
                    $('#intern-timing-filter-hint').show();
                } else {
                    // Re-enable all options when nothing is selected
                    $(this).find('option').prop('disabled', false);
                    $('#intern-timing-filter-hint').hide();
                }

                if (!selectedValues || selectedValues.length === 0) { clearPanel(); return; }
                lastFetchKey = '';
                scheduleFetch();
            });

            // Pre-select intern if passed via GET
            <?php if ($preInternId !== '' && $selectedIntern !== null): ?>
            $('#internId').val([<?php echo json_encode($preInternId); ?>]).trigger('change');
            <?php endif; ?>
        });

        // ── Submit guard ──────────────────────────────────────────────────────────────
        var reqForm = document.querySelector('form');
        if (reqForm) {
            reqForm.addEventListener('submit', function(e) {
                var stEl = document.getElementById('allocStartTime');
                var etEl = document.getElementById('allocEndTime');
                var st = stEl ? stEl.value : '';
                var et = etEl ? etEl.value : '';

                if (!st) { e.preventDefault(); alert('Please enter a start time.'); return; }
                if (!et) { e.preventDefault(); alert('Please enter an end time.');   return; }
                if (timeToMins(et) <= timeToMins(st)) {
                    e.preventDefault();
                    alert('End time must be after start time.');
                }
            });
        }
    </script>

    <?php
    if ($flashStatus !== null && $flashMsg !== null) {
        require_once('../include/sweetAlert.php');
        toastMsg($flashStatus ? 'success' : 'error', $flashMsg);
    }
    ?>

    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>

    <script>
        if (typeof $.fn.dataTable !== 'undefined') {
            $.fn.dataTable.ext.errMode = 'none';
        }

        $(document).ready(function () {
            if (typeof $.fn.dataTable !== 'undefined') {
                $.fn.dataTable.ext.errMode = 'none';
            }
            if ($('#myRequestsTable').length) {
                if ($.fn.DataTable.isDataTable('#myRequestsTable')) {
                    $('#myRequestsTable').DataTable().destroy();
                }
                $('#myRequestsTable').DataTable({
                    destroy: true,
                    pageLength: 10,
                    lengthMenu: [[10, 25, 50, -1], [10, 25, 50, 'All']],
                    ordering: true,
                    order: [[8, 'desc']],
                    info: true,
                    searching: true,
                    autoWidth: false,
                    language: {
                        search: "",
                        searchPlaceholder: "Search requests…",
                        emptyTable: "No resource requests submitted yet."
                    }
                });
            }

            $('#toggleFormBtn').on('click', function () {
                var card = $('#requestFormCard');
                if (card.is(':visible')) {
                    card.slideUp();
                    $(this).html('<i class="fa fa-plus-circle me-1"></i> Create New Request');
                } else {
                    card.slideDown();
                    $(this).html('<i class="fa fa-times me-1"></i> Hide Request Form');
                    $('html, body').animate({
                        scrollTop: card.offset().top - 80
                    }, 400);
                }
            });

            // Cancel / Collapse buttons inside the form
            $('#cancelFormBtn, #collapseFormBtn').on('click', function () {
                $('#requestFormCard').slideUp();
                $('#toggleFormBtn').html('<i class="fa fa-plus-circle me-1"></i> Create New Request');
                $('html, body').animate({ scrollTop: 0 }, 400);
            });
        });

        function deleteRequest(reqId) {
            if (!confirm('Are you sure you want to delete this resource request?')) {
                return;
            }
            $.ajax({
                url: 'deleteResourceRequest.php',
                type: 'POST',
                data: { requestId: reqId },
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        alert(res.message || 'Request deleted successfully.');
                        window.location.reload();
                    } else {
                        alert(res.message || 'Failed to delete request.');
                    }
                },
                error: function(xhr, status, err) {
                    alert('An error occurred while deleting the request.');
                }
            });
        }

        // ── Edit Request Handler ──
        $(document).on('click', '.btn-edit-my-req', function() {
            var reqId      = $(this).data('reqid');
            var internId   = $(this).data('internid');
            var internName = $(this).data('internname');
            var project    = $(this).data('project');
            var client     = $(this).data('client');
            var sDate      = $(this).data('sdate');
            var eDate      = $(this).data('edate');
            var st         = $(this).data('st');
            var et         = $(this).data('et');

            $('#edit_my_requestId').val(reqId);
            $('#edit_my_internId').val(internId);
            $('#edit_my_display_intern').text(internName + ' (' + internId + ')');
            $('#edit_my_display_project').text(project + ' — ' + client);
            $('#edit_my_projectName').val(project).trigger('change');
            $('#edit_my_clientName').val(client);
            $('#edit_my_startDate').val(sDate);
            $('#edit_my_endDate').val(eDate);
            $('#edit_my_allocST').val(st);
            $('#edit_my_allocET').val(et);

            $('#modalEditMyResourceRequest').modal('show');
        });

        $('#edit_my_projectName').on('change', function() {
            var selectedOpt = $(this).find('option:selected');
            var client = selectedOpt.data('client') || '';
            $('#edit_my_clientName').val(client);
        });

        $('#form_edit_my_request').on('submit', function(e) {
            e.preventDefault();
            var formData = $(this).serialize();

            $.ajax({
                url: 'updateResourceRequestProcess.php',
                type: 'POST',
                data: formData,
                dataType: 'json',
                success: function(res) {
                    if (res.status) {
                        $('#modalEditMyResourceRequest').modal('hide');
                        if (typeof Toast !== 'undefined') {
                            Toast.fire({ icon: 'success', text: res.msg });
                        } else {
                            alert(res.msg);
                        }
                        setTimeout(function() { location.reload(); }, 1000);
                    } else {
                        if (typeof sweetMsg === 'function') {
                            sweetMsg('error', res.msg);
                        } else if (typeof Swal !== 'undefined') {
                            Swal.fire({ title: 'Validation Alert', html: res.msg, icon: 'error', confirmButtonColor: '#4b49ac' });
                        } else {
                            alert(res.msg);
                        }
                    }
                },
                error: function() {
                    alert('An error occurred while updating the resource request.');
                }
            });
        });
    </script>

<!-- ── EDIT RESOURCE REQUEST MODAL ── -->
<div class="modal fade" id="modalEditMyResourceRequest" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document" style="max-width:520px;">
    <div class="modal-content" style="border-radius:14px;border:none;box-shadow:0 10px 30px rgba(0,0,0,0.18);">
      <div class="modal-header" style="background:linear-gradient(135deg,#3a39a0,#4b49ac);color:#fff;border-radius:14px 14px 0 0;padding:16px 24px;">
        <h5 class="modal-title" style="font-weight:700;font-size:1.05rem;">
          <i class="fa fa-pencil me-2"></i> Edit Resource Request
        </h5>
        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close" style="opacity:0.9;">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <form id="form_edit_my_request" method="POST" action="updateResourceRequestProcess.php">
        <div class="modal-body" style="padding:20px 24px;">
          <input type="hidden" name="requestId" id="edit_my_requestId" value="">
          <input type="hidden" name="isAjax" value="1">
          <input type="hidden" id="edit_my_internId" value="">

          <div class="alert alert-light border mb-3" style="border-radius:8px;padding:12px 16px;">
            <div style="font-weight:700;color:#2b2b40;font-size:0.9rem;" id="edit_my_display_intern">Intern Name</div>
            <small class="text-muted d-block" id="edit_my_display_project">Project Name</small>
          </div>

          <!-- Project & Client selection -->
          <div class="form-group mb-3">
            <label style="font-weight:700;font-size:0.83rem;color:#444;">Project Name:</label>
            <select class="form-control form-control-sm js-edit-project-select" name="projectName" id="edit_my_projectName" required style="width:100%;border-radius:6px;font-weight:600;color:#333;">
              <?php foreach ($projectList as $proj): ?>
                <option value="<?php echo htmlspecialchars($proj['projectName'], ENT_QUOTES); ?>" data-client="<?php echo htmlspecialchars($proj['client'], ENT_QUOTES); ?>">
                  <?php echo htmlspecialchars($proj['projectName'], ENT_QUOTES); ?><?php if (!empty($proj['client'])): ?> — <?php echo htmlspecialchars($proj['client'], ENT_QUOTES); ?><?php endif; ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group mb-3">
            <label style="font-weight:700;font-size:0.83rem;color:#444;">Client Name:</label>
            <input type="text" class="form-control form-control-sm" name="clientName" id="edit_my_clientName" required readonly style="background:#e9ecef;border-radius:6px;font-weight:600;">
          </div>

          <!-- Dates -->
          <div class="form-group mb-3">
            <label style="font-weight:700;font-size:0.83rem;color:#444;">Requested Allocation Dates:</label>
            <div class="d-flex align-items-center gap-2">
              <input type="date" class="form-control form-control-sm" name="startDate" id="edit_my_startDate" required style="border-radius:6px;font-weight:600;color:#4b49ac;">
              <span style="font-weight:600;color:#888;">to</span>
              <input type="date" class="form-control form-control-sm" name="endDate" id="edit_my_endDate" required style="border-radius:6px;font-weight:600;color:#4b49ac;">
            </div>
          </div>

          <!-- Time Slot -->
          <div class="form-group mb-3">
            <label style="font-weight:700;font-size:0.83rem;color:#444;">Time Slot (Punch Window):</label>
            <div class="d-flex align-items-center gap-2">
              <input type="time" class="form-control form-control-sm" name="allocStartTime" id="edit_my_allocST" required style="border-radius:6px;font-weight:600;color:#4b49ac;">
              <span style="font-weight:600;color:#888;">to</span>
              <input type="time" class="form-control form-control-sm" name="allocEndTime" id="edit_my_allocET" required style="border-radius:6px;font-weight:600;color:#4b49ac;">
            </div>
          </div>
        </div>

        <div class="modal-footer" style="background:#fafbfe;border-radius:0 0 14px 14px;padding:12px 24px;">
          <button type="button" class="btn btn-secondary btn-sm px-3" data-dismiss="modal" style="border-radius:6px;font-weight:600;">Cancel</button>
          <button type="submit" id="btn_save_my_edit" class="btn btn-primary btn-sm px-4" style="border-radius:6px;font-weight:600;background:#4b49ac;">
            <i class="fa fa-save me-1"></i> Save Changes
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
</body>

</html>
