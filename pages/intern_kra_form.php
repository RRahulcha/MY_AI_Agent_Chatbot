<?php
session_start();
 
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href='login.php';</script>";
    exit;
}
 
include('../include/db_config.php');
 
$query        = mysqli_query($con, "SELECT designation, userRole, fullName FROM signup WHERE userId = '" . mysqli_real_escape_string($con, $_SESSION['userId']) . "'");
$user         = mysqli_fetch_assoc($query);
$allowedRolesClean = ['seniorintern', 'teamlead', 'resourceallocator', 'resourcehead', 'resourcemanager', 'admin', 'superadmin', 'hr'];

$desigClean = strtolower(str_replace([' ', '_'], '', $user['designation'] ?? ''));
$roleClean  = strtolower(str_replace([' ', '_'], '', $user['userRole'] ?? ''));

if (!$user || (!in_array($desigClean, $allowedRolesClean) && !in_array($roleClean, $allowedRolesClean))) {
    echo "<script>alert('Unauthorized Access'); window.location.href='logout.php';</script>";
    exit;
}
 
$designationLabels = [
    'juniorIntern'      => 'Junior Intern',
    'seniorIntern'      => 'Senior Intern',
    'teamLead'          => 'Team Lead',
    'resourceAllocator' => 'Resource Allocator'
];
$userDesignationLabel = $designationLabels[$user['designation']] ?? $user['designation'];
 
$currentUserId = mysqli_real_escape_string($con, $_SESSION['userId']);
$userFullName  = mysqli_real_escape_string($con, $user['fullName'] ?? '');
$userRoleClean = strtolower(str_replace([' ', '_'], '', $user['designation'] ?? ''));

$isAdminOrHrOrAllocator = in_array($userRoleClean, ['admin', 'hr', 'resourceallocator', 'superadmin']);

// All roles (Admin, HR, Allocators, Senior Interns, Team Leads) evaluate JUNIOR INTERNS ONLY
$internWhere = "WHERE (designation IN ('juniorIntern', 'junior_intern') OR userRole = 'junior_intern') AND status = 'active'";

$internOptions = mysqli_query($con,
    "SELECT userId, fullName FROM signup
     $internWhere
     ORDER BY fullName ASC"
);

 
$batchOptions = mysqli_query($con,
    "SELECT DISTINCT batch_number FROM signup
     WHERE batch_number IS NOT NULL AND batch_number != ''
     ORDER BY batch_number ASC"
);
 
$officeOptions = mysqli_query($con,
    "SELECT DISTINCT office FROM signup
     WHERE office IS NOT NULL AND office != ''
     ORDER BY office ASC"
);
 
$branchOptions = mysqli_query($con,
    "SELECT DISTINCT branch FROM signup
     WHERE branch IS NOT NULL AND branch != ''
     ORDER BY branch ASC"
);
 
$submissionsQuery = mysqli_query($con,
    "SELECT k.*, s.fullName AS intern_full_name
     FROM internKraAssignments k
     LEFT JOIN signup s ON k.internId = s.userId
     WHERE k.submittedBy = '" . mysqli_real_escape_string($con, $_SESSION['userId']) . "'
     ORDER BY k.submittedAt DESC"
);
$submissions = [];
while ($row = mysqli_fetch_assoc($submissionsQuery)) {
    $submissions[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Intern KRA Assignment</title>
    <?php include('../partials/_links.php'); ?>
    <link href="../vendors/select2/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <style>
        /* ── Base reset ─────────────────────────────────── */
        *, *::before, *::after { box-sizing: border-box; }
 
        /* ── Card ───────────────────────────────────────── */
        .card {
            border: 1px solid #eeeef5;
            border-radius: 12px;
            background: #fff;
            box-shadow: 0 1px 4px rgba(0,0,0,0.05);
            margin-bottom: 14px;
        }
 
        .card-body {
            padding: 20px 22px !important;
        }
 
        /* ── Section title ──────────────────────────────── */
        .section-title {
            font-size: 0.68rem;
            font-weight: 700;
            color: #a0a0b8;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
 
        .section-title::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #f0f0f5;
        }
 
        .section-divider {
            border: none;
            border-top: 1px solid #f0f0f5;
            margin: 16px 0;
        }
 
        /* ── Form labels ────────────────────────────────── */
        .form-label-sm {
            font-size: 0.73rem;
            font-weight: 600;
            color: #7a7a90;
            letter-spacing: 0.02em;
            margin-bottom: 5px;
            display: block;
        }
 
        /* ── Inputs ─────────────────────────────────────── */
        .form-control {
            font-size: 0.84rem;
            border: 1px solid #e2e2ec;
            border-radius: 7px;
            height: 36px;
            color: #3a3a4a;
            background: #fafafa;
            transition: border-color 0.15s, box-shadow 0.15s;
            padding: 5px 10px;
        }
 
        .form-control:focus {
            border-color: #4B49AC;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(75,73,172,0.08);
            outline: none;
        }
 
        /* ── Auto-fill fields ───────────────────────────── */
        .autofill-input {
            background: #f5f5fb !important;
            color: #8888a0 !important;
            cursor: default !important;
            border: 1px solid #eaeaf2 !important;
        }
 
        /* ── Designation badge ───────────────────────────── */
        .designation-badge {
            display: inline-block;
            font-size: 0.67rem;
            font-weight: 600;
            color: #4B49AC;
            background: #eeecf9;
            border-radius: 5px;
            padding: 2px 8px;
            margin-left: 7px;
            vertical-align: middle;
            letter-spacing: 0.01em;
        }
 
        /* ── Submitted-by wrapper ────────────────────────── */
        .submitted-by-wrapper {
            display: flex;
            align-items: center;
            height: 36px;
            background: #f5f5fb;
            border: 1px solid #eaeaf2;
            border-radius: 7px;
            padding: 5px 10px;
            font-size: 0.84rem;
            color: #7a7a90;
        }
 
        /* ── Intern strip ───────────────────────────────── */
        .intern-strip {
            display: none;
            background: #f4f7ff;
            border: 1px solid #dce5f8;
            border-radius: 8px;
            padding: 10px 14px;
            margin-top: 8px;
        }
 
        .intern-strip.visible { display: block; }
 
        .intern-strip-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 4px 22px;
        }
 
        .intern-strip .is-item {
            font-size: 0.77rem;
            color: #5a6678;
        }
 
        .intern-strip .is-item strong {
            font-weight: 600;
            color: #2d3a4a;
        }
 
        /* ── KRA row ────────────────────────────────────── */
        .kra-row {
            background: #fafafa;
            border-radius: 9px;
            padding: 12px 14px 10px;
            margin-bottom: 10px;
            border: 1px solid #ececf4;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
 
        .kra-row:hover {
            border-color: #c8c7e8;
            box-shadow: 0 2px 8px rgba(75,73,172,0.06);
        }
 
        .kra-row-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 9px;
        }
 
        .kra-label {
            font-size: 0.835rem;
            font-weight: 600;
            color: #2d2c5e;
            display: flex;
            align-items: center;
            gap: 8px;
        }
 
        .kra-label .num {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 19px;
            height: 19px;
            background: #4B49AC;
            color: #fff;
            border-radius: 50%;
            font-size: 0.61rem;
            font-weight: 700;
            flex-shrink: 0;
        }
 
        /* ── Score badge ────────────────────────────────── */
        .score-badge {
            font-size: 0.7rem;
            font-weight: 700;
            color: #9090a8;
            background: #f0f0f8;
            padding: 3px 10px;
            border-radius: 20px;
            min-width: 52px;
            text-align: center;
            transition: background 0.2s, color 0.2s;
        }
 
        .score-badge.high { background: #d1fae5; color: #065f46; }
        .score-badge.mid  { background: #fef3c7; color: #92400e; }
        .score-badge.low  { background: #fee2e2; color: #991b1b; }
 
        /* ── Stepper ────────────────────────────────────── */
        .rating-stepper {
            display: flex;
            align-items: center;
            gap: 7px;
            margin-bottom: 9px;
        }
 
        .stepper-btn {
            width: 28px;
            height: 28px;
            border-radius: 6px;
            border: 1px solid #e2e2ec;
            background: #fff;
            color: #666;
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
 
        .stepper-btn:hover  { border-color: #4B49AC; color: #4B49AC; background: #eeecf9; }
        .stepper-btn:active { transform: scale(0.94); }
 
        .rating-num-input {
            width: 56px !important;
            text-align: center !important;
            font-weight: 700 !important;
            font-size: 0.88rem !important;
            color: #4B49AC !important;
            border: 1px solid #e2e2ec !important;
            border-radius: 7px !important;
            padding: 4px 6px !important;
            height: 30px !important;
            flex-shrink: 0;
            -moz-appearance: textfield;
        }
 
        .rating-num-input::-webkit-outer-spin-button,
        .rating-num-input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
 
        .rating-num-input:focus {
            border-color: #4B49AC !important;
            box-shadow: 0 0 0 3px rgba(75,73,172,0.08) !important;
            outline: none !important;
        }
 
        /* ── Pip track ──────────────────────────────────── */
        .pip-track { display: flex; gap: 3px; flex: 1; align-items: center; }
        .pip { flex: 1; height: 3px; border-radius: 2px; background: #e8e8f0; transition: background 0.18s; }
        .pip.on-high { background: #00b37e; }
        .pip.on-mid  { background: #f59e0b; }
        .pip.on-low  { background: #ef4444; }
 
        /* ── Remark ─────────────────────────────────────── */
        .remark-field {
            font-size: 0.775rem !important;
            color: #555 !important;
            border: 1px solid #e8e8f0 !important;
            border-radius: 6px !important;
            resize: none !important;
            padding: 6px 10px !important;
            width: 100%;
            font-family: inherit !important;
            background: #fff !important;
            transition: border-color 0.15s, box-shadow 0.15s;
        }
 
        .remark-field:focus {
            outline: none;
            border-color: #4B49AC !important;
            box-shadow: 0 0 0 3px rgba(75,73,172,0.08) !important;
        }
 
        .remark-field::placeholder { color: #bbb; }
 
        /* ── Average summary panel ──────────────────────── */
        .avg-summary {
            border-radius: 12px;
            padding: 18px 22px;
            background: #4B49AC;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            transition: background 0.35s;
            margin-bottom: 14px;
            border: none;
        }
 
        .avg-summary.green  { background: #059669; }
        .avg-summary.yellow { background: #d97706; }
        .avg-summary.red    { background: #dc2626; }
 
        .avg-left .avg-label  { font-size: 0.67rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; opacity: 0.7; margin-bottom: 3px; }
        .avg-left .avg-score  { font-size: 1.85rem; font-weight: 700; line-height: 1; letter-spacing: -0.02em; }
        .avg-left .avg-sub    { font-size: 0.73rem; opacity: 0.7; margin-top: 4px; }
        .avg-right            { text-align: right; }
        .avg-right .avg-pct-label { font-size: 0.67rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; opacity: 0.7; margin-bottom: 3px; }
        .avg-right .avg-pct   { font-size: 1.35rem; font-weight: 700; line-height: 1; }
        .filled-count         { font-size: 0.7rem; opacity: 0.65; margin-top: 5px; }
 
        .progress-strip       { display: flex; gap: 4px; margin-top: 7px; justify-content: flex-end; }
        .ps-pip               { width: 14px; height: 3px; border-radius: 3px; background: rgba(255,255,255,0.2); transition: background .25s; }
        .ps-pip.on            { background: rgba(255,255,255,0.8); }
 
        /* ── Buttons ────────────────────────────────────── */
        .btn-primary {
            background: #4B49AC;
            border-color: #4B49AC;
            border-radius: 8px;
            font-size: 0.83rem;
            font-weight: 600;
            padding: 8px 22px;
            letter-spacing: 0.02em;
            transition: background 0.15s, box-shadow 0.15s, transform 0.1s;
        }
 
        .btn-primary:hover {
            background: #3f3e96;
            border-color: #3f3e96;
            box-shadow: 0 4px 14px rgba(75,73,172,0.25);
            transform: translateY(-1px);
        }
 
        .btn-primary:active { transform: translateY(0); }
 
        .btn-light {
            border-radius: 8px;
            font-size: 0.83rem;
            font-weight: 500;
            padding: 8px 18px;
            background: #f2f2f8;
            border-color: #e2e2ec;
            color: #666;
            transition: background 0.15s, transform 0.1s;
        }
 
        .btn-light:hover {
            background: #e8e7f4;
            border-color: #cccce0;
            transform: translateY(-1px);
        }
 
        .btn-outline-primary {
            border-radius: 8px;
            font-size: 0.83rem;
            font-weight: 600;
            padding: 8px 18px;
            border: 1.5px solid #4B49AC;
            color: #4B49AC;
            background: #fff;
            transition: background 0.15s, color 0.15s, transform 0.1s;
        }
 
        .btn-outline-primary:hover {
            background: #4B49AC;
            color: #fff;
            transform: translateY(-1px);
        }
 
        /* ── Page header ────────────────────────────────── */
        .page-title {
            font-size: 1.2rem;
            font-weight: 700;
            color: #2d2c5e;
            margin-bottom: 3px;
            letter-spacing: -0.01em;
        }
 
        .page-subtitle {
            font-size: 0.8rem;
            color: #9090a8;
            margin-bottom: 16px;
        }
 
        /* ── Select2 ────────────────────────────────────── */
        .select2-container--default .select2-selection--single {
            height: 36px;
            display: flex;
            align-items: center;
            border: 1px solid #e2e2ec;
            border-radius: 7px;
            background: #fafafa;
        }
 
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            padding-left: 10px;
            font-size: 0.84rem;
            color: #3a3a4a;
            line-height: 34px;
        }
 
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 34px;
        }
 
        .select2-container--default .select2-selection--single .select2-selection__clear {
            display: none !important;
        }
 
        .select2-container--default.select2-container--focus .select2-selection--single,
        .select2-container--default.select2-container--open  .select2-selection--single {
            border-color: #4B49AC;
            box-shadow: 0 0 0 3px rgba(75,73,172,0.08);
            outline: none;
        }
 
        .select2-dropdown {
            border: 1px solid #e2e2ec;
            border-radius: 8px;
            box-shadow: 0 6px 20px rgba(0,0,0,0.08);
            font-size: 0.84rem;
        }
 
        .select2-results__option--highlighted { background-color: #4B49AC !important; }
 
        /* ── Spacing ────────────────────────────────────── */
        .mb-3 { margin-bottom: 12px !important; }
        .mb-4 { margin-bottom: 16px !important; }
        .mt-4 { margin-top: 16px !important; }
        .mb-5 { margin-bottom: 22px !important; }
 
        .fw-semibold { font-weight: 600; }
        .table thead th { background-color: #4B49AC !important; color: white !important; }
 
        /* ── Submissions section ─────────────────────────── */
        #submissionsSection {
            display: none;
            margin-top: 8px;
            margin-bottom: 30px;
        }
 
        #submissionsSection.visible { display: block; }
 
        .submissions-card {
            border: 1px solid #eeeef5;
            border-radius: 12px;
            background: #fff;
            box-shadow: 0 1px 4px rgba(0,0,0,0.05);
            overflow: hidden;
        }
 
        .submissions-card .card-header {
            background: #4B49AC;
            padding: 13px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-radius: 0;
        }
 
        .submissions-card .card-header h6 {
            color: #fff;
            font-size: 0.86rem;
            font-weight: 700;
            margin: 0;
            letter-spacing: 0.02em;
        }
 
        .submissions-card .card-header .badge-count {
            background: rgba(255,255,255,0.18);
            color: #fff;
            font-size: 0.7rem;
            font-weight: 700;
            padding: 2px 10px;
            border-radius: 20px;
        }
 
        .submissions-table-wrap { overflow-x: auto; }
 
        .submissions-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.79rem;
        }
 
        .submissions-table thead th {
            background: #4B49AC !important;
            color: #fff !important;
            font-weight: 700;
            font-size: 0.7rem;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            padding: 10px 14px;
            white-space: nowrap;
            border: none;
        }
 
        .submissions-table tbody tr {
            border-bottom: 1px solid #f2f2f7;
            transition: background 0.15s;
        }
 
        .submissions-table tbody tr:hover { background: #f8f7ff; }
 
        .submissions-table tbody td {
            padding: 9px 14px;
            color: #3a3a4a;
            vertical-align: middle;
            white-space: nowrap;
        }
 
        .submissions-table .avg-pill {
            display: inline-block;
            font-size: 0.7rem;
            font-weight: 700;
            padding: 2px 10px;
            border-radius: 20px;
        }
 
        .submissions-table .avg-pill.high { background: #d1fae5; color: #065f46; }
        .submissions-table .avg-pill.mid  { background: #fef3c7; color: #92400e; }
        .submissions-table .avg-pill.low  { background: #fee2e2; color: #991b1b; }
 
        .submissions-empty {
            text-align: center;
            padding: 40px 20px;
            color: #bbb;
            font-size: 0.84rem;
        }
 
        .submissions-empty i {
            display: block;
            font-size: 2rem;
            margin-bottom: 8px;
            opacity: 0.35;
        }
 
        /* Toggle arrow */
        .toggle-arrow {
            display: inline-block;
            transition: transform 0.25s;
            margin-left: 5px;
            font-size: 0.7rem;
            opacity: 0.7;
        }
 
        .toggle-arrow.open { transform: rotate(180deg); }
 
        /* ── Divider between form sections ──────────────── */
        .row + .row { margin-top: 0; }
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
 
                    <!-- Page header -->
                    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                        <div>
                            <h3 class="page-title mb-0">Intern KRA Assignment</h3>
                            <p class="page-subtitle mb-0">Rate intern performance across 7 key parameters &mdash; Scale: 1 (Poor) to 10 (Excellent)</p>
                        </div>
                        <div style="display:flex; gap:8px;">
                            <?php if ($isAdminOrHrOrAllocator || in_array($userRoleClean, ['seniorintern', 'teamlead'])): ?>
                            <a href="kraDashboard.php" class="btn btn-outline-primary btn-sm" style="border-radius:8px; font-weight:600;"><i class="fas fa-brain mr-1"></i> KRA Analytics Dashboard</a>
                            <?php endif; ?>
                            <a href="intern_kra_list.php" class="btn btn-outline-secondary btn-sm" style="border-radius:8px; font-weight:600;"><i class="fas fa-list-alt mr-1"></i> KRA Submissions</a>
                        </div>
                    </div>
 
                    <form method="POST" action="intern_kra_submit.php" id="kraForm" onsubmit="return validateForm()">
 
                        <input type="hidden" name="intern_name" id="internName">
                        <input type="hidden" name="week_number" id="hiddenWeekNumber" value="">
                        <input type="hidden" name="week_year"   id="hiddenWeekYear"   value="">
                        <input type="hidden" name="average_percentage" id="averagePercentage" value="0">
 
                        <!-- ── Section 1: Intern Information ──── -->
                        <div class="card">
                            <div class="card-body">
 
                                <p class="section-title">Intern Information</p>
 
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label-sm">Select Intern <span class="text-danger">*</span></label>
                                        <select name="intern_id" id="internSelect" class="form-control" required style="width:100%;">
                                            <option value="">-- Select Intern --</option>
                                            <?php
                                            if ($internOptions && mysqli_num_rows($internOptions) > 0):
                                                while ($intern = mysqli_fetch_assoc($internOptions)): ?>
                                                    <option value="<?= htmlspecialchars($intern['userId'], ENT_QUOTES, 'UTF-8') ?>">
                                                        <?= htmlspecialchars($intern['fullName'], ENT_QUOTES, 'UTF-8') ?>
                                                    </option>
                                                <?php endwhile;
                                            else: ?>
                                                <option value="" disabled>No active interns found</option>
                                            <?php endif; ?>
                                        </select>
                                    </div>
 
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label-sm">Week <span class="text-danger">*</span></label>
                                        <select name="_week_select" id="weekSelect" class="form-control" required style="width:100%;">
                                            <option value="">-- Select intern first --</option>
                                        </select>
                                        <small id="weekHint" class="text-muted" style="font-size:0.7rem; display:none;">Only unfilled weeks shown</small>
                                    </div>
 
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label-sm">Submitted By</label>
                                        <div class="submitted-by-wrapper">
                                            <span><?= htmlspecialchars($user['fullName'], ENT_QUOTES, 'UTF-8') ?></span>
                                            <span class="designation-badge"><?= htmlspecialchars($userDesignationLabel, ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                    </div>
                                </div>
 
                                <div class="intern-strip" id="internStrip">
                                    <div class="intern-strip-grid" id="internStripGrid"></div>
                                </div>
 
                                <!-- Hidden context fields — auto-filled from intern data via AJAX, not shown to user -->
                                <input type="hidden" name="batch"  id="batchSelect">
                                <input type="hidden" name="mode"   id="modeSelect">
                                <input type="hidden" name="office" id="officeSelect">

                            </div>
                        </div>
 
                        <!-- ── Average Summary Panel ─────────── -->
                        <div class="avg-summary" id="avgSummary">
                            <div class="avg-left">
                                <div class="avg-label">Average Score</div>
                                <div class="avg-score" id="avgScore">—</div>
                                <div class="avg-sub"   id="avgSub">Fill all 9 parameters below</div>
                            </div>
                            <div class="avg-right">
                                <div class="avg-pct-label">Performance</div>
                                <div class="avg-pct" id="avgPct">—</div>
                                <div class="filled-count" id="filledCount">0 of 9 rated</div>
                                <div class="progress-strip">
                                    <?php for ($i = 0; $i < 9; $i++): ?>
                                        <div class="ps-pip" id="psPip<?= $i ?>"></div>
                                    <?php endfor; ?>
                                </div>
                            </div>
                        </div>
 
                        <!-- ── Section 2: Performance Parameters -->
                        <div class="card">
                            <div class="card-body">
 
                                <p class="section-title">Performance Parameters</p>
 
                                <div class="row">
                                <?php
                                $params = [
                                    ['num' => 1, 'key' => 'communication',      'label' => 'Communication Skills',     'col' => 'col-md-6'],
                                    ['num' => 2, 'key' => 'confidence',          'label' => 'Confidence Level',         'col' => 'col-md-6'],
                                    ['num' => 3, 'key' => 'clarity',             'label' => 'Clarity of Thought',       'col' => 'col-md-6'],
                                    ['num' => 4, 'key' => 'teamwork',            'label' => 'Teamwork & Collaboration', 'col' => 'col-md-6'],
                                    ['num' => 5, 'key' => 'problem_solving',     'label' => 'Problem Solving',          'col' => 'col-md-6'],
                                    ['num' => 6, 'key' => 'learning_attitude',   'label' => 'Learning Attitude',        'col' => 'col-md-6'],
                                    ['num' => 7, 'key' => 'technical_knowledge', 'label' => 'Technical Knowledge',      'col' => 'col-md-6'],
                                    ['num' => 8, 'key' => 'github_activity',     'label' => 'GitHub Evaluation / Activity', 'col' => 'col-md-6'],
                                    ['num' => 9, 'key' => 'ams_process_adherence', 'label' => 'Following AMS Processes (Leaves, Task Addition/DSR, WFH, Regularization)', 'col' => 'col-md-6'],
                                ];
                                foreach ($params as $p):
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
                                                       placeholder="—"
                                                       required
                                                       oninput="clampVal(this, '<?= $p['key'] ?>')"
                                                       onchange="onRate('<?= $p['key'] ?>')">
                                                <button type="button" class="stepper-btn" onclick="step('<?= $p['key'] ?>', 1)">&#43;</button>
                                                <div class="pip-track">
                                                    <?php for ($i = 0; $i < 10; $i++): ?>
                                                        <div class="pip" id="pip_<?= $p['key'] ?>_<?= $i ?>"></div>
                                                    <?php endfor; ?>
                                                </div>
                                            </div>
 
                                            <textarea name="<?= $p['key'] ?>_remark"
                                                      class="remark-field"
                                                      rows="2"
                                                      placeholder="Brief remark (optional)"></textarea>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                </div>
 
                            </div>
                        </div>
 
                        <!-- Submit / Reset / View Submissions -->
                        <div class="mb-5 d-flex align-items-center" style="gap:10px; flex-wrap:wrap;">
                            <button type="submit" class="btn btn-primary">Submit KRA</button>
                            <button type="button" class="btn btn-light" onclick="resetForm()">Clear All</button>
                            <button type="button" class="btn btn-outline-primary" id="toggleSubmissionsBtn" onclick="toggleSubmissions()">
                                View My Submissions <span class="toggle-arrow" id="toggleArrow">&#9660;</span>
                            </button>
                        </div>
 
                    </form>
 
                    <!-- ── My Submissions Table ──────────────── -->
                    <div id="submissionsSection">
                        <div class="submissions-card">
                            <div class="card-header">
                                <h6>&#128203; My KRA Submissions</h6>
                                <span class="badge-count"><?= count($submissions) ?> record<?= count($submissions) !== 1 ? 's' : '' ?></span>
                            </div>
                            <div class="submissions-table-wrap">
                                <?php if (count($submissions) > 0): ?>
                               <table class="submissions-table" id="submissionsTable">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Intern Name</th>
                                            <th>Batch</th>
                                            <th>Mode</th>
                                            <th>Office</th>
                                            <th>Week</th>
                                            <th>Comm.</th>
                                            <th>Conf.</th>
                                            <th>Clarity</th>
                                            <th>Teamwork</th>
                                            <th>Prob. Solving</th>
                                            <th>Learning</th>
                                            <th>Technical</th>
                                            <th>GitHub</th>
                                            <th>AMS Processes</th>
                                            <th>Avg %</th>
                                            <th>Submitted On</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($submissions as $idx => $sub):
                                            $avgPct = floatval($sub['averagePercentage'] ?? 0);
                                            $pillClass = $avgPct >= 75 ? 'high' : ($avgPct >= 50 ? 'mid' : 'low');
                                        ?>
                                        <tr>
                                            <td></td>
                                            <td><strong><?= htmlspecialchars($sub['intern_full_name'] ?? $sub['intern_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?></strong></td>
                                            <td><?= htmlspecialchars($sub['batch'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars($sub['mode'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars($sub['office'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?php
    if (!empty($sub['weekNumber']) && !empty($sub['weekYear'])) {
        $dto = new DateTime();
        $dto->setISODate((int)$sub['weekYear'], (int)$sub['weekNumber']);
        $weekOfMonth = (int)ceil((int)$dto->format('j') / 7);
        echo htmlspecialchars($dto->format('M') . '-' . $weekOfMonth, ENT_QUOTES, 'UTF-8');
    } else { echo '—'; }
?></td>
                                            <td><?= htmlspecialchars($sub['communication'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars($sub['confidence'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars($sub['clarity'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars($sub['teamwork'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars($sub['problemSolving'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars($sub['learningAttitude'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars($sub['technicalKnowledge'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars($sub['githubActivity'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars($sub['amsProcessAdherence'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                                            <td>
                                                <span class="avg-pill <?= $pillClass ?>">
                                                    <?= number_format($avgPct, 1) ?>%
                                                </span>
                                            </td>
                                            <td data-order="<?= isset($sub['submittedAt']) ? strtotime($sub['submittedAt']) : 0 ?>"><?= htmlspecialchars(isset($sub['submittedAt']) ? date('d M Y, h:i A', strtotime($sub['submittedAt'])) : '—', ENT_QUOTES, 'UTF-8') ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <?php else: ?>
                                <div class="submissions-empty">
                                    <i>&#128203;</i>
                                    No submissions found. Submit a KRA above to see it here.
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
 
                </div>
 
            </div>
        </div>
    </div>
</div>
 
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="../vendors/select2/select2.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
<script>
$(document).ready(function () {
    $('#internSelect').select2({ width: '100%', placeholder: '-- Select Intern --', allowClear: false });
    $('#weekSelect').select2({ width: '100%', placeholder: '-- Select intern first --', allowClear: false });
 
    $('#internSelect').on('change', function () { loadInternDetails(); });
    $('#weekSelect').on('change', function() {
        var val = $(this).val();
        if (val) {
            var parts = val.split('_');
            $('#hiddenWeekYear').val(parts[0] || '');
            $('#hiddenWeekNumber').val(parts[1] || '');
        } else {
            $('#hiddenWeekYear').val('');
            $('#hiddenWeekNumber').val('');
        }
    });
    <?php if (count($submissions) > 0): ?>
$('#submissionsTable').DataTable({
    order: [[15, 'desc']],
    orderFixed: { pre: [[15, 'desc']] },
    pageLength: 10,
    lengthMenu: [10, 15, 20, 25, 50, 100],
    language: { search: 'Search submissions:' },
    deferRender: true,
    columnDefs: [
        {
            targets: 0,
            searchable: false,
            orderable: false,
            render: function (data, type, row, meta) {
                return meta.row + 1;
            }
        }
    ]
});
<?php endif; ?>
});
 
var KEYS = ['communication','confidence','clarity','teamwork','problem_solving','learning_attitude','technical_knowledge','github_activity','ams_process_adherence'];
 
function toggleSubmissions() {
    var section = document.getElementById('submissionsSection');
    var arrow   = document.getElementById('toggleArrow');
    var isOpen  = section.classList.contains('visible');
 
    if (isOpen) {
        section.classList.remove('visible');
        arrow.classList.remove('open');
    } else {
        section.classList.add('visible');
        arrow.classList.add('open');
        $('#submissionsTable').DataTable().columns.adjust().draw();
        section.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}
 
function loadInternDetails() {
    var internId = $('#internSelect').val();
    var strip    = document.getElementById('internStrip');
 
    if (!internId) {
        strip.classList.remove('visible');
        document.getElementById('internName').value = '';
        clearDropdowns();
        // Reset week dropdown
        $('#weekSelect').empty().append('<option value="">-- Select intern first --</option>').trigger('change');
        document.getElementById('weekHint').style.display = 'none';
        $('#hiddenWeekNumber').val('');
        $('#hiddenWeekYear').val('');
        return;
    }
 
    fetch('intern_kra_ajax.php?action=get_intern_details&intern_id=' + encodeURIComponent(internId))
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.success) {
                strip.classList.remove('visible');
                clearDropdowns();
                return;
            }
 
            document.getElementById('internName').value = data.fullName || '';
 
            setSelect2('#batchSelect', data.batch);
            setSelect2('#modeSelect', data.mode);
            setSelect2('#officeSelect', data.office);
            setSelect2('#branchSelect', data.branch);
 
            var chips = [];
            if (data.fullName)    chips.push({ label: 'Name',        val: data.fullName });
            if (data.designation) chips.push({ label: 'Designation', val: formatDesignation(data.designation) });
            if (data.department)  chips.push({ label: 'Department',  val: data.department });
            if (data.branch)      chips.push({ label: 'Branch',      val: data.branch });
            if (data.office)      chips.push({ label: 'Office',      val: data.office });
            if (data.mode)        chips.push({ label: 'Mode',        val: data.mode });
            if (data.batch)       chips.push({ label: 'Batch',       val: data.batch });
 
            var html = '';
            chips.forEach(function (c) {
                html += '<div class="is-item">' + escHtml(c.label) + ': <strong>' + escHtml(c.val) + '</strong></div>';
            });
 
            document.getElementById('internStripGrid').innerHTML = html;
            strip.classList.add('visible');
        })
        .catch(function (e) {
            console.error('AJAX error:', e);
            strip.classList.remove('visible');
        });

    // Load unfilled weeks for the selected intern
    loadWeeks(internId);
}
 
function loadWeeks(internId) {
    var $select = $('#weekSelect');
    // Show loading state
    $select.empty().append('<option value="">Loading weeks...</option>').trigger('change');
    $('#hiddenWeekNumber').val('');
    $('#hiddenWeekYear').val('');

    fetch('intern_kra_ajax.php?action=get_unfilled_weeks&intern_id=' + encodeURIComponent(internId))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            $select.empty();
            if (!data.success || !data.weeks || data.weeks.length === 0) {
                $select.append('<option value="">✅ All weeks filled!</option>').trigger('change');
                document.getElementById('weekHint').style.display = 'none';
                return;
            }
            $select.append('<option value="">-- Select Week --</option>');
            data.weeks.forEach(function(w) {
                $select.append('<option value="' + w.value + '">' + w.label + '</option>');
            });
            $select.trigger('change');
            document.getElementById('weekHint').style.display = 'block';
            document.getElementById('weekHint').textContent = data.weeks.length + ' unfilled week(s) available';
        })
        .catch(function(e) {
            console.error('Week load error:', e);
            $select.empty().append('<option value="">Error loading weeks</option>').trigger('change');
        });
}

function setSelect2(selector, value) {
    // For plain hidden inputs (batch, mode, office), just set the value directly
    if (!value) {
        $(selector).val('');
        return;
    }
    $(selector).val(value);
}
 
function clearDropdowns() {
    $('#batchSelect').val('');
    $('#modeSelect').val('');
    $('#officeSelect').val('');
}
 
function formatDesignation(d) {
    var map = {
        'juniorIntern'      : 'Junior Intern',
        'seniorIntern'      : 'Senior Intern',
        'teamLead'          : 'Team Lead',
        'resourceAllocator' : 'Resource Allocator'
    };
    return map[d] || d;
}
 
function escHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}
 
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
    if (window._resetting) return;
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
    var countEl = document.getElementById('filledCount');
 
    countEl.textContent = count + ' of 7 rated';
 
    for (var i = 0; i < 7; i++) {
        var pip = document.getElementById('psPip' + i);
        if (pip) pip.classList.toggle('on', i < count);
    }
 
    if (count === 0) {
        scoreEl.textContent = '—';
        pctEl.textContent   = '—';
        subEl.textContent   = 'Fill all 7 parameters below';
        panel.className     = 'avg-summary';
        document.getElementById('averagePercentage').value = 0;
        return;
    }
 
    var avg    = sum / count;
    var avgPct = (avg / 10) * 100;
 
    scoreEl.textContent = avg.toFixed(2) + ' / 10';
    pctEl.textContent   = avgPct.toFixed(1) + '%';
    document.getElementById('averagePercentage').value = avgPct.toFixed(2);
 
    if (count < 7) {
        subEl.textContent = count + ' of 7 rated — complete all to submit';
    } else {
        var label = avg >= 8 ? 'Excellent' : avg >= 6 ? 'Good' : avg >= 4 ? 'Average' : 'Needs Improvement';
        subEl.textContent = label + ' — all parameters rated';
    }
 
    panel.className = 'avg-summary ' + (avg >= 7.5 ? 'green' : avg >= 5 ? 'yellow' : 'red');
}
 
function validateForm() {
    if (!$('#internSelect').val()) {
        Swal.fire({ icon: 'error', title: 'Missing Field', text: 'Please select an intern.', confirmButtonColor: '#4B49AC' });
        return false;
    }
    if (!$('#weekSelect').val()) {
        Swal.fire({ icon: 'error', title: 'Missing Field', text: 'Please select a week for this KRA submission.', confirmButtonColor: '#4B49AC' });
        return false;
    }
    if (!$('#hiddenWeekNumber').val() || !$('#hiddenWeekYear').val()) {
        Swal.fire({ icon: 'error', title: 'Missing Field', text: 'Week data is incomplete. Please re-select the week.', confirmButtonColor: '#4B49AC' });
        return false;
    }
    for (var i = 0; i < KEYS.length; i++) {
        var val = parseInt(document.getElementById(KEYS[i]).value);
        if (isNaN(val) || val < 1 || val > 10) {
            Swal.fire({ icon: 'error', title: 'Incomplete Ratings', text: 'All 7 parameters must be rated between 1 and 10.', confirmButtonColor: '#4B49AC' });
            return false;
        }
    }
    return true;
}

 
function resetForm() {
    window._resetting = true;
 
    document.getElementById('kraForm').reset();
    hardResetPips();
 
    KEYS.forEach(function (k) {
        var input = document.getElementById(k);
        if (input) input.value = '';
 
        var badge = document.getElementById('badge_' + k);
        badge.textContent = '— / 10';
        badge.className   = 'score-badge';
 
        for (var i = 0; i < 10; i++) {
            var pip = document.getElementById('pip_' + k + '_' + i);
            if (pip) pip.className = 'pip';
        }
    });
 
    window._resetting = false;
 
    document.getElementById('internName').value        = '';
    document.getElementById('averagePercentage').value = '0';
    document.getElementById('avgScore').textContent    = '—';
    document.getElementById('avgPct').textContent      = '—';
    document.getElementById('avgSub').textContent      = 'Fill all 7 parameters below';
    document.getElementById('filledCount').textContent = '0 of 7 rated';
    document.getElementById('avgSummary').className    = 'avg-summary';
    document.getElementById('internStrip').classList.remove('visible');
    document.getElementById('internStripGrid').innerHTML = '';
 
    for (var i = 0; i < 7; i++) {
        var pip = document.getElementById('psPip' + i);
        if (pip) pip.classList.remove('on');
    }
 
    $('#internSelect').val(null).trigger('change');
    clearDropdowns();
}
 
function hardResetPips() {
    KEYS.forEach(function(k) {
        for (var i = 0; i < 10; i++) {
            var pip = document.getElementById('pip_' + k + '_' + i);
            if (pip) {
                pip.classList.remove('on-high');
                pip.classList.remove('on-mid');
                pip.classList.remove('on-low');
            }
        }
    });
}
</script>
</body>
</html>