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
$userRoleVal  = strtolower(str_replace([' ', '_'], '', $userRow['userRole'] ?? ''));
$userDesigVal = strtolower(str_replace([' ', '_'], '', $userRow['designation'] ?? ''));
$userName     = $userRow['fullName'] ?? 'User';

// Access control: Admin, HR, Resource Head, Team Lead, Senior Intern
$rhRoles  = ['hr', 'admin', 'superadmin', 'resourcehead', 'resourceallocator', 'resourcemanager'];
$leadRoles = ['teamlead', 'seniorintern', 'projectmanager', 'pm'];

$isHR     = in_array($userRoleVal, $rhRoles) || in_array($userDesigVal, $rhRoles);
$isLeader = in_array($userRoleVal, $leadRoles) || in_array($userDesigVal, $leadRoles);
if (!$isHR && !$isLeader) {
    echo "<script>alert('Access Denied: You do not have permission to view KRA Analytics Dashboard.'); window.location.href = 'intern_kra_list.php';</script>";
    exit;
}

// ── GET FILTERS ──
$filterBatch = isset($_GET['batch']) ? trim($_GET['batch']) : 'all';
$filterOffice = isset($_GET['office']) ? trim($_GET['office']) : 'all';
$filterWeek   = isset($_GET['week']) ? trim($_GET['week']) : 'all';
$filterYear   = isset($_GET['year']) ? trim($_GET['year']) : 'all';
$filterLead   = isset($_GET['lead']) ? trim($_GET['lead']) : 'all';

// Fetch filter option lists
$batchesList = [];
$bQ = mysqli_query($con, "SELECT DISTINCT batch FROM internKraAssignments WHERE batch IS NOT NULL AND TRIM(batch) != '' ORDER BY batch ASC");
if ($bQ) { while ($b = mysqli_fetch_assoc($bQ)) $batchesList[] = $b['batch']; }

$officesList = [];
$oQ = mysqli_query($con, "SELECT DISTINCT office FROM internKraAssignments WHERE office IS NOT NULL AND TRIM(office) != '' ORDER BY office ASC");
if ($oQ) { while ($o = mysqli_fetch_assoc($oQ)) $officesList[] = $o['office']; }

$leadsList = [];
$lQ = mysqli_query($con, "SELECT DISTINCT submittedBy, submittedByName FROM internKraAssignments WHERE submittedByName IS NOT NULL AND TRIM(submittedByName) != '' ORDER BY submittedByName ASC");
if ($lQ) { while ($l = mysqli_fetch_assoc($lQ)) $leadsList[] = $l; }

$weeksList = [];
$wQ = mysqli_query($con, "SELECT DISTINCT weekNumber, weekYear FROM internKraAssignments WHERE status = 'active' ORDER BY weekYear DESC, weekNumber DESC");
if ($wQ) { while ($w = mysqli_fetch_assoc($wQ)) $weeksList[] = $w; }

// ── BUILD WHERE CLAUSE ──
$where = "WHERE status = 'active'";
if ($filterBatch !== 'all') {
    $escapedBatch = mysqli_real_escape_string($con, $filterBatch);
    $where .= " AND batch = '$escapedBatch'";
}
if ($filterOffice !== 'all') {
    $escapedOffice = mysqli_real_escape_string($con, $filterOffice);
    $where .= " AND office = '$escapedOffice'";
}
if ($filterWeek !== 'all') {
    $escapedWeek = (int)$filterWeek;
    $where .= " AND weekNumber = $escapedWeek";
}
if ($filterYear !== 'all') {
    $escapedYear = (int)$filterYear;
    $where .= " AND weekYear = $escapedYear";
}
if ($filterLead !== 'all') {
    $escapedLead = mysqli_real_escape_string($con, $filterLead);
    $where .= " AND submittedBy = '$escapedLead'";
}

// ── METRIC 0: PENDING KRA EVALUATIONS FOR SELECTED / CURRENT WEEK ──
$targetWeek = ($filterWeek !== 'all') ? (int)$filterWeek : (int)($weeksList[0]['weekNumber'] ?? date('W'));
$targetYear = ($filterYear !== 'all') ? (int)$filterYear : (int)($weeksList[0]['weekYear'] ?? date('Y'));

$internWhere = "WHERE status = 'Active' AND (LOWER(designation) IN ('juniorintern', 'junior_intern') OR LOWER(userRole) = 'junior_intern')";
if ($filterBatch !== 'all') {
    $escB = mysqli_real_escape_string($con, $filterBatch);
    $internWhere .= " AND (LOWER(batch_number) = LOWER('$escB') OR userId IN (SELECT internId FROM internKraAssignments WHERE LOWER(batch) = LOWER('$escB')))";
}
if ($filterOffice !== 'all') {
    $escO = mysqli_real_escape_string($con, $filterOffice);
    $internWhere .= " AND LOWER(office) = LOWER('$escO')";
}

$eligibleInternsQ = mysqli_query($con, "SELECT userId, fullName, batch_number AS batch, office, designation FROM signup $internWhere ORDER BY fullName ASC");
$eligibleInterns = [];
if ($eligibleInternsQ) {
    while ($ig = mysqli_fetch_assoc($eligibleInternsQ)) {
        $eligibleInterns[$ig['userId']] = $ig;
    }
}
$totalEligibleCount = count($eligibleInterns);

$submittedIdsQ = mysqli_query($con, "
    SELECT DISTINCT internId 
    FROM internKraAssignments 
    WHERE status = 'active' 
      AND weekNumber = $targetWeek 
      AND weekYear = $targetYear
");
$submittedInternIds = [];
if ($submittedIdsQ) {
    while ($sub = mysqli_fetch_assoc($submittedIdsQ)) {
        $submittedInternIds[$sub['internId']] = true;
    }
}

$pendingInternsList = [];
foreach ($eligibleInterns as $uId => $iInfo) {
    if (!isset($submittedInternIds[$uId])) {
        $pendingInternsList[] = $iInfo;
    }
}
$pendingKraCount = count($pendingInternsList);

// ── METRIC 1: EXECUTIVE KPI SUMMARY COUNTS ──
$summaryStats = mysqli_fetch_assoc(mysqli_query($con, "
    SELECT 
        COUNT(kraId) as total_kras,
        COUNT(DISTINCT internId) as total_interns,
        AVG(averagePercentage) as avg_score,
        AVG(communication) as avg_comm,
        AVG(confidence) as avg_conf,
        AVG(clarity) as avg_clar,
        AVG(teamwork) as avg_team,
        AVG(problemSolving) as avg_prob,
        AVG(learningAttitude) as avg_learn,
        AVG(technicalKnowledge) as avg_tech,
        AVG(githubActivity) as avg_github,
        AVG(amsProcessAdherence) as avg_ams,
        SUM(CASE WHEN averagePercentage >= 85 THEN 1 ELSE 0 END) as top_performers_cnt,
        SUM(CASE WHEN averagePercentage < 65 THEN 1 ELSE 0 END) as low_performers_cnt
    FROM internKraAssignments
    $where
"));

$totalKras       = (int)($summaryStats['total_kras'] ?? 0);
$totalInterns    = (int)($summaryStats['total_interns'] ?? 0);
$overallAvgScore = round((float)($summaryStats['avg_score'] ?? 0), 1);
$topPerformers   = (int)($summaryStats['top_performers_cnt'] ?? 0);
$lowPerformers   = (int)($summaryStats['low_performers_cnt'] ?? 0);

// Parameter averages (1-10 scale converted to 10-point and percentage)
$paramsAvg = [
    'Communication'           => round((float)($summaryStats['avg_comm'] ?? 0), 2),
    'Confidence'              => round((float)($summaryStats['avg_conf'] ?? 0), 2),
    'Clarity'                 => round((float)($summaryStats['avg_clar'] ?? 0), 2),
    'Teamwork'                => round((float)($summaryStats['avg_team'] ?? 0), 2),
    'Problem Solving'         => round((float)($summaryStats['avg_prob'] ?? 0), 2),
    'Learning Attitude'       => round((float)($summaryStats['avg_learn'] ?? 0), 2),
    'Technical Knowledge'     => round((float)($summaryStats['avg_tech'] ?? 0), 2),
    'GitHub Usage'            => round((float)($summaryStats['avg_github'] ?? 0), 2),
    'Following AMS Processes' => round((float)($summaryStats['avg_ams'] ?? 0), 2),
];

// Determine Highest & Lowest Parameter
asort($paramsAvg);
$lowestParamName  = key($paramsAvg);
$lowestParamScore = current($paramsAvg);
arsort($paramsAvg);
$highestParamName  = key($paramsAvg);
$highestParamScore = current($paramsAvg);

// ── METRIC 2: BATCH-WISE PERFORMANCE COMPARISON ──
$batchChartData = [];
$batchCompareQ = mysqli_query($con, "
    SELECT 
        COALESCE(NULLIF(TRIM(batch), ''), 'Unassigned') as batch_name,
        COUNT(kraId) as eval_cnt,
        AVG(averagePercentage) as avg_batch_score
    FROM internKraAssignments
    $where
    GROUP BY batch_name
    ORDER BY avg_batch_score DESC
");
if ($batchCompareQ) {
    while ($bRow = mysqli_fetch_assoc($batchCompareQ)) {
        $batchChartData[] = [
            'batch' => $bRow['batch_name'],
            'score' => round((float)$bRow['avg_batch_score'], 1),
            'count' => (int)$bRow['eval_cnt']
        ];
    }
}

// ── METRIC 3: WEEKLY SCORE PROGRESSION TREND ──
$weeklyTrendData = [];
$trendQ = mysqli_query($con, "
    SELECT 
        weekNumber, weekYear,
        AVG(averagePercentage) as avg_week_score,
        COUNT(kraId) as week_eval_cnt
    FROM internKraAssignments
    $where
    GROUP BY weekYear, weekNumber
    ORDER BY weekYear ASC, weekNumber ASC
    LIMIT 20
");
if ($trendQ) {
    while ($tRow = mysqli_fetch_assoc($trendQ)) {
        $weeklyTrendData[] = [
            'label' => 'W' . $tRow['weekNumber'] . ' (' . $tRow['weekYear'] . ')',
            'score' => round((float)$tRow['avg_week_score'], 1),
            'count' => (int)$tRow['week_eval_cnt']
        ];
    }
}

// ── METRIC 4: MENTORSHIP & TRAINING WATCHLIST (< 65% Score) ──
$watchlist = [];
$watchQ = mysqli_query($con, "
    SELECT 
        internId, internName, batch, office, averagePercentage,
        communication, confidence, clarity, teamwork, problemSolving, learningAttitude, technicalKnowledge,
        submittedByName, weekNumber, weekYear, submittedAt
    FROM internKraAssignments
    $where AND averagePercentage < 65
    ORDER BY averagePercentage ASC, submittedAt DESC
");
if ($watchQ) {
    while ($wItem = mysqli_fetch_assoc($watchQ)) {
        // Find parameters scoring < 6 out of 10
        $weakSkills = [];
        if ($wItem['communication'] < 6)       $weakSkills[] = 'Communication (' . $wItem['communication'] . '/10)';
        if ($wItem['confidence'] < 6)          $weakSkills[] = 'Confidence (' . $wItem['confidence'] . '/10)';
        if ($wItem['clarity'] < 6)             $weakSkills[] = 'Clarity (' . $wItem['clarity'] . '/10)';
        if ($wItem['teamwork'] < 6)            $weakSkills[] = 'Teamwork (' . $wItem['teamwork'] . '/10)';
        if ($wItem['problemSolving'] < 6)      $weakSkills[] = 'Problem Solving (' . $wItem['problemSolving'] . '/10)';
        if ($wItem['learningAttitude'] < 6)    $weakSkills[] = 'Learning Attitude (' . $wItem['learningAttitude'] . '/10)';
        if ($wItem['technicalKnowledge'] < 6)  $weakSkills[] = 'Technical Knowledge (' . $wItem['technicalKnowledge'] . '/10)';
        if (($wItem['githubActivity'] ?? 10) < 6) $weakSkills[] = 'GitHub Usage (' . ($wItem['githubActivity'] ?? 0) . '/10)';
        if (($wItem['amsProcessAdherence'] ?? 10) < 6) $weakSkills[] = 'Following AMS Processes (' . ($wItem['amsProcessAdherence'] ?? 0) . '/10)';
        
        $wItem['weak_skills'] = $weakSkills;
        $watchlist[] = $wItem;
    }
}

// ── METRIC 5: FULL INTERN KRA PERFORMANCE LIST ──
$kraRecords = [];
$recordsQ = mysqli_query($con, "
    SELECT 
        kraId, internId, internName, batch, mode, office, averagePercentage,
        submittedByName, weekNumber, weekYear, submittedAt
    FROM internKraAssignments
    $where
    ORDER BY submittedAt DESC
");
if ($recordsQ) {
    while ($r = mysqli_fetch_assoc($recordsQ)) {
        $kraRecords[] = $r;
    }
}

// Export CSV Action
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=intern_kra_analytics_report_' . date('Y-m-d') . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['KRA ID', 'Intern Name', 'Intern ID', 'Batch', 'Office', 'Mode', 'Week', 'Year', 'Avg Score (%)', 'Evaluated By', 'Submitted Date']);
    foreach ($kraRecords as $row) {
        fputcsv($output, [
            $row['kraId'],
            $row['internName'],
            $row['internId'],
            $row['batch'],
            $row['office'],
            $row['mode'],
            'W' . $row['weekNumber'],
            $row['weekYear'],
            $row['averagePercentage'] . '%',
            $row['submittedByName'],
            date('d M Y, h:i A', strtotime($row['submittedAt']))
        ]);
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
    <title>SETTribe · Intelligent KRA Analytical Dashboard</title>
    <?php include('../partials/_links.php'); ?>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #4f46e5;
            --primary-light: #eef2ff;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --border: #e2e8f0;
            --radius: 12px;
        }
        body { background: #f8fafc; font-family: "Inter", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        
        .page-header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px; margin-bottom: 24px; }
        .page-title { font-size: 1.45rem; font-weight: 800; color: #0f172a; margin: 0; display: flex; align-items: center; gap: 10px; }
        .page-subtitle { font-size: .83rem; color: #64748b; margin-top: 2px; }

        /* Filter Card */
        .filter-card { background: #ffffff; border: 1px solid var(--border); border-radius: var(--radius); padding: 16px 20px; margin-bottom: 24px; box-shadow: 0 2px 8px rgba(0,0,0,0.02); }
        .filter-form { display: flex; align-items: center; flex-wrap: wrap; gap: 14px; }
        .filter-group { display: flex; flex-direction: column; gap: 4px; }
        .filter-group label { font-size: .74rem; font-weight: 700; color: #475569; text-transform: uppercase; margin: 0; }
        .filter-control { border: 1px solid #cbd5e1; border-radius: 8px; padding: 6px 12px; font-size: .83rem; color: #334155; outline: none; background: #fff; }
        .filter-control:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(79,70,229,0.12); }
        .btn-filter-apply { background: var(--primary); color: #fff; border: none; border-radius: 8px; padding: 8px 18px; font-size: .83rem; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
        .btn-filter-apply:hover { background: #4338ca; }
        .btn-export { background: #10b981; color: #fff; border: none; border-radius: 8px; padding: 8px 18px; font-size: .83rem; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; margin-left: auto; }
        .btn-export:hover { background: #059669; color: #fff; text-decoration: none; }

        /* KPI Cards Grid */
        .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .kpi-card { background: #ffffff; border: 1px solid var(--border); border-radius: var(--radius); padding: 18px 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.02); position: relative; overflow: hidden; }
        .kpi-card::before { content: ''; position: absolute; top: 0; left: 0; width: 4px; height: 100%; background: var(--primary); }
        .kpi-card.kpi-success::before { background: #10b981; }
        .kpi-card.kpi-purple::before { background: #9333ea; }
        .kpi-card.kpi-warning::before { background: #f59e0b; }
        .kpi-card.kpi-danger::before { background: #ef4444; }

        .kpi-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
        .kpi-lbl { font-size: .75rem; font-weight: 700; text-transform: uppercase; color: #64748b; }
        .kpi-icon { width: 34px; height: 34px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: .95rem; background: var(--primary-light); color: var(--primary); }
        .kpi-val { font-size: 1.7rem; font-weight: 800; color: #0f172a; line-height: 1.2; }
        .kpi-subtext { font-size: .75rem; color: #94a3b8; margin-top: 4px; }

        /* Charts Grid */
        .charts-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 20px; margin-bottom: 24px; }
        .chart-card { background: #ffffff; border: 1px solid var(--border); border-radius: var(--radius); padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.02); }
        .chart-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; border-bottom: 1px solid #f1f5f9; padding-bottom: 10px; }
        .chart-title { font-size: .95rem; font-weight: 700; color: #1e293b; margin: 0; display: flex; align-items: center; gap: 8px; }
        .chart-container { position: relative; height: 260px; width: 100%; }

        /* Section Cards */
        .section-card { background: #ffffff; border: 1px solid var(--border); border-radius: var(--radius); padding: 20px; margin-bottom: 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.02); }
        .section-card-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; border-bottom: 1px solid #f1f5f9; padding-bottom: 12px; }
        .section-card-title { font-size: 1.05rem; font-weight: 800; color: #0f172a; margin: 0; display: flex; align-items: center; gap: 8px; }

        /* Table Styling */
        .data-table { width: 100%; border-collapse: collapse; font-size: .84rem; }
        .data-table th { background: #f8fafc; color: #475569; font-weight: 700; text-transform: uppercase; font-size: .73rem; padding: 10px 14px; border-bottom: 2px solid #e2e8f0; text-align: left; }
        .data-table td { padding: 12px 14px; border-bottom: 1px solid #f1f5f9; color: #334155; vertical-align: middle; }
        .data-table tr:hover { background: #f8fafc; }

        .watchlist-item { display: flex; align-items: flex-start; justify-content: space-between; padding: 14px 16px; border: 1px solid #fee2e2; background: #fff5f5; border-radius: 10px; margin-bottom: 10px; flex-wrap: wrap; gap: 10px; }
        .watchlist-user { display: flex; align-items: center; gap: 12px; }
        .watchlist-avatar { width: 38px; height: 38px; border-radius: 50%; background: #ef4444; color: #fff; font-weight: 800; font-size: .9rem; display: flex; align-items: center; justify-content: center; }
        .watchlist-name { font-weight: 700; color: #991b1b; font-size: .9rem; }
        .watchlist-meta { font-size: .76rem; color: #7f1d1d; }
        .weak-tag { display: inline-block; background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; font-size: .7rem; font-weight: 700; padding: 2px 8px; border-radius: 12px; margin-right: 4px; margin-top: 4px; }
    </style>
</head>
<body>
<div class="container-scroller">
    <?php include('../partials/_navbar.php'); ?>
    <div class="container-fluid page-body-wrapper">
        <?php include('../partials/_sidebar.php'); ?>
        <div class="main-panel">
            <div class="content-wrapper">

                <!-- Page Header -->
                <div class="page-header">
                    <div>
                        <h3 class="page-title"><i class="fas fa-brain" style="color:var(--primary);"></i> Intelligent KRA Analytical Dashboard</h3>
                        <div class="page-subtitle">7-Pillar Competency Insights, Performance Radar &amp; Mentorship Intelligence</div>
                    </div>
                    <div style="display:flex; gap:10px;">
                        <a href="intern_kra_list.php" class="btn btn-outline-primary btn-sm" style="border-radius:8px; font-weight:600;"><i class="fas fa-list-alt mr-1"></i> KRA Submissions List</a>
                        <a href="intern_kra_form.php" class="btn btn-primary btn-sm" style="border-radius:8px; font-weight:600;"><i class="fas fa-plus-circle mr-1"></i> Submit New KRA</a>
                    </div>
                </div>

                <!-- Filter Bar -->
                <div class="filter-card">
                    <form method="GET" action="kraDashboard.php" class="filter-form">
                        <div class="filter-group">
                            <label><i class="fas fa-layer-group mr-1"></i> Batch</label>
                            <select name="batch" class="filter-control">
                                <option value="all">All Batches</option>
                                <?php foreach ($batchesList as $b): ?>
                                <option value="<?= htmlspecialchars($b) ?>" <?= $filterBatch===$b?'selected':'' ?>><?= htmlspecialchars($b) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label><i class="fas fa-building mr-1"></i> Office</label>
                            <select name="office" class="filter-control">
                                <option value="all">All Offices</option>
                                <?php foreach ($officesList as $o): ?>
                                <option value="<?= htmlspecialchars($o) ?>" <?= $filterOffice===$o?'selected':'' ?>><?= htmlspecialchars($o) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label><i class="fas fa-user-check mr-1"></i> Evaluated By</label>
                            <select name="lead" class="filter-control">
                                <option value="all">All Evaluators</option>
                                <?php foreach ($leadsList as $l): ?>
                                <option value="<?= htmlspecialchars($l['submittedBy']) ?>" <?= $filterLead===$l['submittedBy']?'selected':'' ?>><?= htmlspecialchars($l['submittedByName']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label><i class="fas fa-calendar-alt mr-1"></i> Week / Year</label>
                            <select name="week" class="filter-control">
                                <option value="all">All Weeks</option>
                                <?php foreach ($weeksList as $w): ?>
                                <option value="<?= $w['weekNumber'] ?>" <?= $filterWeek==$w['weekNumber']?'selected':'' ?>>W<?= $w['weekNumber'] ?> (<?= $w['weekYear'] ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-group" style="align-self:flex-end;">
                            <button type="submit" class="btn-filter-apply"><i class="fas fa-filter"></i> Apply Filters</button>
                        </div>

                        <a href="kraDashboard.php?action=export_csv&<?= http_build_query($_GET) ?>" class="btn-export" title="Download Excel/CSV Report">
                            <i class="fas fa-file-csv"></i> Export CSV Report
                        </a>
                    </form>
                </div>

                <!-- KPI Cards Grid -->
                <div class="kpi-grid">
                    <div class="kpi-card kpi-info">
                        <div class="kpi-header">
                            <span class="kpi-lbl">Total KRA Evaluations</span>
                            <div class="kpi-icon"><i class="fas fa-clipboard-check"></i></div>
                        </div>
                        <div class="kpi-val"><?= number_format($totalKras) ?></div>
                        <div class="kpi-subtext"><?= number_format($totalInterns) ?> distinct interns evaluated</div>
                    </div>

                    <div class="kpi-card kpi-success">
                        <div class="kpi-header">
                            <span class="kpi-lbl">Overall Average Score</span>
                            <div class="kpi-icon"><i class="fas fa-star"></i></div>
                        </div>
                        <div class="kpi-val" style="color:#10b981;"><?= $overallAvgScore ?>%</div>
                        <div class="kpi-subtext"><?= $topPerformers ?> interns scoring &ge;85%</div>
                    </div>

                    <div class="kpi-card kpi-purple">
                        <div class="kpi-header">
                            <span class="kpi-lbl">Pending Fillings (W<?= $targetWeek ?>)</span>
                            <div class="kpi-icon" style="background:#f3e8ff; color:#9333ea;"><i class="fas fa-hourglass-half"></i></div>
                        </div>
                        <div class="kpi-val" style="color:#9333ea;"><?= number_format($pendingKraCount) ?></div>
                        <div class="kpi-subtext">
                            <a href="#pendingKraModal" data-toggle="modal" style="color:#7e22ce; font-weight:700; text-decoration:underline;">
                                <?= number_format($pendingKraCount) ?> of <?= number_format($totalEligibleCount) ?> interns pending
                            </a>
                        </div>
                    </div>

                    <div class="kpi-card kpi-warning">
                        <div class="kpi-header">
                            <span class="kpi-lbl">Top Strength Area</span>
                            <div class="kpi-icon"><i class="fas fa-award"></i></div>
                        </div>
                        <div class="kpi-val" style="font-size:1.15rem; color:#d97706; padding-top:4px;"><?= htmlspecialchars($highestParamName) ?></div>
                        <div class="kpi-subtext">Avg Rating: <strong><?= $highestParamScore ?>/10</strong></div>
                    </div>

                    <div class="kpi-card kpi-danger">
                        <div class="kpi-header">
                            <span class="kpi-lbl">Critical Training Focus</span>
                            <div class="kpi-icon"><i class="fas fa-exclamation-circle"></i></div>
                        </div>
                        <div class="kpi-val" style="font-size:1.15rem; color:#dc2626; padding-top:4px;"><?= htmlspecialchars($lowestParamName) ?></div>
                        <div class="kpi-subtext">Avg Rating: <strong><?= $lowestParamScore ?>/10</strong> · <?= $lowPerformers ?> interns &lt;65%</div>
                    </div>
                </div>

                <!-- Charts Grid -->
                <div class="charts-grid">
                    <!-- Donut / Radar: 7-Pillar Competency Radar -->
                    <div class="chart-card">
                        <div class="chart-header">
                            <h5 class="chart-title"><i class="fas fa-spider" style="color:var(--primary);"></i> 7-Pillar Skill Competency Radar (Out of 10)</h5>
                        </div>
                        <div class="chart-container">
                            <canvas id="chartKraRadar"></canvas>
                        </div>
                    </div>

                    <!-- Bar Chart: Batch-wise Comparison -->
                    <div class="chart-card">
                        <div class="chart-header">
                            <h5 class="chart-title"><i class="fas fa-chart-bar" style="color:#10b981;"></i> Batch Average KRA Performance (%)</h5>
                        </div>
                        <div class="chart-container">
                            <canvas id="chartBatchCompare"></canvas>
                        </div>
                    </div>

                    <!-- Line Chart: Weekly Progression Trend -->
                    <div class="chart-card" style="grid-column: 1 / -1;">
                        <div class="chart-header">
                            <h5 class="chart-title"><i class="fas fa-chart-line" style="color:#f59e0b;"></i> Weekly KRA Score Trajectory Trend</h5>
                        </div>
                        <div class="chart-container" style="height:220px;">
                            <canvas id="chartWeeklyTrend"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Mentorship & Training Watchlist Card -->
                <?php if (!empty($watchlist)): ?>
                <div class="section-card" style="border-left:4px solid #ef4444;">
                    <div class="section-card-header">
                        <h4 class="section-card-title" style="color:#991b1b;"><i class="fas fa-user-graduate" style="color:#ef4444;"></i> Targeted Training &amp; Mentorship Watchlist (&lt;65% Score)</h4>
                        <span class="badge badge-danger"><?= count($watchlist) ?> Interns Require Training</span>
                    </div>
                    <div>
                        <?php foreach ($watchlist as $wItem): ?>
                        <div class="watchlist-item">
                            <div class="watchlist-user">
                                <div class="watchlist-avatar"><?= strtoupper(substr($wItem['internName'], 0, 1)) ?></div>
                                <div>
                                    <div class="watchlist-name"><?= htmlspecialchars($wItem['internName']) ?> <small class="text-muted">(<?= htmlspecialchars($wItem['internId']) ?>)</small></div>
                                    <div class="watchlist-meta">Batch: <strong><?= htmlspecialchars($wItem['batch']) ?></strong> · Office: <strong><?= htmlspecialchars($wItem['office']) ?></strong> · Evaluator: <strong><?= htmlspecialchars($wItem['submittedByName']) ?></strong> (W<?= $wItem['weekNumber'] ?>)</div>
                                    <div style="margin-top:2px;">
                                        <?php foreach ($wItem['weak_skills'] as $ws): ?>
                                        <span class="weak-tag"><?= htmlspecialchars($ws) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <span class="badge badge-danger" style="font-size:.85rem; padding:6px 12px; border-radius:12px; font-weight:800;"><?= $wItem['averagePercentage'] ?>% Score</span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Full Intern KRA Performance Table -->
                <div class="section-card">
                    <div class="section-card-header">
                        <h4 class="section-card-title"><i class="fas fa-table" style="color:var(--primary);"></i> Intern KRA Evaluations Master Grid</h4>
                        <span class="text-muted" style="font-size:.8rem;"><?= count($kraRecords) ?> total evaluation records</span>
                    </div>
                    <div class="table-responsive">
                        <table class="data-table" id="kraTable">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Intern Name</th>
                                    <th>ID</th>
                                    <th>Batch</th>
                                    <th>Office</th>
                                    <th>Week</th>
                                    <th>Score (%)</th>
                                    <th>Evaluated By</th>
                                    <th>Evaluation Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($kraRecords as $idx => $r): 
                                    $sc = (float)$r['averagePercentage'];
                                    $bCls = $sc >= 85 ? 'badge-success' : ($sc >= 65 ? 'badge-warning' : 'badge-danger');
                                ?>
                                <tr>
                                    <td class="text-muted"><?= $idx + 1 ?></td>
                                    <td><strong><?= htmlspecialchars($r['internName']) ?></strong></td>
                                    <td><code><?= htmlspecialchars($r['internId']) ?></code></td>
                                    <td><span class="badge badge-light" style="border:1px solid #cbd5e1;"><?= htmlspecialchars($r['batch']) ?></span></td>
                                    <td><?= htmlspecialchars($r['office']) ?></td>
                                    <td><strong>W<?= $r['weekNumber'] ?></strong> <small class="text-muted">(<?= $r['weekYear'] ?>)</small></td>
                                    <td><span class="badge <?= $bCls ?>" style="font-size:.78rem; padding:4px 9px; border-radius:12px;"><?= $sc ?>%</span></td>
                                    <td><?= htmlspecialchars($r['submittedByName']) ?></td>
                                    <td class="text-muted" style="font-size:.78rem;"><?= date('d M Y, h:i A', strtotime($r['submittedAt'])) ?></td>
                                    <td>
                                        <a href="intern_kra_list.php?filter_intern=<?= urlencode($r['internName']) ?>" class="btn btn-outline-primary btn-sm" style="font-size:.72rem; padding:2px 8px; border-radius:6px;">View Logs</a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<script src="../vendors/js/vendor.bundle.base.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>

<script>
$(document).ready(function() {
    $('#kraTable').DataTable({
        "pageLength": 15,
        "order": [[ 6, "desc" ]],
        "language": { "search": "Search Intern / Evaluator / Batch:" }
    });

    // ── Chart 1: 7-Pillar Competency Radar ──
    var radarLabels = <?= json_encode(array_keys($paramsAvg)) ?>;
    var radarData   = <?= json_encode(array_values($paramsAvg)) ?>;

    var ctxRadar = document.getElementById('chartKraRadar').getContext('2d');
    new Chart(ctxRadar, {
        type: 'radar',
        data: {
            labels: radarLabels,
            datasets: [{
                label: 'Average Score (Out of 10)',
                data: radarData,
                backgroundColor: 'rgba(79, 70, 229, 0.2)',
                borderColor: 'var(--primary)',
                borderWidth: 2,
                pointBackgroundColor: 'var(--primary)',
                pointRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                r: {
                    angleLines: { display: true },
                    suggestedMin: 0,
                    suggestedMax: 10
                }
            },
            plugins: { legend: { display: false } }
        }
    });

    // ── Chart 2: Batch Comparison (Bar Chart) ──
    var batchLabels = <?= json_encode(array_column($batchChartData, 'batch')) ?>;
    var batchScores  = <?= json_encode(array_column($batchChartData, 'score')) ?>;

    var ctxBatch = document.getElementById('chartBatchCompare').getContext('2d');
    new Chart(ctxBatch, {
        type: 'bar',
        data: {
            labels: batchLabels.length ? batchLabels : ['No Data'],
            datasets: [{
                label: 'Average KRA Score (%)',
                data: batchScores.length ? batchScores : [0],
                backgroundColor: 'rgba(16, 185, 129, 0.75)',
                borderColor: '#10b981',
                borderWidth: 1.5,
                borderRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: { beginAtZero: true, max: 100, title: { display: true, text: 'Score (%)' } }
            },
            plugins: { legend: { display: false } }
        }
    });

    // ── Chart 3: Weekly Trend (Line Chart) ──
    var trendLabels = <?= json_encode(array_column($weeklyTrendData, 'label')) ?>;
    var trendScores = <?= json_encode(array_column($weeklyTrendData, 'score')) ?>;

    var ctxTrend = document.getElementById('chartWeeklyTrend').getContext('2d');
    new Chart(ctxTrend, {
        type: 'line',
        data: {
            labels: trendLabels.length ? trendLabels : ['No Trend'],
            datasets: [{
                label: 'Average KRA Score (%)',
                data: trendScores.length ? trendScores : [0],
                borderColor: '#f59e0b',
                backgroundColor: 'rgba(245, 158, 11, 0.1)',
                tension: 0.3,
                fill: true
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: { beginAtZero: true, max: 100, title: { display: true, text: 'Score (%)' } }
            },
            plugins: { legend: { display: false } }
        }
    });
});
</script>

<!-- Modal: Pending KRA Fillings List -->
<div class="modal fade" id="pendingKraModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content" style="border-radius:16px; border:none; overflow:hidden;">
            <div class="modal-header" style="background:linear-gradient(135deg, #9333ea, #7e22ce); color:#fff; padding:16px 24px;">
                <h5 class="modal-title" style="font-weight:700; color:#fff; font-size:1.1rem;">
                    <i class="fas fa-user-clock mr-2"></i> Pending KRA Fillings — Week <?= $targetWeek ?> (<?= $targetYear ?>)
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close" style="color:#fff; opacity:0.9;">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body" style="padding:20px 24px; max-height:70vh; overflow-y:auto;">
                <p style="font-size:0.85rem; color:#64748b; margin-bottom:16px;">
                    Below is the list of active interns who have not been evaluated for <strong>Week <?= $targetWeek ?></strong> (Total Pending: <strong><?= $pendingKraCount ?></strong>).
                </p>
                <?php if (!empty($pendingInternsList)): ?>
                <table class="table table-hover table-bordered" style="font-size:0.85rem;">
                    <thead style="background:#f8fafc; color:#475569;">
                        <tr>
                            <th>#</th>
                            <th>Intern Name</th>
                            <th>Intern ID</th>
                            <th>Batch</th>
                            <th>Office Branch</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $pIdx = 1; foreach ($pendingInternsList as $pInt): ?>
                        <tr>
                            <td><?= $pIdx++ ?></td>
                            <td><strong><?= htmlspecialchars($pInt['fullName']) ?></strong></td>
                            <td><code><?= htmlspecialchars($pInt['userId']) ?></code></td>
                            <td><?= htmlspecialchars($pInt['batch'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($pInt['office'] ?? '—') ?></td>
                            <td class="text-center">
                                <a href="intern_kra_form.php?internId=<?= urlencode($pInt['userId']) ?>" class="btn btn-sm btn-primary" style="border-radius:6px; font-weight:600; font-size:0.75rem;">
                                    <i class="fas fa-edit mr-1"></i> Fill KRA Now
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="text-center py-4 text-success" style="font-weight:600;">
                    <i class="fas fa-check-circle fa-2x mb-2 d-block"></i>
                    All eligible interns have completed KRA evaluations for Week <?= $targetWeek ?>! 🎉
                </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer" style="background:#f8fafc; border-top:1px solid #e2e8f0; padding:12px 24px;">
                <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal" style="border-radius:6px;">Close</button>
            </div>
        </div>
    </div>
</div>

</body>
</html>
