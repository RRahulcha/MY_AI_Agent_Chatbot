<?php
session_start();

if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href='login.php';</script>";
    exit;
}

include('../include/db_config.php');

$query = mysqli_query($con, "SELECT designation, userRole, fullName FROM signup WHERE userId = '" . mysqli_real_escape_string($con, $_SESSION['userId']) . "'");
$user  = mysqli_fetch_assoc($query);

$allowedRolesClean = ['resourceallocator', 'resourcehead', 'resourcemanager', 'admin', 'superadmin', 'hr'];
$desigClean = strtolower(str_replace([' ', '_'], '', $user['designation'] ?? ''));
$roleClean  = strtolower(str_replace([' ', '_'], '', $user['userRole'] ?? ''));

if (!$user || (!in_array($desigClean, $allowedRolesClean) && !in_array($roleClean, $allowedRolesClean))) {
    echo "<script>alert('Unauthorized Access'); window.location.href='logout.php';</script>";
    exit;
}

$kraId = (int)($_GET['kra_id'] ?? 0);

if ($kraId <= 0) {
    $_SESSION['msg']    = 'Invalid KRA record.';
    $_SESSION['status'] = 0;
    header('Location: intern_kra_list.php');
    exit;
}

// ── Handle POST update ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $communication            = (int)($_POST['communication']              ?? 0);
    $communicationRemark      = mysqli_real_escape_string($con, trim($_POST['communication_remark']      ?? ''));
    $confidence               = (int)($_POST['confidence']                 ?? 0);
    $confidenceRemark         = mysqli_real_escape_string($con, trim($_POST['confidence_remark']         ?? ''));
    $clarity                  = (int)($_POST['clarity']                    ?? 0);
    $clarityRemark            = mysqli_real_escape_string($con, trim($_POST['clarity_remark']            ?? ''));
    $teamwork                 = (int)($_POST['teamwork']                   ?? 0);
    $teamworkRemark           = mysqli_real_escape_string($con, trim($_POST['teamwork_remark']           ?? ''));
    $problemSolving           = (int)($_POST['problem_solving']            ?? 0);
    $problemSolvingRemark     = mysqli_real_escape_string($con, trim($_POST['problem_solving_remark']    ?? ''));
    $learningAttitude         = (int)($_POST['learning_attitude']          ?? 0);
    $learningAttitudeRemark   = mysqli_real_escape_string($con, trim($_POST['learning_attitude_remark']  ?? ''));
    $technicalKnowledge       = (int)($_POST['technical_knowledge']        ?? 0);
    $technicalKnowledgeRemark = mysqli_real_escape_string($con, trim($_POST['technical_knowledge_remark'] ?? ''));
    $githubActivity           = (int)($_POST['github_activity']            ?? 0);
    $githubActivityRemark     = mysqli_real_escape_string($con, trim($_POST['github_activity_remark']     ?? ''));
    $amsProcessAdherence      = (int)($_POST['ams_process_adherence']      ?? 0);
    $amsProcessAdherenceRemark= mysqli_real_escape_string($con, trim($_POST['ams_process_adherence_remark']  ?? ''));
    $averagePercentage        = (float)($_POST['average_percentage']       ?? 0);
    $batch                    = mysqli_real_escape_string($con, trim($_POST['batch']  ?? ''));
    $mode                     = mysqli_real_escape_string($con, trim($_POST['mode']   ?? ''));
    $office                   = mysqli_real_escape_string($con, trim($_POST['office'] ?? ''));

    $ratings = [$communication, $confidence, $clarity, $teamwork, $problemSolving, $learningAttitude, $technicalKnowledge, $githubActivity, $amsProcessAdherence];
    $valid   = true;
    foreach ($ratings as $r) {
        if ($r < 1 || $r > 10) { $valid = false; break; }
    }

    if (!$valid) {
        $_SESSION['msg']    = 'All ratings must be between 1 and 10.';
        $_SESSION['status'] = 0;
        header('Location: intern_kra_edit.php?kra_id=' . $kraId);
        exit;
    }

    $update = mysqli_query($con,
        "UPDATE internKraAssignments SET
            batch                    = '$batch',
            mode                     = '$mode',
            office                   = '$office',
            communication            = '$communication',
            communicationRemark      = '$communicationRemark',
            confidence               = '$confidence',
            confidenceRemark         = '$confidenceRemark',
            clarity                  = '$clarity',
            clarityRemark            = '$clarityRemark',
            teamwork                 = '$teamwork',
            teamworkRemark           = '$teamworkRemark',
            problemSolving           = '$problemSolving',
            problemSolvingRemark     = '$problemSolvingRemark',
            learningAttitude         = '$learningAttitude',
            learningAttitudeRemark   = '$learningAttitudeRemark',
            technicalKnowledge       = '$technicalKnowledge',
            technicalKnowledgeRemark = '$technicalKnowledgeRemark',
            githubActivity           = '$githubActivity',
            githubActivityRemark     = '$githubActivityRemark',
            amsProcessAdherence      = '$amsProcessAdherence',
            amsProcessAdherenceRemark= '$amsProcessAdherenceRemark',
            averagePercentage        = '$averagePercentage'
         WHERE kraId = '$kraId' AND status = 'active'"
    );

    if ($update) {
        $_SESSION['msg']    = 'KRA record updated successfully.';
        $_SESSION['status'] = 1;
    } else {
        $_SESSION['msg']    = 'Failed to update: ' . mysqli_error($con);
        $_SESSION['status'] = 0;
    }

    header('Location: intern_kra_list.php');
    exit;
}

// ── Fetch record ──────────────────────────────────────────────────
$kraQ = mysqli_query($con, "SELECT * FROM internKraAssignments WHERE kraId = '$kraId' AND status = 'active' LIMIT 1");

if (!$kraQ || mysqli_num_rows($kraQ) === 0) {
    $_SESSION['msg']    = 'KRA record not found.';
    $_SESSION['status'] = 0;
    header('Location: intern_kra_list.php');
    exit;
}

$kra = mysqli_fetch_assoc($kraQ);

$batchOptions  = mysqli_query($con, "SELECT DISTINCT batch_number FROM signup WHERE batch_number IS NOT NULL AND batch_number != '' ORDER BY batch_number ASC");
$officeOptions = mysqli_query($con, "SELECT DISTINCT office FROM signup WHERE office IS NOT NULL AND office != '' ORDER BY office ASC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Edit Intern KRA</title>
    <?php include('../partials/_links.php'); ?>
    <link href="../vendors/select2/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .card {
            border: none;
            border-radius: 12px;
            background: #fff;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }

        .section-divider {
            border: none;
            border-top: 1px solid #f0f0f0;
            margin: 20px 0;
        }

        .section-title {
            font-size: 0.78rem;
            font-weight: 700;
            color: #667085;
            letter-spacing: 0.6px;
            text-transform: uppercase;
            margin-bottom: 14px;
        }

        .intern-info-box {
            background: #f0f4ff;
            border: 1px solid #d0d9f5;
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 20px;
            display: flex;
            gap: 24px;
            flex-wrap: wrap;
            align-items: center;
        }

        .intern-info-box .ib-item {
            font-size: 0.8rem;
            color: #475467;
        }

        .intern-info-box .ib-item strong {
            font-weight: 700;
            color: #2d3a4a;
            display: block;
            font-size: 0.875rem;
        }

        /* Select2 */
        .select2-container--default .select2-selection--single {
            height: 38px;
            display: flex;
            align-items: center;
            border: 1px solid #ced4da;
            border-radius: 4px;
        }

        .select2-container--default .select2-selection--single .select2-selection__rendered {
            padding-left: 10px;
            font-size: 0.875rem;
            color: #495057;
            line-height: 36px;
        }

        .select2-container--default .select2-selection--single .select2-selection__arrow { height: 36px; }

        .select2-container--default.select2-container--focus .select2-selection--single,
        .select2-container--default.select2-container--open  .select2-selection--single {
            border-color: #4B49AC;
            box-shadow: 0 0 0 0.2rem rgba(75,73,172,0.15);
        }

        .select2-dropdown  { font-size: 0.875rem; }
        .select2-results__option--highlighted { background-color: #4B49AC !important; }

        /* KRA rows */
        .kra-row {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 14px 16px;
            margin-bottom: 12px;
            border-left: 3px solid #e67e22;
        }

        .kra-row .kra-row-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 10px;
        }

        .kra-row .kra-label {
            font-size: 0.875rem;
            font-weight: 600;
            color: #2d3a4a;
        }

        .kra-row .kra-label .num {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 20px;
            height: 20px;
            background: #e67e22;
            color: #fff;
            border-radius: 50%;
            font-size: 0.68rem;
            font-weight: 700;
            margin-right: 8px;
        }

        .score-badge {
            font-size: 0.72rem;
            font-weight: 600;
            color: #6c757d;
            background: #e9ecef;
            padding: 2px 9px;
            border-radius: 12px;
            min-width: 48px;
            text-align: center;
            transition: background 0.2s, color 0.2s;
        }

        .score-badge.high { background: #d1fae5; color: #065f46; }
        .score-badge.mid  { background: #fef3c7; color: #92400e; }
        .score-badge.low  { background: #fee2e2; color: #991b1b; }

        .rating-stepper {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 10px;
        }

        .stepper-btn {
            width: 30px;
            height: 30px;
            border-radius: 4px;
            border: 1px solid #ced4da;
            background: #fff;
            color: #495057;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: border-color 0.15s, background 0.15s, color 0.15s;
            line-height: 1;
        }

        .stepper-btn:hover {
            border-color: #e67e22;
            color: #e67e22;
            background: #fff8f0;
        }

        .stepper-btn:active { transform: scale(0.94); }

        .rating-num-input {
            width: 64px !important;
            text-align: center !important;
            font-weight: 700 !important;
            font-size: 0.95rem !important;
            color: #e67e22 !important;
            border: 1px solid #ced4da !important;
            border-radius: 4px !important;
            padding: 5px 8px !important;
            flex-shrink: 0;
            -moz-appearance: textfield;
        }

        .rating-num-input::-webkit-outer-spin-button,
        .rating-num-input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }

        .rating-num-input:focus {
            border-color: #e67e22 !important;
            box-shadow: 0 0 0 0.2rem rgba(230,126,34,0.15) !important;
            outline: none !important;
        }

        .pip-track {
            display: flex;
            gap: 3px;
            flex: 1;
            align-items: center;
        }

        .pip {
            flex: 1;
            height: 4px;
            border-radius: 2px;
            background: #dee2e6;
            transition: background 0.18s;
        }

        .pip.on-high { background: #00b37e; }
        .pip.on-mid  { background: #f59e0b; }
        .pip.on-low  { background: #ef4444; }

        .remark-field {
            font-size: 0.8rem !important;
            color: #495057 !important;
            border: 1px solid #ced4da !important;
            border-radius: 4px !important;
            resize: none !important;
            padding: 7px 10px !important;
            width: 100%;
            font-family: inherit !important;
            transition: border-color 0.15s, box-shadow 0.15s;
        }

        .remark-field:focus {
            outline: none;
            border-color: #e67e22 !important;
            box-shadow: 0 0 0 0.2rem rgba(230,126,34,0.15) !important;
        }

        .avg-summary {
            border-radius: 10px;
            padding: 18px 22px;
            background: linear-gradient(135deg, #4B49AC 0%, #7978e9 100%);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            transition: background 0.4s;
            margin-bottom: 20px;
        }

        .avg-summary.green  { background: linear-gradient(135deg, #059669 0%, #34d399 100%); }
        .avg-summary.yellow { background: linear-gradient(135deg, #d97706 0%, #fbbf24 100%); }
        .avg-summary.red    { background: linear-gradient(135deg, #dc2626 0%, #f87171 100%); }

        .avg-left .avg-label { font-size:0.72rem; font-weight:600; letter-spacing:0.08em; text-transform:uppercase; opacity:0.8; margin-bottom:3px; }
        .avg-left .avg-score { font-size:2rem; font-weight:700; line-height:1; }
        .avg-left .avg-sub   { font-size:0.78rem; opacity:0.75; margin-top:4px; }
        .avg-right { text-align:right; }
        .avg-right .avg-pct-label { font-size:0.72rem; font-weight:600; letter-spacing:0.08em; text-transform:uppercase; opacity:0.8; margin-bottom:3px; }
        .avg-right .avg-pct { font-size:1.5rem; font-weight:700; line-height:1; }

        .progress-strip { display:flex; gap:3px; margin-top:8px; justify-content:flex-end; }
        .ps-pip { width:18px; height:5px; border-radius:3px; background:rgba(255,255,255,0.25); transition:background .25s; }
        .ps-pip.on { background:rgba(255,255,255,0.85); }
    </style>
</head>

<body>
<div class="container-scroller">
    <?php include('../partials/_navbar.php'); ?>

    <div class="container-fluid page-body-wrapper">
        <?php include('../partials/_sidebar.php'); ?>

        <div class="main-panel">
            <div class="content-wrapper">

                <?php if (isset($_SESSION['msg'])): ?>
                <script>
                    document.addEventListener("DOMContentLoaded", function () {
                        Swal.fire({
                            icon: '<?= $_SESSION['status'] ? 'success' : 'error' ?>',
                            title: '<?= $_SESSION['status'] ? 'Success' : 'Error' ?>',
                            text: '<?= addslashes($_SESSION['msg']) ?>',
                            confirmButtonColor: '#4B49AC'
                        });
                    });
                </script>
                <?php unset($_SESSION['msg']); unset($_SESSION['status']); ?>
                <?php endif; ?>

                <div class="container mt-4">

                    <div class="d-flex align-items-center justify-content-between mb-3" style="flex-wrap:wrap; gap:10px;">
                        <div>
                            <h3 class="fw-bold mb-1">Edit Intern KRA</h3>
                            <p class="text-muted mb-0" style="font-size:0.875rem;">Week <?= (int)$kra['weekNumber'] ?>, <?= (int)$kra['weekYear'] ?></p>
                        </div>
                        <a href="intern_kra_list.php" class="btn btn-light btn-sm">Back to List</a>
                    </div>

                    <!-- Intern info -->
                    <div class="intern-info-box">
                        <div class="ib-item">
                            <strong><?= htmlspecialchars($kra['internName'], ENT_QUOTES, 'UTF-8') ?></strong>
                            Intern
                        </div>
                        <div class="ib-item">
                            <strong><?= htmlspecialchars($kra['submittedByName'], ENT_QUOTES, 'UTF-8') ?></strong>
                            Submitted By
                        </div>
                        <div class="ib-item">
                            <strong><?= date('d M Y, h:i A', strtotime($kra['submittedAt'])) ?></strong>
                            Submitted At
                        </div>
                        <div class="ib-item">
                            <strong>Week <?= (int)$kra['weekNumber'] ?> / <?= (int)$kra['weekYear'] ?></strong>
                            Period
                        </div>
                    </div>

                    <form method="POST" action="intern_kra_edit.php?kra_id=<?= $kraId ?>"
                          id="kraEditForm" onsubmit="return validateForm()">

                        <input type="hidden" name="average_percentage" id="averagePercentage" value="<?= htmlspecialchars($kra['averagePercentage'], ENT_QUOTES, 'UTF-8') ?>">

                        <!-- Context -->
                        <div class="card mb-4">
                            <div class="card-body" style="padding:24px;">
                                <p class="section-title">Context (Optional)</p>
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label class="fw-semibold mb-1" style="font-size:0.875rem;">Batch</label>
                                        <select name="batch" id="batchSelect" class="form-control" style="width:100%;">
                                            <option value="">-- Select Batch --</option>
                                            <?php
                                            if ($batchOptions && mysqli_num_rows($batchOptions) > 0):
                                                while ($b = mysqli_fetch_assoc($batchOptions)): ?>
                                                    <option value="<?= htmlspecialchars($b['batch_number'], ENT_QUOTES, 'UTF-8') ?>"
                                                        <?= ($kra['batch'] == $b['batch_number']) ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($b['batch_number'], ENT_QUOTES, 'UTF-8') ?>
                                                    </option>
                                                <?php endwhile;
                                            endif; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="fw-semibold mb-1" style="font-size:0.875rem;">Mode</label>
                                        <select name="mode" id="modeSelect" class="form-control" style="width:100%;">
                                            <option value="">-- Select Mode --</option>
                                            <option value="Online"  <?= ($kra['mode'] === 'Online')  ? 'selected' : '' ?>>Online</option>
                                            <option value="Offline" <?= ($kra['mode'] === 'Offline') ? 'selected' : '' ?>>Offline</option>
                                            <option value="Hybrid"  <?= ($kra['mode'] === 'Hybrid')  ? 'selected' : '' ?>>Hybrid</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="fw-semibold mb-1" style="font-size:0.875rem;">Office</label>
                                        <select name="office" id="officeSelect" class="form-control" style="width:100%;">
                                            <option value="">-- Select Office --</option>
                                            <?php
                                            if ($officeOptions && mysqli_num_rows($officeOptions) > 0):
                                                while ($o = mysqli_fetch_assoc($officeOptions)): ?>
                                                    <option value="<?= htmlspecialchars($o['office'], ENT_QUOTES, 'UTF-8') ?>"
                                                        <?= ($kra['office'] == $o['office']) ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($o['office'], ENT_QUOTES, 'UTF-8') ?>
                                                    </option>
                                                <?php endwhile;
                                            endif; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Average summary -->
                        <div class="avg-summary" id="avgSummary">
                            <div class="avg-left">
                                <div class="avg-label">Average Score</div>
                                <div class="avg-score" id="avgScore">—</div>
                                <div class="avg-sub" id="avgSub">—</div>
                            </div>
                            <div class="avg-right">
                                <div class="avg-pct-label">Performance</div>
                                <div class="avg-pct" id="avgPct">—</div>
                                <div class="progress-strip" id="progressStrip">
                                    <?php for ($i = 0; $i < 9; $i++): ?>
                                        <div class="ps-pip" id="psPip<?= $i ?>"></div>
                                    <?php endfor; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Parameters -->
                        <div class="card mb-4">
                            <div class="card-body" style="padding:24px;">
                                <p class="section-title">Performance Parameters</p>

                                <div class="row">
                                <?php
                                $params = [
                                    ['num' => 1, 'key' => 'communication',      'dbKey' => 'communication',      'remarkKey' => 'communicationRemark',      'label' => 'Communication Skills',     'col' => 'col-md-6'],
                                    ['num' => 2, 'key' => 'confidence',          'dbKey' => 'confidence',          'remarkKey' => 'confidenceRemark',          'label' => 'Confidence Level',         'col' => 'col-md-6'],
                                    ['num' => 3, 'key' => 'clarity',             'dbKey' => 'clarity',             'remarkKey' => 'clarityRemark',             'label' => 'Clarity of Thought',       'col' => 'col-md-6'],
                                    ['num' => 4, 'key' => 'teamwork',            'dbKey' => 'teamwork',            'remarkKey' => 'teamworkRemark',            'label' => 'Teamwork & Collaboration', 'col' => 'col-md-6'],
                                    ['num' => 5, 'key' => 'problem_solving',     'dbKey' => 'problemSolving',      'remarkKey' => 'problemSolvingRemark',      'label' => 'Problem Solving',          'col' => 'col-md-6'],
                                    ['num' => 6, 'key' => 'learning_attitude',   'dbKey' => 'learningAttitude',    'remarkKey' => 'learningAttitudeRemark',    'label' => 'Learning Attitude',        'col' => 'col-md-6'],
                                    ['num' => 7, 'key' => 'technical_knowledge', 'dbKey' => 'technicalKnowledge',  'remarkKey' => 'technicalKnowledgeRemark',  'label' => 'Technical Knowledge',      'col' => 'col-md-6'],
                                    ['num' => 8, 'key' => 'github_activity',     'dbKey' => 'githubActivity',      'remarkKey' => 'githubActivityRemark',      'label' => 'GitHub Evaluation / Activity', 'col' => 'col-md-6'],
                                    ['num' => 9, 'key' => 'ams_process_adherence', 'dbKey' => 'amsProcessAdherence', 'remarkKey' => 'amsProcessAdherenceRemark', 'label' => 'Following AMS Processes (Leaves, Task Addition/DSR, WFH, Regularization)', 'col' => 'col-md-6'],
                                ];
                                foreach ($params as $p):
                                    $currentVal    = (int)($kra[$p['dbKey']]    ?? 0);
                                    $currentRemark = $kra[$p['remarkKey']] ?? '';
                                ?>
                                    <div class="<?= $p['col'] ?> mb-3">
                                        <div class="kra-row" id="kraRow_<?= $p['key'] ?>">
                                            <div class="kra-row-header">
                                                <div class="kra-label">
                                                    <span class="num"><?= $p['num'] ?></span>
                                                    <?= htmlspecialchars($p['label']) ?>
                                                </div>
                                                <span class="score-badge" id="badge_<?= $p['key'] ?>">— / 10</span>
                                            </div>

                                            <div class="rating-stepper">
                                                <button type="button" class="stepper-btn" onclick="step('<?= $p['key'] ?>', -1)">&#8722;</button>
                                                <input type="number"
                                                       name="<?= $p['key'] ?>"
                                                       id="<?= $p['key'] ?>"
                                                       class="form-control rating-num-input"
                                                       min="1" max="10"
                                                       value="<?= $currentVal ?>"
                                                       required
                                                       oninput="clampVal(this, '<?= $p['key'] ?>')"
                                                       onchange="onRate('<?= $p['key'] ?>')">
                                                <button type="button" class="stepper-btn" onclick="step('<?= $p['key'] ?>', 1)">&#43;</button>
                                                <div class="pip-track" id="pips_<?= $p['key'] ?>">
                                                    <?php for ($i = 0; $i < 10; $i++): ?>
                                                        <div class="pip" id="pip_<?= $p['key'] ?>_<?= $i ?>"></div>
                                                    <?php endfor; ?>
                                                </div>
                                            </div>

                                            <textarea name="<?= $p['key'] ?>_remark"
                                                      class="remark-field"
                                                      rows="2"
                                                      placeholder="Brief remark (optional)"><?= htmlspecialchars($currentRemark, ENT_QUOTES, 'UTF-8') ?></textarea>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                </div>

                            </div>
                        </div>

                        <div class="mb-4">
                            <button type="submit" class="btn btn-warning mr-2">Update KRA</button>
                            <a href="intern_kra_list.php" class="btn btn-light">Cancel</a>
                        </div>

                    </form>
                </div>
            </div><!-- /.content-wrapper -->
        </div><!-- /.main-panel -->
    </div><!-- /.page-body-wrapper -->
</div><!-- /.container-scroller -->

<script src="../vendors/jquery/jquery.min.js"></script>
<script src="../vendors/select2/select2.min.js"></script>
<script>
var KEYS = ['communication','confidence','clarity','teamwork','problem_solving','learning_attitude','technical_knowledge','github_activity','ams_process_adherence'];

$(document).ready(function () {
    $('#batchSelect').select2({ width: '100%', placeholder: '-- Select Batch --', allowClear: true });
    $('#modeSelect').select2({ width: '100%', placeholder: '-- Select Mode --', allowClear: true });
    $('#officeSelect').select2({ width: '100%', placeholder: '-- Select Office --', allowClear: true });

    // Init all pips and badges from existing values
    KEYS.forEach(function (k) { onRate(k); });
});

function step(key, delta) {
    var input = document.getElementById(key);
    var val   = parseInt(input.value) || 0;
    val = Math.min(10, Math.max(1, val + delta));
    input.value = val;
    onRate(key);
}

function clampVal(input, key) {
    var val = parseInt(input.value);
    if (isNaN(val)) { onRate(key); return; }
    if (val < 1)  input.value = 1;
    if (val > 10) input.value = 10;
    onRate(key);
}

function onRate(key) {
    var input = document.getElementById(key);
    var val   = parseInt(input.value);
    var badge = document.getElementById('badge_' + key);

    if (isNaN(val) || val < 1 || val > 10) {
        badge.textContent = '— / 10';
        badge.className   = 'score-badge';
        setPips(key, 0);
    } else {
        badge.textContent = val + ' / 10';
        badge.className   = 'score-badge ' + (val >= 8 ? 'high' : val >= 5 ? 'mid' : 'low');
        setPips(key, val);
    }

    recalc();
}

function setPips(key, val) {
    for (var i = 0; i < 10; i++) {
        var pip = document.getElementById('pip_' + key + '_' + i);
        if (!pip) continue;
        pip.className = 'pip';
        if (i < val) {
            pip.classList.add(val >= 8 ? 'on-high' : val >= 5 ? 'on-mid' : 'on-low');
        }
    }
}

function recalc() {
    var sum = 0, count = 0;
    KEYS.forEach(function (k) {
        var v = parseInt(document.getElementById(k).value);
        if (!isNaN(v) && v >= 1 && v <= 10) { sum += v; count++; }
    });

    var panel   = document.getElementById('avgSummary');
    var scoreEl = document.getElementById('avgScore');
    var pctEl   = document.getElementById('avgPct');
    var subEl   = document.getElementById('avgSub');

    for (var i = 0; i < 9; i++) {
        var pip = document.getElementById('psPip' + i);
        if (pip) pip.classList.toggle('on', i < count);
    }

    if (count === 0) {
        scoreEl.textContent = '—';
        pctEl.textContent   = '—';
        subEl.textContent   = 'Fill all 9 parameters below';
        panel.className     = 'avg-summary';
        document.getElementById('averagePercentage').value = 0;
        return;
    }

    var avg    = sum / count;
    var avgPct = (avg / 10) * 100;

    scoreEl.textContent = avg.toFixed(2) + ' / 10';
    pctEl.textContent   = avgPct.toFixed(1) + '%';
    document.getElementById('averagePercentage').value = avgPct.toFixed(2);

    subEl.textContent = label + ' — ' + count + ' of 7 rated';

    panel.className = 'avg-summary ' + (avg >= 7.5 ? 'green' : avg >= 5 ? 'yellow' : 'red');
}

function validateForm() {
    for (var i = 0; i < KEYS.length; i++) {
        var val = parseInt(document.getElementById(KEYS[i]).value);
        if (isNaN(val) || val < 1 || val > 10) {
            Swal.fire({ icon: 'error', title: 'Incomplete Ratings', text: 'All 7 parameters must be rated between 1 and 10.', confirmButtonColor: '#4B49AC' });
            return false;
        }
    }
    return true;
}
</script>
</body>
</html>