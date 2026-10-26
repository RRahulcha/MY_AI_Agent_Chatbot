<?php
session_start();
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}

include('../include/db_config.php');
date_default_timezone_set('Asia/Kolkata');

// ── 1. Pipeline High-Level Metrics ──────────────────────────────────────────
$totalCandidates = (int)mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as c FROM candidates"))['c'];
$totalEvaluated  = (int)mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as c FROM technical_evaluations"))['c'];
$totalSubmittedHr= (int)mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as c FROM technical_evaluations WHERE submittedToHr = 1"))['c'];
$pendingHr       = (int)mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as c FROM technical_evaluations WHERE submittedToHr = 1 AND (hrStatus IS NULL OR hrStatus = '' OR hrStatus = 'pending')"))['c'];
$approvedHr      = (int)mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as c FROM technical_evaluations WHERE submittedToHr = 1 AND hrStatus = 'approved'"))['c'];
$rejectedHr      = (int)mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as c FROM technical_evaluations WHERE submittedToHr = 1 AND hrStatus = 'rejected'"))['c'];

$activeInternsCount = (int)mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as c FROM signup WHERE status = 'Active' AND regstatus = 'Approved'"))['c'];

$conversionRate = ($totalCandidates > 0) ? round(($approvedHr / $totalCandidates) * 100, 1) : 0;

// ── 2. Online vs Offline Mode Breakdown ──────────────────────────────────────
$onlineCount  = 0;
$offlineCount = 0;

$modeBreakdownQ = mysqli_query($con, "SELECT interviewMode, COUNT(*) as cnt FROM candidates GROUP BY interviewMode");
if ($modeBreakdownQ) {
    while ($mb = mysqli_fetch_assoc($modeBreakdownQ)) {
        $mVal = strtolower(trim($mb['interviewMode'] ?? ''));
        if ($mVal === 'online') {
            $onlineCount += (int)$mb['cnt'];
        } elseif ($mVal === 'offline') {
            $offlineCount += (int)$mb['cnt'];
        } else {
            $onlineCount += (int)$mb['cnt']; // default fallback
        }
    }
}

// ── 3. Technical Interaction Conducting Persons (Evaluator / Interviewer Stats) ──
$interviewerCounts = [];
$intQ = mysqli_query($con, "
    SELECT c.assignedInterviewer, s.fullName
    FROM candidates c
    LEFT JOIN signup s ON (c.assignedInterviewer = s.userId OR c.assignedInterviewer = s.emp_id)
");
if ($intQ) {
    while ($r = mysqli_fetch_assoc($intQ)) {
        $name = !empty($r['fullName']) ? $r['fullName'] : (!empty($r['assignedInterviewer']) ? $r['assignedInterviewer'] : 'Unassigned');
        $interviewerCounts[$name] = ($interviewerCounts[$name] ?? 0) + 1;
    }
}
arsort($interviewerCounts);

// ── 4. Internship Recommendation Mode Distribution ──────────────────────────
$modeCounts = [
    'Mentor-Led' => 0,
    'Free of Cost' => 0,
    'Stipend-based Internship' => 0,
    'Not Selected' => 0,
    'Other' => 0
];
$modeQ = mysqli_query($con, "SELECT internshipMode, COUNT(*) as cnt FROM technical_evaluations GROUP BY internshipMode");
if ($modeQ) {
    while ($m = mysqli_fetch_assoc($modeQ)) {
        $key = trim($m['internshipMode'] ?? '');
        if (isset($modeCounts[$key])) {
            $modeCounts[$key] += (int)$m['cnt'];
        } elseif (!empty($key)) {
            $modeCounts['Other'] += (int)$m['cnt'];
        }
    }
}

// ── 5. Candidate Pipeline Full Data List ─────────────────────────────────────
$pipelineRows = [];
$candQ = mysqli_query($con, "
    SELECT c.*, te.id as evalId, te.overallScore, te.internshipMode, te.submittedToHr, te.hrStatus, te.submittedAt, te.hrDecidedAt
    FROM candidates c
    LEFT JOIN technical_evaluations te ON c.id = te.candidateId
    ORDER BY c.id DESC
");

if ($candQ) {
    while ($r = mysqli_fetch_assoc($candQ)) {
        $emailEsc = mysqli_real_escape_string($con, strtolower(trim($r['email'] ?? '')));
        $isOnboarded = false;
        if ($emailEsc !== '') {
            $checkOnb = mysqli_query($con, "SELECT userId FROM signup WHERE LOWER(email) = '$emailEsc' AND regstatus = 'Approved' LIMIT 1");
            if ($checkOnb && mysqli_num_rows($checkOnb) > 0) {
                $isOnboarded = true;
            }
        }

        // Determine Current Pipeline Stage
        if ($isOnboarded) {
            $stage = 'Onboarded Active Intern';
            $stageBadge = 'badge-stage-onboarded';
            $stageStep = 5;
            $actionUrl = 'active-interns.php';
            $actionText = 'View Active Intern';
        } elseif ($r['hrStatus'] === 'approved') {
            $stage = 'Approved (Pending Onboarding)';
            $stageBadge = 'badge-stage-approved';
            $stageStep = 4;
            $actionUrl = 'onBoarding.php';
            $actionText = 'Complete Onboarding';
        } elseif ($r['hrStatus'] === 'rejected') {
            $stage = 'Rejected by HR';
            $stageBadge = 'badge-stage-rejected';
            $stageStep = 3;
            $actionUrl = 'technicalEvaluationHr.php';
            $actionText = 'View Decision';
        } elseif ($r['submittedToHr'] == 1) {
            $stage = 'Pending HR Decision';
            $stageBadge = 'badge-stage-hr-pending';
            $stageStep = 3;
            $actionUrl = 'technicalEvaluationHr.php';
            $actionText = 'HR Review';
        } elseif (!empty($r['evalId'])) {
            $stage = 'Evaluated (Draft)';
            $stageBadge = 'badge-stage-evaluated';
            $stageStep = 2;
            $actionUrl = 'technical_evaluation.php';
            $actionText = 'Submit to HR';
        } else {
            $stage = 'Interaction Registered';
            $stageBadge = 'badge-stage-registered';
            $stageStep = 1;
            $actionUrl = 'evaluate_candidate.php?candidate_id=' . $r['id'];
            $actionText = 'Evaluate Candidate';
        }

        $r['stage']       = $stage;
        $r['stageBadge']  = $stageBadge;
        $r['stageStep']   = $stageStep;
        $r['actionUrl']   = $actionUrl;
        $r['actionText']  = $actionText;
        $r['isOnboarded'] = $isOnboarded;

        $pipelineRows[] = $r;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <title>SETTribe · Onboarding Process Dashboard</title>
    <?php include('../partials/_links.php'); ?>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap4.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary: #4f46e5;
            --primary-dark: #3730a3;
            --primary-light: #eef2ff;
            --success: #10b981;
            --success-light: #ecfdf5;
            --warning: #f59e0b;
            --warning-light: #fffbeb;
            --danger: #ef4444;
            --danger-light: #fef2f2;
            --info: #06b6d4;
            --info-light: #ecfeff;
            --purple: #8b5cf6;
            --border: #e2e8f0;
            --radius-lg: 14px;
            --radius-md: 10px;
        }

        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: #f1f5f9;
            color: #1e293b;
        }

        /* ── Header Banner ── */
        .analytics-header-card {
            background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 50%, #312e81 100%);
            border-radius: var(--radius-lg);
            padding: 26px 30px;
            color: #ffffff;
            margin-bottom: 24px;
            box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.3);
            position: relative;
            overflow: hidden;
        }
        .analytics-header-card::after {
            content: '';
            position: absolute;
            right: -50px;
            top: -50px;
            width: 240px;
            height: 240px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 50%;
            pointer-events: none;
        }
        .header-title-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 0.76rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 10px;
        }

        /* ── Quick Pipeline Stage Stepper ── */
        .stepper-wrap {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #ffffff;
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            padding: 16px 24px;
            margin-bottom: 24px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.03);
            overflow-x: auto;
        }
        .step-item {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: #475569;
            transition: all 0.2s;
            padding: 6px 12px;
            border-radius: var(--radius-md);
        }
        .step-item:hover {
            background: var(--primary-light);
            color: var(--primary);
            text-decoration: none;
        }
        .step-num {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #f1f5f9;
            color: #475569;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 0.85rem;
        }
        .step-item:hover .step-num {
            background: var(--primary);
            color: #ffffff;
        }
        .step-text { display: flex; flex-direction: column; }
        .step-title { font-size: 0.82rem; font-weight: 700; line-height: 1.2; }
        .step-desc { font-size: 0.72rem; opacity: 0.75; }
        .step-arrow { color: #cbd5e1; font-size: 1rem; }

        /* ── KPI Cards ── */
        .kpi-card {
            background: #ffffff;
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            padding: 18px 20px;
            height: 100%;
            box-shadow: 0 4px 14px rgba(0,0,0,0.03);
            transition: all 0.25s ease;
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .kpi-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 24px rgba(0,0,0,0.06);
            border-color: #cbd5e1;
        }
        .kpi-card::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; }
        .kpi-blue::before    { background: var(--primary); }
        .kpi-cyan::before    { background: var(--info); }
        .kpi-amber::before   { background: var(--warning); }
        .kpi-green::before   { background: var(--success); }
        .kpi-purple::before  { background: var(--purple); }

        .kpi-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
        .kpi-title { font-size: 0.78rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.4px; }
        .kpi-icon-wrap { width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1rem; }
        .icon-blue   { background: var(--primary-light); color: var(--primary); }
        .icon-cyan   { background: var(--info-light); color: var(--info); }
        .icon-amber  { background: var(--warning-light); color: var(--warning); }
        .icon-green  { background: var(--success-light); color: var(--success); }
        .icon-purple { background: #f3e8ff; color: var(--purple); }

        .kpi-value { font-size: 1.7rem; font-weight: 800; color: #0f172a; line-height: 1.1; margin-bottom: 6px; }
        .kpi-sub { font-size: 0.75rem; color: #64748b; font-weight: 600; }

        /* ── Report Card ── */
        .analytics-table-card {
            background: #ffffff;
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            padding: 24px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.03);
            margin-bottom: 30px;
        }

        #pipelineTable { width: 100% !important; border-collapse: separate; border-spacing: 0; }
        #pipelineTable thead th {
            background: linear-gradient(135deg, #1e293b, #334155);
            color: #ffffff;
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            padding: 12px 14px;
            border: none;
            text-align: center;
        }
        #pipelineTable thead th:first-child { border-top-left-radius: 8px; }
        #pipelineTable thead th:last-child { border-top-right-radius: 8px; }

        #pipelineTable tbody td {
            font-size: 0.84rem;
            padding: 12px 14px;
            vertical-align: middle;
            border-bottom: 1px solid #f1f5f9;
            text-align: center;
            color: #334155;
        }
        #pipelineTable tbody tr:hover { background-color: #f8fafc; }

        /* Stage Badges */
        .badge-stage { padding: 4px 10px; border-radius: 12px; font-size: 0.73rem; font-weight: 700; display: inline-flex; align-items: center; gap: 4px; }
        .badge-stage-registered { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }
        .badge-stage-evaluated  { background: #ede9ff; color: #6d28d9; border: 1px solid #ddd6fe; }
        .badge-stage-hr-pending { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
        .badge-stage-approved   { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .badge-stage-onboarded  { background: #ccfbf1; color: #0f766e; border: 1px solid #99f6e4; font-weight:800; }
        .badge-stage-rejected   { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }

        .btn-stage-action {
            padding: 4px 12px;
            border-radius: 6px;
            font-size: 0.76rem;
            font-weight: 700;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.2s;
        }
        .btn-stage-action:hover { text-decoration: none; transform: translateY(-1px); }

        .btn-gradient-primary {
            background: linear-gradient(135deg, #4f46e5 0%, #3730a3 100%);
            color: #ffffff; border: none; font-weight: 700; padding: 8px 18px; border-radius: var(--radius-md); box-shadow: 0 4px 12px rgba(79, 70, 229, 0.25); transition: all 0.2s;
        }
        .btn-gradient-primary:hover { color: #fff; transform: translateY(-1px); }
    </style>
</head>

<body>
    <div class="container-scroller">
        <?php include('../partials/_navbar.php'); ?>
        <div class="container-fluid page-body-wrapper">
            <?php include('../partials/_sidebar.php'); include('../partials/_settings-panel.php'); ?>

            <div class="main-panel">
                <div class="content-wrapper">

                    <!-- ── HEADER BANNER ── -->
                    <div class="analytics-header-card">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                            <div>
                                <div class="header-title-badge">
                                    <i class="fas fa-sitemap"></i> Candidate Journey &amp; Onboarding Pipeline
                                </div>
                                <h2 style="font-size: 1.65rem; font-weight: 800; margin-bottom: 4px;">
                                    Interaction &amp; Onboarding Process Dashboard
                                </h2>
                                <p style="font-size: 0.85rem; opacity: 0.88; margin: 0;">
                                    End-to-end tracking from candidate interaction registration -> technical evaluation -> HR decision -> onboarding completion.
                                </p>
                            </div>
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <a href="interaction_entry.php" class="btn btn-light text-primary font-weight-bold" style="border-radius:10px; font-size:0.82rem;">
                                    <i class="fas fa-plus-circle mr-1"></i> New Interaction
                                </a>
                                <a href="onBoarding.php" class="btn btn-gradient-primary" style="font-size:0.82rem;">
                                    <i class="fas fa-user-plus mr-1"></i> Complete Onboarding
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- ── PIPELINE STEPPER NAVIGATION ── -->
                    <div class="stepper-wrap">
                        <a href="interaction_entry.php" class="step-item">
                            <div class="step-num">1</div>
                            <div class="step-text">
                                <span class="step-title">Interaction Entry</span>
                                <span class="step-desc">Register Inquiry</span>
                            </div>
                        </a>
                        <i class="fas fa-chevron-right step-arrow"></i>
                        <a href="technical_evaluation.php" class="step-item">
                            <div class="step-num">2</div>
                            <div class="step-text">
                                <span class="step-title">Technical Evaluation</span>
                                <span class="step-desc">Rate Skills &amp; Scores</span>
                            </div>
                        </a>
                        <i class="fas fa-chevron-right step-arrow"></i>
                        <a href="technicalEvaluationHr.php" class="step-item">
                            <div class="step-num">3</div>
                            <div class="step-text">
                                <span class="step-title">HR Review &amp; Decision</span>
                                <span class="step-desc">Approve or Reject</span>
                            </div>
                        </a>
                        <i class="fas fa-chevron-right step-arrow"></i>
                        <a href="onBoarding.php" class="step-item">
                            <div class="step-num">4</div>
                            <div class="step-text">
                                <span class="step-title">Candidate Onboarding</span>
                                <span class="step-desc">Offer &amp; Documents</span>
                            </div>
                        </a>
                        <i class="fas fa-chevron-right step-arrow"></i>
                        <a href="active-interns.php" class="step-item">
                            <div class="step-num">5</div>
                            <div class="step-text">
                                <span class="step-title">Active Roster</span>
                                <span class="step-desc">Active Team Member</span>
                            </div>
                        </a>
                    </div>

                    <!-- ── KPI METRICS ROW ── -->
                    <div class="row mb-4">
                        <div class="col-xl-2 col-md-4 col-sm-6 mb-3 mb-xl-0">
                            <div class="kpi-card kpi-blue">
                                <div class="kpi-header">
                                    <span class="kpi-title">Registered</span>
                                    <div class="kpi-icon-wrap icon-blue"><i class="fas fa-user-edit"></i></div>
                                </div>
                                <div>
                                    <div class="kpi-value"><?= $totalCandidates ?></div>
                                    <div class="kpi-sub">Total Interactions</div>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-2 col-md-4 col-sm-6 mb-3 mb-xl-0">
                            <div class="kpi-card kpi-cyan">
                                <div class="kpi-header">
                                    <span class="kpi-title">Evaluated</span>
                                    <div class="kpi-icon-wrap icon-cyan"><i class="fas fa-laptop-code"></i></div>
                                </div>
                                <div>
                                    <div class="kpi-value"><?= $totalEvaluated ?></div>
                                    <div class="kpi-sub">Technical Tested</div>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-2 col-md-4 col-sm-6 mb-3 mb-xl-0">
                            <div class="kpi-card kpi-amber">
                                <div class="kpi-header">
                                    <span class="kpi-title">HR Pending</span>
                                    <div class="kpi-icon-wrap icon-amber"><i class="fas fa-hourglass-half"></i></div>
                                </div>
                                <div>
                                    <div class="kpi-value"><?= $pendingHr ?></div>
                                    <div class="kpi-sub">Awaiting Decision</div>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-3 col-md-6 col-sm-6 mb-3 mb-xl-0">
                            <div class="kpi-card kpi-green">
                                <div class="kpi-header">
                                    <span class="kpi-title">Approved Candidates</span>
                                    <div class="kpi-icon-wrap icon-green"><i class="fas fa-check-circle"></i></div>
                                </div>
                                <div>
                                    <div class="kpi-value"><?= $approvedHr ?></div>
                                    <div class="kpi-sub">Ready for Onboarding | Rejected: <strong><?= $rejectedHr ?></strong></div>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-3 col-md-6 col-sm-6 mb-3 mb-xl-0">
                            <div class="kpi-card kpi-purple">
                                <div class="kpi-header">
                                    <span class="kpi-title">Active Intern Roster</span>
                                    <div class="kpi-icon-wrap icon-purple"><i class="fas fa-user-check"></i></div>
                                </div>
                                <div>
                                    <div class="kpi-value"><?= $activeInternsCount ?></div>
                                    <div class="kpi-sub">Approval Success Rate: <strong><?= $conversionRate ?>%</strong></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── CHARTS ROW 1: Funnel & Online/Offline Mode ── -->
                    <div class="row mb-4">
                        <!-- Funnel Bar Chart -->
                        <div class="col-lg-7 mb-3 mb-lg-0">
                            <div class="analytics-table-card h-100" style="padding:20px;">
                                <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-2">
                                    <h5 style="font-size:.95rem; font-weight:800; color:#0f172a; margin:0;">
                                        <i class="fas fa-chart-bar text-primary mr-1"></i> Onboarding Funnel Progression
                                    </h5>
                                    <span class="badge badge-light border" style="font-size:.72rem;">Stage Drop-off Metrics</span>
                                </div>
                                <div style="position: relative; height: 230px;">
                                    <canvas id="funnelChart"></canvas>
                                </div>
                            </div>
                        </div>

                        <!-- Online vs Offline Mode Doughnut Chart -->
                        <div class="col-lg-5">
                            <div class="analytics-table-card h-100" style="padding:20px;">
                                <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-2">
                                    <h5 style="font-size:.95rem; font-weight:800; color:#0f172a; margin:0;">
                                        <i class="fas fa-wifi text-info mr-1"></i> Online vs Offline Mode Breakdown
                                    </h5>
                                    <span class="badge badge-light border" style="font-size:.72rem;">Interview Mode Proportions</span>
                                </div>
                                <div style="position: relative; height: 230px;">
                                    <canvas id="onlineOfflineChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── CHARTS ROW 2: Conducting Persons & Recommendation Modes ── -->
                    <div class="row mb-4">
                        <!-- Technical Conducting Persons Bar Chart -->
                        <div class="col-lg-7 mb-3 mb-lg-0">
                            <div class="analytics-table-card h-100" style="padding:20px;">
                                <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-2">
                                    <h5 style="font-size:.95rem; font-weight:800; color:#0f172a; margin:0;">
                                        <i class="fas fa-user-tie text-purple mr-1"></i> Technical Interaction Conducting Persons
                                    </h5>
                                    <span class="badge badge-light border" style="font-size:.72rem;">Interviewer Candidate Count</span>
                                </div>
                                <div style="position: relative; height: 230px;">
                                    <canvas id="conductingPersonsChart"></canvas>
                                </div>
                            </div>
                        </div>

                        <!-- Mode Recommendation Doughnut Chart -->
                        <div class="col-lg-5">
                            <div class="analytics-table-card h-100" style="padding:20px;">
                                <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-2">
                                    <h5 style="font-size:.95rem; font-weight:800; color:#0f172a; margin:0;">
                                        <i class="fas fa-chart-pie text-success mr-1"></i> Internship Mode Recommendation
                                    </h5>
                                    <span class="badge badge-light border" style="font-size:.72rem;">Evaluator Recommendations</span>
                                </div>
                                <div style="position: relative; height: 230px;">
                                    <canvas id="modeDoughnutChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── CANDIDATE PIPELINE MASTER TRACKER TABLE ── -->
                    <div class="analytics-table-card">
                        <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                            <div>
                                <h5 class="mb-0 font-weight-bold" style="color:#0f172a; font-size:1.05rem;">
                                    <i class="fas fa-list-check text-primary mr-1"></i> Live Candidate Pipeline Tracker
                                </h5>
                                <p style="font-size:0.8rem; color:#64748b; margin:2px 0 0 0;">
                                    Real-time status tracking for every candidate from interaction entry to active intern status.
                                </p>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table" id="pipelineTable">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th style="text-align:left;">Candidate Name</th>
                                        <th>Contact Details</th>
                                        <th>Mode</th>
                                        <th>Score</th>
                                        <th>Recommendation</th>
                                        <th>Pipeline Stage</th>
                                        <th>Action Link</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $sr = 1;
                                    foreach ($pipelineRows as $r):
                                        $mode = htmlspecialchars((string)($r['internshipMode'] ?? $r['interviewMode'] ?? '—'));
                                        $score = !empty($r['overallScore']) ? $r['overallScore'] . ' / 5' : '—';
                                    ?>
                                        <tr>
                                            <td class="font-weight-bold text-muted"><?= $sr++; ?></td>
                                            <td style="text-align:left;">
                                                <strong style="color:#0f172a; font-size:.88rem;"><?= htmlspecialchars((string)$r['applicantName']) ?></strong>
                                                <div style="font-size:.72rem; color:#64748b;">Applied: <?= !empty($r['applicationDate']) ? date('d M Y', strtotime($r['applicationDate'])) : '—' ?></div>
                                            </td>
                                            <td>
                                                <div style="font-size:.8rem; font-weight:600; color:#334155;"><?= htmlspecialchars((string)$r['email']) ?></div>
                                                <small class="text-muted"><?= htmlspecialchars((string)$r['mobile']) ?></small>
                                            </td>
                                            <td><span class="badge badge-light border"><?= $mode ?></span></td>
                                            <td><span class="badge badge-info" style="font-size:.74rem;"><?= $score ?></span></td>
                                            <td><strong style="font-size:.78rem; color:#475569;"><?= htmlspecialchars((string)($r['internshipMode'] ?? '—')) ?></strong></td>
                                            <td>
                                                <span class="badge-stage <?= $r['stageBadge'] ?>">
                                                    Step <?= $r['stageStep'] ?>: <?= $r['stage'] ?>
                                                </span>
                                            </td>
                                            <td>
                                                <a href="<?= $r['actionUrl'] ?>" class="btn-stage-action btn-outline-primary">
                                                    <?= $r['actionText'] ?> <i class="fas fa-arrow-right ml-1"></i>
                                                </a>
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

    <!-- JS -->
    <script src="../vendors/js/vendor.bundle.base.js"></script>
    <script src="../vendors/chart.js/Chart.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap4.min.js"></script>
    <script src="../js/off-canvas.js"></script>
    <script src="../js/hoverable-collapse.js"></script>
    <script src="../js/template.js"></script>
    <script src="../js/settings.js"></script>

    <script>
        $(document).ready(function () {
            $('#pipelineTable').DataTable({
                pageLength: 15,
                order: [[ 0, "asc" ]],
                language: { search: "<i class='fas fa-search text-muted mr-1'></i> Search Candidate Pipeline:" }
            });

            // 1. Funnel Bar Chart
            var ctxFunnel = document.getElementById('funnelChart').getContext('2d');
            new Chart(ctxFunnel, {
                type: 'bar',
                data: {
                    labels: ['1. Interactions', '2. Evaluated', '3. HR Submitted', '4. HR Approved', '5. Active Interns'],
                    datasets: [{
                        label: 'Candidate Count',
                        data: [<?= $totalCandidates ?>, <?= $totalEvaluated ?>, <?= $totalSubmittedHr ?>, <?= $approvedHr ?>, <?= $activeInternsCount ?>],
                        backgroundColor: ['#3b82f6', '#8b5cf6', '#f59e0b', '#10b981', '#06b6d4'],
                        borderRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    legend: { display: false },
                    scales: {
                        xAxes: [{ gridLines: { display: false } }],
                        yAxes: [{ ticks: { beginAtZero: true, stepSize: 1 } }]
                    }
                }
            });

            // 2. Online vs Offline Mode Doughnut Chart
            var ctxOnlineOffline = document.getElementById('onlineOfflineChart').getContext('2d');
            new Chart(ctxOnlineOffline, {
                type: 'doughnut',
                data: {
                    labels: ['Online Mode', 'Offline Mode'],
                    datasets: [{
                        data: [<?= $onlineCount ?>, <?= $offlineCount ?>],
                        backgroundColor: ['#4f46e5', '#f59e0b'],
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    legend: { position: 'right', labels: { boxWidth: 12, fontSize: 11 } }
                }
            });

            // 3. Technical Conducting Persons Bar Chart
            var ctxConducting = document.getElementById('conductingPersonsChart').getContext('2d');
            new Chart(ctxConducting, {
                type: 'bar',
                data: {
                    labels: <?= json_encode(array_keys($interviewerCounts)) ?>,
                    datasets: [{
                        label: 'Candidates Assigned / Evaluated',
                        data: <?= json_encode(array_values($interviewerCounts)) ?>,
                        backgroundColor: '#8b5cf6',
                        borderRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    legend: { display: false },
                    scales: {
                        xAxes: [{ gridLines: { display: false } }],
                        yAxes: [{ ticks: { beginAtZero: true, stepSize: 1 } }]
                    }
                }
            });

            // 4. Recommendation Mode Doughnut Chart
            var ctxMode = document.getElementById('modeDoughnutChart').getContext('2d');
            new Chart(ctxMode, {
                type: 'doughnut',
                data: {
                    labels: ['Mentor-Led', 'Free of Cost', 'Stipend-based', 'Not Selected'],
                    datasets: [{
                        data: [
                            <?= $modeCounts['Mentor-Led'] ?>,
                            <?= $modeCounts['Free of Cost'] ?>,
                            <?= $modeCounts['Stipend-based Internship'] ?>,
                            <?= $modeCounts['Not Selected'] ?>
                        ],
                        backgroundColor: ['#4f46e5', '#10b981', '#f59e0b', '#ef4444'],
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    legend: { position: 'right', labels: { boxWidth: 12, fontSize: 11 } }
                }
            });
        });
    </script>
</body>
</html>
