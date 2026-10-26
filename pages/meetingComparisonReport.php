<?php
include('../include/db_config.php');

if (!isset($_SESSION['userId']) && isset($_COOKIE['userId'])) {
    $_SESSION['userId'] = $_COOKIE['userId'];
}

if (!isset($_SESSION['userId'])) {
    header("Location: login.php");
    exit;
}

$userId = $_SESSION['userId'];

// Fetch logged-in user details
$userRow = mysqli_fetch_assoc(mysqli_query($con, "SELECT userId, fullName, emp_id, internId, userRole, designation FROM signup WHERE userId='$userId' LIMIT 1"));
if (!$userRow) {
    $userRow = mysqli_fetch_assoc(mysqli_query($con, "SELECT internId as userId, fullName, internId as emp_id, internId, 'intern' as userRole, 'intern' as designation FROM interns WHERE internId='$userId' LIMIT 1"));
}
$userRoleVal  = strtolower($userRow['userRole'] ?? '');
$userDesigVal = strtolower($userRow['designation'] ?? '');
$userName     = $userRow['fullName'] ?? 'User';

// Access control: Admin, HR, Resource Head, Team Lead, Senior Intern
$rhRoles  = ['hr', 'admin', 'superadmin', 'resource_head', 'resourcehead', 'resourceallocator', 'resource_allocator', 'resource allocator', 'resourcemanager', 'resource_manager'];
$leadRoles = ['team_lead', 'teamlead', 'senior_intern', 'seniorintern', 'project_manager', 'pm'];

$isHR     = in_array($userRoleVal, $rhRoles) || in_array($userDesigVal, $rhRoles);
$isLeader = in_array($userRoleVal, $leadRoles) || in_array($userDesigVal, $leadRoles);
if (!$isHR && !$isLeader) {
    echo "<script>alert('Access Denied: You do not have permission to view Meeting Comparison Reports.'); window.location.href = 'meetingHistory.php';</script>";
    exit;
}

// Fetch all recurring meetings for dropdown selector
$recurringMeetingsQ = mysqli_query($con, "
    SELECT m.id, m.agenda, m.recurrence_type, m.meeting_date, m.meeting_time, m.branch, m.participants,
           COUNT(o.id) as total_occurrences
    FROM meetings m
    JOIN meeting_occurrences o ON o.meeting_id = m.id
    WHERE m.recurrence_type IS NOT NULL AND TRIM(m.recurrence_type) != ''
    GROUP BY m.id
    ORDER BY m.id DESC
");

$recurringMeetings = [];
if ($recurringMeetingsQ) {
    while ($rm = mysqli_fetch_assoc($recurringMeetingsQ)) {
        $recurringMeetings[] = $rm;
    }
}

// Determine selected meeting ID
$selectedMeetingId = isset($_GET['meeting_id']) ? intval($_GET['meeting_id']) : ($recurringMeetings[0]['id'] ?? 0);

// Fetch details for selected meeting
$selectedMeeting = null;
if ($selectedMeetingId > 0) {
    $mRes = mysqli_query($con, "SELECT m.*, s.fullName as creator_name FROM meetings m LEFT JOIN signup s ON s.userId = m.creator_id WHERE m.id = $selectedMeetingId LIMIT 1");
    if ($mRes && mysqli_num_rows($mRes) > 0) {
        $selectedMeeting = mysqli_fetch_assoc($mRes);
    }
}

// Initialize comparison data variables
$occurrences = [];
$participantsList = [];
$matrix = []; // [userId][occurrence_id] = status
$occurrencesChartData = [];
$momLogs = [];
$summaryStats = [
    'total_occs'     => 0,
    'completed_occs' => 0,
    'avg_attendance' => 0,
    'total_present'  => 0,
    'total_absent'   => 0,
    'total_intimated' => 0,
];

if ($selectedMeeting) {
    $mid = (int)$selectedMeeting['id'];

    // 1. Fetch occurrences (limit to max 50 for matrix grid performance, sorted by date ASC)
    $occRes = mysqli_query($con, "
        SELECT id, occurrence_date, occurrence_time, status
        FROM meeting_occurrences
        WHERE meeting_id = $mid
        ORDER BY occurrence_date ASC, occurrence_time ASC
        LIMIT 60
    ");
    if ($occRes) {
        while ($oRow = mysqli_fetch_assoc($occRes)) {
            $occurrences[] = $oRow;
        }
    }

    $summaryStats['total_occs'] = count($occurrences);

    // 2. Fetch participant IDs from meetings table
    $pIdsStr = $selectedMeeting['participants'] ?? '';
    $pIdsArr = array_filter(array_map('trim', explode(',', $pIdsStr)));

    if (!empty($pIdsArr)) {
        $escapedPids = implode("','", array_map(fn($id) => mysqli_real_escape_string($con, $id), $pIdsArr));
        
        $pQuery = mysqli_query($con, "
            SELECT DISTINCT userId, fullName, display_id, batch_name FROM (
                SELECT s.userId, s.fullName, COALESCE(NULLIF(s.emp_id,''), s.internId, '—') as display_id, COALESCE(NULLIF(TRIM(s.batch_number),''), 'Unassigned') as batch_name
                FROM signup s WHERE s.userId IN ('$escapedPids')
                UNION
                SELECT i.internId as userId, i.fullName, i.internId as display_id, COALESCE(NULLIF(TRIM(i.batch),''), 'Unassigned') as batch_name
                FROM interns i WHERE i.internId IN ('$escapedPids')
            ) combined ORDER BY fullName ASC
        ");
        if ($pQuery) {
            while ($pr = mysqli_fetch_assoc($pQuery)) {
                $participantsList[] = $pr;
            }
        }
    }

    // 3. Fetch attendance matrix logs for this meeting
    $attQuery = mysqli_query($con, "
        SELECT occurrence_id, userId, status, markedAt
        FROM meeting_attendance
        WHERE meetingId = '$mid'
    ");
    
    if ($attQuery) {
        while ($aRow = mysqli_fetch_assoc($attQuery)) {
            $occId = (int)$aRow['occurrence_id'];
            $uId   = $aRow['userId'];
            $st    = $aRow['status'];
            $matrix[$uId][$occId] = $st;

            if ($st === 'present')          $summaryStats['total_present']++;
            elseif ($st === 'absent')       $summaryStats['total_absent']++;
            elseif ($st === 'absent_intimated') $summaryStats['total_intimated']++;
        }
    }

    // 4. Calculate per-occurrence attendance trend for chart
    foreach ($occurrences as $occ) {
        $occId = (int)$occ['id'];
        $pCount = 0; $aCount = 0; $iCount = 0;
        foreach ($participantsList as $p) {
            $st = $matrix[$p['userId']][$occId] ?? '';
            if ($st === 'present')          $pCount++;
            elseif ($st === 'absent')       $aCount++;
            elseif ($st === 'absent_intimated') $iCount++;
        }
        if ($occ['status'] === 'completed') $summaryStats['completed_occs']++;

        $timeFormatted = date('h:i A', strtotime($occ['occurrence_time']));
        $dateFormatted = date('d M', strtotime($occ['occurrence_date']));

        $occurrencesChartData[] = [
            'label'     => $dateFormatted . ' ' . $timeFormatted,
            'present'   => $pCount,
            'absent'    => $aCount,
            'intimated' => $iCount,
            'total'     => count($participantsList)
        ];
    }

    // 5. Fetch MOM Discussion Points Logged per occurrence
    $momQ = mysqli_query($con, "
        SELECT mm.id, mm.occurrence_id, mm.discussion_points, mm.mom_finalised, mm.created_at,
               o.occurrence_date, o.occurrence_time, s.fullName as author_name
        FROM meeting_mom mm
        JOIN meeting_occurrences o ON o.id = mm.occurrence_id
        LEFT JOIN signup s ON s.userId = mm.created_by
        WHERE mm.meeting_id = $mid AND mm.discussion_points IS NOT NULL AND TRIM(mm.discussion_points) != ''
        ORDER BY o.occurrence_date DESC, o.occurrence_time DESC
    ");
    if ($momQ) {
        while ($momRow = mysqli_fetch_assoc($momQ)) {
            $momLogs[] = $momRow;
        }
    }
}

// Export Matrix CSV Action
if (isset($_GET['action']) && $_GET['action'] === 'export_matrix_csv' && $selectedMeeting) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=meeting_comparison_' . $selectedMeetingId . '_' . date('Y-m-d') . '.csv');
    $output = fopen('php://output', 'w');

    // Header row: Participant Name, ID, Batch, [Occurrence Columns], Overall Rate
    $headerRow = ['Participant Name', 'Display ID', 'Batch'];
    foreach ($occurrences as $occ) {
        $dayName = date('D', strtotime($occ['occurrence_date']));
        $headerRow[] = $dayName . ', ' . date('d M Y', strtotime($occ['occurrence_date'])) . ' ' . date('h:i A', strtotime($occ['occurrence_time']));
    }
    $headerRow[] = 'Present Count';
    $headerRow[] = 'Absent Count';
    $headerRow[] = 'Intimated Count';
    $headerRow[] = 'Attendance Score (%)';
    fputcsv($output, $headerRow);

    foreach ($participantsList as $p) {
        $row = [$p['fullName'], $p['display_id'], $p['batch_name']];
        $pPresent = 0; $pAbsent = 0; $pIntimated = 0; $pTotal = count($occurrences);
        foreach ($occurrences as $occ) {
            $st = $matrix[$p['userId']][$occ['id']] ?? '—';
            if ($st === 'present')          { $row[] = 'Present'; $pPresent++; }
            elseif ($st === 'absent')       { $row[] = 'Absent'; $pAbsent++; }
            elseif ($st === 'absent_intimated') { $row[] = 'Absent (Intimated)'; $pIntimated++; }
            else                            { $row[] = 'Unmarked'; }
        }
        $rate = $pTotal > 0 ? round(($pPresent / $pTotal) * 100, 1) : 0;
        $row[] = $pPresent;
        $row[] = $pAbsent;
        $row[] = $pIntimated;
        $row[] = $rate . '%';
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>SETTribe · Recurring Meeting Comparison Report</title>
    <?php include('../partials/_links.php'); ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #2f55ff;
            --primary-light: #eef1ff;
            --success: #15803d;
            --danger: #c62828;
            --warning: #b45309;
            --border: #e0e4ef;
            --radius: 12px;
        }
        body { background: #f4f6fa; font-family: "Inter", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        .page-header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px; margin-bottom: 24px; }
        .page-title { font-size: 1.4rem; font-weight: 800; color: #1e293b; margin: 0; display: flex; align-items: center; gap: 10px; }
        .page-subtitle { font-size: .83rem; color: #64748b; margin-top: 2px; }

        .selector-card { background: #ffffff; border: 1px solid var(--border); border-radius: var(--radius); padding: 18px 24px; margin-bottom: 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.03); }
        .selector-form { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
        .selector-control { flex: 1; min-width: 280px; border: 1.5px solid #cbd5e1; border-radius: 8px; padding: 8px 14px; font-size: .9rem; font-weight: 600; color: #1e293b; outline: none; background: #fff; }
        .selector-control:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(47,85,255,0.12); }

        .meeting-meta-box { background: #ffffff; border: 1px solid var(--border); border-radius: var(--radius); padding: 20px; margin-bottom: 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.02); }
        .meta-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; }
        .meta-item { display: flex; flex-direction: column; }
        .meta-label { font-size: .75rem; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 2px; }
        .meta-val { font-size: 1.05rem; font-weight: 800; color: #0f172a; }

        /* KPI Cards Grid */
        .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .kpi-card { background: #ffffff; border: 1px solid var(--border); border-radius: var(--radius); padding: 16px 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.02); position: relative; overflow: hidden; }
        .kpi-card::before { content: ''; position: absolute; top: 0; left: 0; width: 4px; height: 100%; background: var(--primary); }
        .kpi-card.kpi-success::before { background: #10b981; }
        .kpi-card.kpi-warning::before { background: #f59e0b; }
        .kpi-card.kpi-danger::before { background: #ef4444; }
        .kpi-val { font-size: 1.6rem; font-weight: 800; color: #0f172a; }
        .kpi-lbl { font-size: .75rem; font-weight: 700; color: #64748b; text-transform: uppercase; }

        /* Matrix Table Styling */
        .matrix-wrapper { background: #ffffff; border: 1px solid var(--border); border-radius: var(--radius); padding: 20px; margin-bottom: 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.02); overflow: hidden; }
        .matrix-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; border-bottom: 1px solid #f1f5f9; padding-bottom: 10px; }
        .matrix-title { font-size: 1.05rem; font-weight: 800; color: #0f172a; margin: 0; display: flex; align-items: center; gap: 8px; }
        
        .matrix-table-scroll { overflow-x: auto; max-height: 520px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 8px; }
        .matrix-table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: .8rem; }
        .matrix-table th { background: #f8fafc; color: #475569; font-weight: 700; text-transform: uppercase; font-size: .7rem; padding: 10px 12px; border-bottom: 2px solid #cbd5e1; border-right: 1px solid #e2e8f0; position: sticky; top: 0; z-index: 2; white-space: nowrap; }
        .matrix-table th.sticky-col { position: sticky; left: 0; z-index: 3; background: #f1f5f9; border-right: 2px solid #cbd5e1; }
        .matrix-table td { padding: 8px 10px; border-bottom: 1px solid #f1f5f9; border-right: 1px solid #f1f5f9; text-align: center; vertical-align: middle; white-space: nowrap; }
        .matrix-table td.sticky-col { position: sticky; left: 0; background: #ffffff; z-index: 1; font-weight: 700; text-align: left; border-right: 2px solid #cbd5e1; }
        .matrix-table tr:hover td { background: #f8fafc; }
        .matrix-table tr:hover td.sticky-col { background: #f1f5f9; }

        .cell-badge { display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px; border-radius: 50%; font-size: .72rem; font-weight: 800; }
        .cell-p { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .cell-a { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .cell-i { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
        .cell-none { color: #cbd5e1; font-weight: 400; }

        /* MOM Points Timeline */
        .mom-timeline { position: relative; padding-left: 20px; border-left: 2px solid #e2e8f0; margin-top: 10px; }
        .mom-timeline-item { position: relative; margin-bottom: 20px; }
        .mom-timeline-dot { position: absolute; left: -27px; top: 2px; width: 12px; height: 12px; border-radius: 50%; background: var(--primary); border: 2px solid #fff; }
        .mom-timeline-date { font-size: .78rem; font-weight: 700; color: var(--primary); margin-bottom: 4px; display: flex; align-items: center; gap: 8px; }
        .mom-timeline-content { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 16px; font-size: .84rem; color: #334155; }
    </style>
</head>
<body>
<div class="container-scroller">
    <?php include('../partials/_navbar.php'); ?>
    <div class="container-fluid page-body-wrapper">
        <?php include('../partials/_sidebar.php'); ?>
        <div class="main-panel">
            <div class="content-wrapper">

                <!-- Header -->
                <div class="page-header">
                    <div>
                        <h3 class="page-title"><i class="fas fa-layer-group" style="color:var(--primary);"></i> Recurring Meeting Comparison &amp; Audit Report</h3>
                        <div class="page-subtitle">Session-by-Session Performance Matrix, Trend Analytics &amp; MOM Audit Logs</div>
                    </div>
                    <div style="display:flex; gap:10px;">
                        <a href="meetingDashboard.php" class="btn btn-outline-primary btn-sm" style="border-radius:8px; font-weight:600;"><i class="fas fa-chart-line mr-1"></i> Analytics Dashboard</a>
                        <a href="meetingHistory.php" class="btn btn-primary btn-sm" style="border-radius:8px; font-weight:600;"><i class="fas fa-calendar-alt mr-1"></i> Meeting History</a>
                    </div>
                </div>

                <!-- Meeting Selector Card -->
                <div class="selector-card">
                    <form method="GET" action="meetingComparisonReport.php" class="selector-form">
                        <label style="font-weight:700; font-size:.85rem; color:#475569; margin:0;"><i class="fas fa-video mr-1" style="color:var(--primary);"></i> Select Recurring Meeting:</label>
                        <select name="meeting_id" class="selector-control" onchange="this.form.submit()">
                            <?php if (empty($recurringMeetings)): ?>
                            <option value="">No recurring meetings found</option>
                            <?php else: ?>
                            <?php foreach ($recurringMeetings as $rm): ?>
                            <option value="<?= $rm['id'] ?>" <?= $selectedMeetingId==$rm['id']?'selected':'' ?>>
                                #<?= $rm['id'] ?> · <?= htmlspecialchars($rm['agenda']) ?> (<?= ucfirst($rm['recurrence_type']) ?> — <?= $rm['total_occurrences'] ?> sessions)
                            </option>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </form>
                </div>

                <?php if ($selectedMeeting): ?>

                <!-- Meeting Metadata Header -->
                <div class="meeting-meta-box">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:14px; flex-wrap:wrap; gap:10px;">
                        <div>
                            <h4 style="font-weight:800; color:#0f172a; margin:0; font-size:1.25rem;">
                                <?= htmlspecialchars($selectedMeeting['agenda']) ?>
                                <span class="badge badge-primary" style="font-size:.72rem; vertical-align:middle; margin-left:8px; border-radius:12px;">
                                    <i class="fas fa-redo mr-1"></i> <?= ucfirst($selectedMeeting['recurrence_type']) ?>
                                </span>
                            </h4>
                            <div style="font-size:.82rem; color:#64748b; margin-top:4px;">
                                Created by <strong><?= htmlspecialchars($selectedMeeting['creator_name'] ?? 'Admin') ?></strong> · Branch: <strong><?= htmlspecialchars($selectedMeeting['branch'] ?? 'All') ?></strong> · Mode: <strong><?= ucfirst($selectedMeeting['mode']) ?></strong>
                            </div>
                        </div>

                        <a href="meetingComparisonReport.php?action=export_matrix_csv&meeting_id=<?= $selectedMeetingId ?>" class="btn btn-success btn-sm" style="border-radius:8px; font-weight:600;">
                            <i class="fas fa-file-csv mr-1"></i> Export Matrix to CSV
                        </a>
                    </div>

                    <div class="meta-grid">
                        <div class="meta-item">
                            <span class="meta-label">Total Scheduled Slots</span>
                            <span class="meta-val"><?= $summaryStats['total_occs'] ?> sessions</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Completed Sessions</span>
                            <span class="meta-val" style="color:#10b981;"><?= $summaryStats['completed_occs'] ?> completed</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Total Participants</span>
                            <span class="meta-val" style="color:var(--primary);"><?= count($participantsList) ?> members</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Total Attendance Logs</span>
                            <span class="meta-val"><?= number_format($summaryStats['total_present'] + $summaryStats['total_absent'] + $summaryStats['total_intimated']) ?></span>
                        </div>
                    </div>
                </div>

                <!-- KPI Cards Grid -->
                <div class="kpi-grid">
                    <div class="kpi-card kpi-success">
                        <span class="kpi-lbl">Total Present Logs</span>
                        <div class="kpi-val" style="color:#059669;"><?= number_format($summaryStats['total_present']) ?></div>
                    </div>
                    <div class="kpi-card kpi-danger">
                        <span class="kpi-lbl">Unexcused Absences</span>
                        <div class="kpi-val" style="color:#dc2626;"><?= number_format($summaryStats['total_absent']) ?></div>
                    </div>
                    <div class="kpi-card kpi-warning">
                        <span class="kpi-lbl">Intimated Absences</span>
                        <div class="kpi-val" style="color:#d97706;"><?= number_format($summaryStats['total_intimated']) ?></div>
                    </div>
                </div>

                <!-- Session-by-Session Attendance Trend Chart -->
                <div class="matrix-wrapper">
                    <div class="matrix-header">
                        <h5 class="matrix-title"><i class="fas fa-chart-line" style="color:var(--primary);"></i> Session-by-Session Attendance Trend</h5>
                    </div>
                    <div style="position:relative; height:240px; width:100%;">
                        <canvas id="chartSessionTrend"></canvas>
                    </div>
                </div>

                <!-- Participant Attendance Matrix Table -->
                <div class="matrix-wrapper">
                    <div class="matrix-header">
                        <h5 class="matrix-title"><i class="fas fa-table" style="color:var(--primary);"></i> Participant Session Attendance Matrix</h5>
                        <div style="font-size:.78rem; color:#64748b;">
                            Legend: <span class="cell-badge cell-p" style="width:20px;height:20px;font-size:.65rem;display:inline-flex;">P</span> Present ·
                            <span class="cell-badge cell-a" style="width:20px;height:20px;font-size:.65rem;display:inline-flex;">A</span> Absent ·
                            <span class="cell-badge cell-i" style="width:20px;height:20px;font-size:.65rem;display:inline-flex;">I</span> Intimated
                        </div>
                    </div>

                    <div class="matrix-table-scroll">
                        <table class="matrix-table">
                            <thead>
                                <tr>
                                    <th class="sticky-col" style="min-width:200px;">Participant</th>
                                    <th style="min-width:90px;">Batch</th>
                                    <?php foreach ($occurrences as $occ): 
                                        $dayName = date('D', strtotime($occ['occurrence_date']));
                                        $dFmt = date('d M', strtotime($occ['occurrence_date']));
                                        $tFmt = date('h:i A', strtotime($occ['occurrence_time']));
                                    ?>
                                    <th title="<?= $dayName ?>, <?= $occ['occurrence_date'] ?> <?= $occ['occurrence_time'] ?>">
                                        <span style="color:var(--primary); font-weight:800; display:block; line-height:1.2; font-size:.75rem; uppercase;"><?= $dayName ?></span>
                                        <?= $dFmt ?><br><small style="font-weight:400;"><?= $tFmt ?></small>
                                    </th>
                                    <?php endforeach; ?>
                                    <th style="min-width:110px;">Score (%)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($participantsList)): ?>
                                <tr><td colspan="<?= count($occurrences) + 3 ?>" class="text-muted p-4">No participants found for this meeting.</td></tr>
                                <?php else: ?>
                                <?php foreach ($participantsList as $p): 
                                    $pId   = $p['userId'];
                                    $pPres = 0; $pAbs = 0; $pInt = 0;
                                    $pTot  = count($occurrences);
                                ?>
                                <tr>
                                    <td class="sticky-col">
                                        <?= htmlspecialchars($p['fullName']) ?>
                                        <small class="text-muted d-block" style="font-size:.7rem; font-weight:400;"><?= htmlspecialchars($p['display_id']) ?></small>
                                    </td>
                                    <td><span class="badge badge-light" style="border:1px solid #e2e8f0; font-size:.7rem;"><?= htmlspecialchars($p['batch_name']) ?></span></td>
                                    <?php foreach ($occurrences as $occ): 
                                        $st = $matrix[$pId][$occ['id']] ?? '';
                                        if ($st === 'present')          $pPres++;
                                        elseif ($st === 'absent')       $pAbs++;
                                        elseif ($st === 'absent_intimated') $pInt++;
                                    ?>
                                    <td>
                                        <?php if ($st === 'present'): ?>
                                            <span class="cell-badge cell-p" title="Present">P</span>
                                        <?php elseif ($st === 'absent'): ?>
                                            <span class="cell-badge cell-a" title="Absent">A</span>
                                        <?php elseif ($st === 'absent_intimated'): ?>
                                            <span class="cell-badge cell-i" title="Absent (Intimated)">I</span>
                                        <?php else: ?>
                                            <span class="cell-none">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php endforeach; ?>
                                    <td>
                                        <?php 
                                        $rate = $pTot > 0 ? round(($pPres / $pTot) * 100, 1) : 0;
                                        $badgeCls = $rate >= 85 ? 'badge-success' : ($rate >= 70 ? 'badge-warning' : 'badge-danger');
                                        ?>
                                        <span class="badge <?= $badgeCls ?>" style="font-size:.75rem;"><?= $rate ?>%</span>
                                        <small class="text-muted d-block" style="font-size:.65rem;"><?= $pPres ?>/<?= $pTot ?></small>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- MOM Discussion Points Timeline Audit Log -->
                <div class="matrix-wrapper">
                    <div class="matrix-header">
                        <h5 class="matrix-title"><i class="fas fa-clipboard-list" style="color:var(--primary);"></i> Minutes of Meeting (MOM) Session History Log</h5>
                    </div>

                    <?php if (empty($momLogs)): ?>
                    <p class="text-muted" style="font-size:.85rem;"><i class="fas fa-info-circle mr-1"></i> No MOM discussion points recorded for this recurring series yet.</p>
                    <?php else: ?>
                    <div class="mom-timeline">
                        <?php foreach ($momLogs as $mom): 
                            $pts = array_filter(array_map('trim', explode('||', $mom['discussion_points'])));
                        ?>
                        <div class="mom-timeline-item">
                            <div class="mom-timeline-dot"></div>
                            <div class="mom-timeline-date">
                                <span><i class="fas fa-calendar-check"></i> Session: <?= date('d M Y', strtotime($mom['occurrence_date'])) ?> at <?= date('h:i A', strtotime($mom['occurrence_time'])) ?></span>
                                <?php if ($mom['mom_finalised']): ?>
                                <span class="badge badge-success" style="font-size:.68rem; font-weight:600;"><i class="fas fa-lock mr-1"></i> Finalized</span>
                                <?php endif; ?>
                            </div>
                            <div class="mom-timeline-content">
                                <ul style="margin:0; padding-left:18px;">
                                    <?php foreach ($pts as $pt): ?>
                                    <li style="margin-bottom:4px;"><?= htmlspecialchars($pt) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                                <div style="font-size:.72rem; color:#94a3b8; margin-top:8px; font-style:italic;">
                                    Logged by <?= htmlspecialchars($mom['author_name'] ?? 'Admin') ?> on <?= date('d M Y, h:i A', strtotime($mom['created_at'])) ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <?php endif; ?>

            </div>
        </div>
    </div>
</div>

<script src="../vendors/js/vendor.bundle.base.js"></script>
<script>
$(document).ready(function(){
    <?php if ($selectedMeeting && !empty($occurrencesChartData)): ?>
    var labels    = <?= json_encode(array_column($occurrencesChartData, 'label')) ?>;
    var pData     = <?= json_encode(array_column($occurrencesChartData, 'present')) ?>;
    var aData     = <?= json_encode(array_column($occurrencesChartData, 'absent')) ?>;
    var iData     = <?= json_encode(array_column($occurrencesChartData, 'intimated')) ?>;

    var ctx = document.getElementById('chartSessionTrend').getContext('2d');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Present',
                    data: pData,
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    tension: 0.3,
                    fill: true
                },
                {
                    label: 'Absent (Unexcused)',
                    data: aData,
                    borderColor: '#ef4444',
                    backgroundColor: 'rgba(239, 68, 68, 0.1)',
                    tension: 0.3,
                    fill: true
                },
                {
                    label: 'Absent (Intimated)',
                    data: iData,
                    borderColor: '#f59e0b',
                    backgroundColor: 'rgba(245, 158, 11, 0.1)',
                    tension: 0.3,
                    fill: true
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: { beginAtZero: true, precision: 0 }
            },
            plugins: {
                legend: { position: 'bottom' }
            }
        }
    });
    <?php endif; ?>
});
</script>
</body>
</html>
