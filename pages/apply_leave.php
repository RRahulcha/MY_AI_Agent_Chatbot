<?php
session_start();
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}

include('../include/db_config.php');

$userId = $_SESSION['userId'];
$today = date('Y-m-d');

// ── Punch-in check ──────────────────────────────────────────────────────────
$checkPunch = mysqli_query($con, "
    SELECT punchInTime FROM attendance
    WHERE  userId      = '$userId'
      AND  date        = '$today'
      AND  punchInTime IS NOT NULL
");
$hasPunchedIn = mysqli_num_rows($checkPunch) > 0;

// ── User info ────────────────────────────────────────────────────────────────
$get = mysqli_query($con, "SELECT type, designation, SL, CL, PL FROM signup WHERE userId = '$userId'");
$myGet = mysqli_fetch_assoc($get);

$isOfflineUser = (strtolower($myGet['type'] ?? '') === 'offline');

// ── Pre-count approved+pending RO days per month for this user ───────────────
$roUsedByMonth = [];
if ($isOfflineUser) {
    $roCountQ = mysqli_query($con, "
        SELECT from_date, to_date FROM leaves
        WHERE  userId     = '$userId'
          AND  leave_type = 'RO'
          AND  status    IN ('Approved', 'Pending')
    ");
    while ($rcRow = mysqli_fetch_assoc($roCountQ)) {
        $s = strtotime($rcRow['from_date']);
        $e = strtotime($rcRow['to_date']);
        for ($d = $s; $d <= $e; $d += 86400) {
            $mk = date('Y-m', $d);
            $roUsedByMonth[$mk] = ($roUsedByMonth[$mk] ?? 0) + 1;
        }
    }
}

// ── Pre-count all approved+pending leaves per month (excluding RO and Auto Leaves) ───────────
$leavesUsedByMonth = [];
$leaveCountQ = mysqli_query($con, "
    SELECT from_date, to_date, leave_type FROM leaves
    WHERE  userId     = '$userId'
      AND  leave_type != 'RO'
      AND  leave_name NOT LIKE '%Auto Leave%'
      AND  leave_type != 'Auto'
      AND  status    IN ('Approved', 'Pending')
");
while ($lcRow = mysqli_fetch_assoc($leaveCountQ)) {
    $s = strtotime($lcRow['from_date']);
    $e = strtotime($lcRow['to_date']);
    for ($d = $s; $d <= $e; $d += 86400) {
        if (date('N', $d) == 7)
            continue; // Skip Sundays
        $mk = date('Y-m', $d);
        $leavesUsedByMonth[$mk] = ($leavesUsedByMonth[$mk] ?? 0) + 1;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <title>Apply Leave</title>
    <?php include('../partials/_links.php'); ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link href="../vendors/select2/select2.min.css" rel="stylesheet" />
    <style>
        .btn-secondary {
            color: white;
        }

        #cancelBtn {
            display: none;
        }

        /* Fixed Select2 styling to match other form controls */
        .select2-container {
            width: 100% !important;
        }

        .select2-container .select2-selection--single {
            box-sizing: border-box;
            cursor: pointer;
            display: block;
            height: 2.875rem;
            user-select: none;
            border: 1px solid #CED4DA;
            border-radius: 2px;
            background-color: transparent;
            padding: 0.56rem 0.75rem;
            font-size: 0.875rem;
            line-height: 1;
        }

        .select2-container--default .select2-selection--single .select2-selection__rendered {
            color: #495057;
            line-height: 1.5;
            padding-left: 0;
            padding-right: 0;
        }

        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 2.675rem;
            right: 5px;
        }

        .select2-container--default.select2-container--focus .select2-selection--single,
        .select2-container--default.select2-container--open .select2-selection--single {
            border-color: #80bdff;
            outline: 0;
            box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, .25);
        }

        .select2-container--default .select2-selection--multiple .select2-selection__choice {
            font-size: 1rem;
        }

        .select2-dropdown {
            border: 1px solid #CED4DA;
            border-radius: 2px;
        }

        .select2-container--default .select2-results__option--highlighted[aria-selected] {
            background-color: #007bff;
        }

        .required {
            color: red;
        }

        .image-preview-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.8);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            cursor: pointer;
        }

        .image-preview-content {
            position: relative;
            max-width: 90%;
            max-height: 90%;
            background: transparent;
            cursor: default;
        }

        .image-preview-cancel-btn {
            position: absolute;
            top: -40px;
            right: -10px;
            background: #ff4444;
            color: white;
            border: none;
            border-radius: 50%;
            width: 35px;
            height: 35px;
            font-size: 18px;
            cursor: pointer;
            z-index: 10000;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background .3s;
        }

        .image-preview-cancel-btn:hover {
            background: #cc0000;
        }

        .image-preview-img {
            max-width: 100%;
            max-height: 80vh;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.5);
            display: block;
        }

        #roLimitWarning {
            display: none;
            color: #dc3545;
            margin-top: 8px;
            font-weight: 500;
        }

        #roSaturdayNotice {
            display: none;
            color: #6f42c1;
            margin-top: 6px;
            font-weight: 500;
        }

        /* Custom SweetAlert Styling */
        .swal-custom-popup {
            border-radius: 12px !important;
            padding: 25px !important;
        }

        .swal-custom-title {
            font-size: 22px !important;
            font-weight: 600 !important;
            color: #333 !important;
        }

        .swal-custom-button {
            padding: 10px 30px !important;
            font-size: 15px !important;
            font-weight: 500 !important;
            border-radius: 6px !important;
        }
    </style>
</head>

<body>
    <div class="container-scroller">
        <?php include('../partials/_navbar.php'); ?>
        <div class="container-fluid page-body-wrapper">
            <?php
            include('../partials/_sidebar.php');
            include('../partials/_settings-panel.php');
            ?>
            <div class="main-panel">
                <div class="content-wrapper">
                    <div class="row">
                        <div class="col-12 grid-margin stretch-card">
                            <div class="card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <h4 class="card-title mb-0" id="pageTitle">Apply Leave</h4>
                                        <span class="badge badge-outline-primary font-weight-bold p-2"
                                            style="font-size: 0.82rem;">
                                            <i class="fa-solid fa-calendar-check mr-1"></i> FY Policy (1 Aug – 31 Mar)
                                        </span>
                                    </div>

                                    <!-- ── Leave Entitlements Summary Widget ── -->
                                    <?php
                                    $clBal = (int) ($myGet['CL'] ?? 0);
                                    $slBal = (int) ($myGet['SL'] ?? 0);
                                    $plBal = (int) ($myGet['PL'] ?? 0);
                                    $totalPaidRemaining = $clBal + $slBal + $plBal;
                                    ?>
                                    <div class="row mb-4">
                                        <div class="col-md col-6 mb-2">
                                            <div class="p-3 rounded text-center"
                                                style="background:#EEF2FF; border:1px solid #C7D2FE;">
                                                <small class="text-muted font-weight-bold d-block">Casual Leave
                                                    (CL)</small>
                                                <h4 class="font-weight-bold text-primary mb-0 mt-1"><?= $clBal ?> <span
                                                        style="font-size:0.75rem; color:#6B7280;">/ 4 Days</span></h4>
                                            </div>
                                        </div>
                                        <div class="col-md col-6 mb-2">
                                            <div class="p-3 rounded text-center"
                                                style="background:#ECFDF5; border:1px solid #A7F3D0;">
                                                <small class="text-muted font-weight-bold d-block">Sick Leave
                                                    (SL)</small>
                                                <h4 class="font-weight-bold text-success mb-0 mt-1"><?= $slBal ?> <span
                                                        style="font-size:0.75rem; color:#6B7280;">/ 4 Days</span></h4>
                                            </div>
                                        </div>
                                        <div class="col-md col-6 mb-2">
                                            <div class="p-3 rounded text-center"
                                                style="background:#FEF3C7; border:1px solid #FDE68A;">
                                                <small class="text-muted font-weight-bold d-block">Privilege Leave
                                                    (PL/EL)</small>
                                                <h4 class="font-weight-bold text-warning mb-0 mt-1"><?= $plBal ?> <span
                                                        style="font-size:0.75rem; color:#6B7280;">/ 5 Days</span></h4>
                                            </div>
                                        </div>
                                        <div class="col-md col-6 mb-2">
                                            <div class="p-3 rounded text-center"
                                                style="background:#FEF2F2; border:1px solid #FECACA;">
                                                <small class="text-muted font-weight-bold d-block">Rostering Off (RO)</small>
                                                <?php
                                                $currentMonthROUsed = $roUsedByMonth[date('Y-m')] ?? 0;
                                                $roRemaining = max(0, 2 - $currentMonthROUsed);
                                                ?>
                                                <h4 class="font-weight-bold mb-0 mt-1" style="color:#DC2626;"><?= $roRemaining ?> <span
                                                        style="font-size:0.75rem; color:#6B7280;">/ 2 Days (Mth)</span></h4>
                                            </div>
                                        </div>
                                        <div class="col-md col-6 mb-2">
                                            <div class="p-3 rounded text-center"
                                                style="background:#F3E8FF; border:1px solid #DDD6FE;">
                                                <small class="text-muted font-weight-bold d-block">Total Paid
                                                    Remaining</small>
                                                <h4 class="font-weight-bold text-purple mb-0 mt-1"
                                                    style="color:#7C3AED;"><?= $totalPaidRemaining ?> <span
                                                        style="font-size:0.75rem; color:#6B7280;">/ 13 Days</span></h4>
                                            </div>
                                        </div>
                                    </div>

                                    <form class="forms-sample" action="leave_DB.php" method="post"
                                        enctype="multipart/form-data">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="row">

                                                    <div class="form-group col-md-12">
                                                        <label>Leave Type <span class="required">*</span></label>
                                                        <select class="form-control" name="leave_type" id="leaveType"
                                                            required>
                                                            <option value="">-- Select Leave Type --</option>
                                                            <option value="CL">Casual Leave (CL)</option>
                                                            <option value="SL">Sick Leave (SL)</option>
                                                            <option value="PL">Privilege / Earned Leave (PL/EL)</option>
                                                            <?php if ($isOfflineUser): ?>
                                                                <option value="RO">Rostering Off (RO)</option>
                                                            <?php endif; ?>
                                                        </select>
                                                        <p id="roLimitWarning">
                                                            ⚠ You have already used 2 Rostering Off days in the selected
                                                            month.
                                                            RO cannot be applied for that month.
                                                        </p>
                                                        <p id="roSaturdayNotice">
                                                            ℹ Rostering Off applies to Saturdays only and is
                                                            auto-approved.
                                                        </p>
                                                    </div>

                                                    <div class="col-md-12" id="interviewLeaveDiv" style="display:none;">
                                                        <div class="form-group">
                                                            <label>Interview Leave? <span
                                                                    class="required">*</span></label>
                                                            <div class="d-flex align-items-center mt-2">
                                                                <label class="d-flex align-items-center mr-4 mb-0"
                                                                    style="cursor:pointer;">
                                                                    <input type="radio" name="interview_leave"
                                                                        value="Yes" style="margin-right:6px;"> Yes
                                                                </label>
                                                                <label class="d-flex align-items-center mb-0"
                                                                    style="cursor:pointer;">
                                                                    <input type="radio" name="interview_leave"
                                                                        value="No" style="margin-right:6px;"> No
                                                                </label>
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <div class="col-md-6">
                                                        <div class="form-group">
                                                            <label>From Date <span class="required">*</span></label>
                                                            <input type="text" class="form-control datepicker"
                                                                name="from_date" id="from_date"
                                                                placeholder="Select From Date" autocomplete="off"
                                                                required>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <div class="form-group">
                                                            <label>To Date <span class="required">*</span></label>
                                                            <input type="text" class="form-control datepicker"
                                                                name="to_date" id="to_date" placeholder="Select To Date"
                                                                autocomplete="off" required>
                                                        </div>
                                                    </div>

                                                    <div class="col-md-12">
                                                        <div class="form-group">
                                                            <label>Reason <span class="required">*</span></label>
                                                            <textarea class="form-control" name="reason" rows="4"
                                                                required></textarea>
                                                        </div>
                                                    </div>

                                                    <div class="col-md-12" id="medicalDocDiv" style="display:none;">
                                                        <div class="form-group">
                                                            <label>Medical Document</label>
                                                            <div class="input-group">
                                                                <input type="file" name="leave_document"
                                                                    id="leaveDocument" class="form-control"
                                                                    accept=".pdf,.jpg,.jpeg,.png">
                                                                <div class="input-group-append">
                                                                    <a href="#" id="viewMedicalDoc"
                                                                        class="btn btn-primary"
                                                                        style="display:none;">Preview</a>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <!-- Approval From: hidden for RO -->
                                                    <div class="col-md-6" id="approvalFromDiv">
                                                        <div class="form-group">
                                                            <label>Approval From <span class="required">*</span></label>
                                                            <select id="approved_by"
                                                                class="form-control js-example-basic-single"
                                                                name="approved_by" required>
                                                                <option value="" selected disabled>Select</option>
                                                                <?php
                                                               if ($myGet['designation'] == 'seniorIntern') {
                                                                     $query = mysqli_query($con, "SELECT * FROM signup WHERE (designation = 'Admin' OR designation = 'HR' OR designation = 'resourceAllocator') AND status='Active' AND userId != '$userId'");
                                                               } else {
                                                                     $query = mysqli_query($con, "SELECT * FROM signup WHERE userId != '$userId' AND (designation = 'seniorIntern' OR designation = 'Admin' OR designation = 'HR' OR designation = 'resourceAllocator') AND status='Active'");
                                                               }
                                                                while ($fetch = mysqli_fetch_assoc($query)) {
                                                                    echo "<option value='{$fetch['userId']}'>{$fetch['fullName']}</option>";
                                                                }
                                                                ?>
                                                            </select>
                                                        </div>
                                                    </div>

                                                    <div class="col-md-6">
                                                        <div class="form-group">
                                                            <label>Task Handedover To <span
                                                                    class="required">*</span></label>
                                                            <select id="informed_colleagues"
                                                                class="form-control js-example-basic-single"
                                                                name="informed_colleagues" required>
                                                                <option value="" disabled selected>-- Select Name --
                                                                </option>
                                                                <option value="NA">NA</option>
                                                                <?php
                                                                $query = mysqli_query($con, "
                                                                SELECT userId, fullName FROM signup
                                                                WHERE  status      = 'active'
                                                                  AND  designation IN ('juniorIntern','seniorIntern','employee')
                                                                  AND  userId     != '$userId'
                                                                ORDER  BY fullName
                                                            ");
                                                                while ($fetch = mysqli_fetch_assoc($query)) {
                                                                    echo "<option value='{$fetch['userId']}'>{$fetch['fullName']}</option>";
                                                                }
                                                                ?>
                                                            </select>
                                                        </div>
                                                    </div>
                                                </div><!-- /.row -->

                                                <button type="submit" id="submitBtn" class="btn btn-primary mr-2"
                                                    name="apply">Apply</button>
                                                <p id="punchMsg" style="color:red;margin-top:10px;display:none;">
                                                    You have already PUNCHED IN today. Leave cannot be applied.
                                                </p>
                                            </div><!-- /.col-md-6 -->

                                            <div class="col-md-6">
                                                <center><img src="../images/apply_leave_art.png"
                                                        style="width:95%; max-width:480px; border-radius:12px;">
                                                </center>
                                            </div>
                                        </div><!-- /.row -->

                                        <a href="projectMaster" id="cancelBtn">
                                            <button type="button" class="btn btn-dark">Cancel</button>
                                        </a>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Scripts -->
        <script src="../vendors/js/vendor.bundle.base.js"></script>
        <script src="../vendors/select2/select2.min.js"></script>
        <script src="../vendors/typeahead.js/typeahead.bundle.min.js"></script>
        <script src="../js/off-canvas.js"></script>
        <script src="../js/hoverable-collapse.js"></script>
        <script src="../js/template.js"></script>
        <script src="../js/settings.js"></script>
        <script src="../js/select2.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

        <!-- ── PHP → JS: RO usage map, leaves usage map & offline flag ── -->
        <script>
            const roUsedByMonth = <?= json_encode($roUsedByMonth) ?>;
            const leavesUsedByMonth = <?= json_encode($leavesUsedByMonth) ?>;
            const isOfflineUser = <?= $isOfflineUser ? 'true' : 'false' ?>;
            const RO_LIMIT = 2;
            const MONTHLY_LEAVE_LIMIT = 2;

            function getROUsedInMonth(dateStr) {
                if (!dateStr) return 0;
                const mk = dateStr.substring(0, 7);
                return roUsedByMonth[mk] || 0;
            }

            function getLeavesUsedInMonth(dateStr) {
                if (!dateStr) return 0;
                const mk = dateStr.substring(0, 7);
                return leavesUsedByMonth[mk] || 0;
            }

            function daysInMonthFromRange(fromStr, toStr, targetMonth) {
                if (!fromStr || !toStr) return 0;
                let count = 0;
                const s = new Date(fromStr), e = new Date(toStr);
                for (let d = new Date(s); d <= e; d.setDate(d.getDate() + 1)) {
                    if (d.toISOString().substring(0, 7) === targetMonth && d.getUTCDay() !== 0) count++;
                }
                return count;
            }

            // ── Saturday enforcement for RO ──────────────────────────────────────
            function enforceROSaturday() {
                const fromInput = document.getElementById('from_date');
                const toInput = document.getElementById('to_date');
                const fromVal = fromInput.value;
                const toVal = toInput.value;

                if (fromVal) {
                    const d = new Date(fromVal);
                    fromInput.setCustomValidity(d.getDay() !== 6
                        ? 'Rostering Off can only be applied on a Saturday.' : '');
                }
                if (toVal) {
                    const d = new Date(toVal);
                    toInput.setCustomValidity(d.getDay() !== 6
                        ? 'Rostering Off can only be applied on a Saturday.' : '');
                }
            }

            // ── RO monthly cap check ─────────────────────────────────────────────
            function checkROLimit() {
                if (!isOfflineUser) return;

                const leaveType = document.getElementById('leaveType').value;
                const fromVal = document.getElementById('from_date').value;
                const toVal = document.getElementById('to_date').value;
                const warning = document.getElementById('roLimitWarning');
                const submitBtn = document.getElementById('submitBtn');

                if (leaveType !== 'RO' || !fromVal) {
                    warning.style.display = 'none';
                    submitBtn.disabled = false;
                    return;
                }

                const fromDate = new Date(fromVal);
                const toDate = toVal ? new Date(toVal) : fromDate;
                let blocked = false;

                const months = new Set();
                for (let d = new Date(fromDate); d <= toDate; d.setDate(d.getDate() + 1)) {
                    months.add(d.toISOString().substring(0, 7));
                }

                months.forEach(function (mk) {
                    const alreadyUsed = roUsedByMonth[mk] || 0;
                    const applyingInMk = daysInMonthFromRange(fromVal, toVal || fromVal, mk);
                    if (alreadyUsed + applyingInMk > RO_LIMIT) blocked = true;
                });

                if (blocked) {
                    warning.style.display = 'block';
                    submitBtn.disabled = true;
                } else {
                    warning.style.display = 'none';
                    submitBtn.disabled = false;
                }
            }

            document.getElementById('leaveType').addEventListener('change', checkROLimit);
            document.getElementById('from_date').addEventListener('change', checkROLimit);
            document.getElementById('to_date').addEventListener('change', checkROLimit);
            window.addEventListener('load', checkROLimit);
        </script>

        <!-- ── Punch-in block ── -->
        <script>
            const applyBtn = document.getElementById('submitBtn');
            const fromDateInput = document.getElementById('from_date');
            const punchMsg = document.getElementById('punchMsg');

            const todayDate = new Date();
            todayDate.setHours(0, 0, 0, 0);

            function checkPunchInForToday() {
                const selectedDate = new Date(fromDateInput.value);
                selectedDate.setHours(0, 0, 0, 0);
                <?php if ($myGet['designation'] != 'seniorIntern' && $hasPunchedIn): ?>
                    if (selectedDate.getTime() === todayDate.getTime()) {
                        applyBtn.disabled = true;
                        punchMsg.style.display = 'block';
                    } else {
                        punchMsg.style.display = 'none';
                        checkROLimit();
                    }
                <?php endif; ?>
            }

            fromDateInput.addEventListener('change', checkPunchInForToday);
            window.addEventListener('load', checkPunchInForToday);
        </script>

        <!-- ── Select2 init ── -->
        <script>
            $(document).ready(function () {

                flatpickr("#from_date", {
                    dateFormat: "Y-m-d",
                    minDate: "today",
                    allowInput: true,
                    disable: [
                        function (date) { return (date.getDay() === 0); }
                    ]
                });

                flatpickr("#to_date", {
                    dateFormat: "Y-m-d",
                    minDate: "today",
                    allowInput: true,
                    disable: [
                        function (date) { return (date.getDay() === 0); }
                    ]
                });

                $('#approved_by').select2({
                    width: '100%',
                    placeholder: 'Select',
                    allowClear: true,
                    minimumResultsForSearch: 0
                });

                $('#informed_colleagues').select2({
                    width: '100%',
                    placeholder: 'Select',
                    allowClear: true,
                    minimumResultsForSearch: 0
                });

            });
        </script>

        <!-- ── Leave-type toggles ── -->
        <script>
            function toggleLeaveExtras() {
                const leaveType = document.getElementById('leaveType').value;
                const medicalDiv = document.getElementById('medicalDocDiv');
                const medicalInput = document.getElementById('leaveDocument');
                const interviewDiv = document.getElementById('interviewLeaveDiv');
                const interviewRadios = document.querySelectorAll('input[name="interview_leave"]');
                const approvalDiv = document.getElementById('approvalFromDiv');
                const approvedBy = document.getElementById('approved_by');
                const fromInput = document.getElementById('from_date');
                const toInput = document.getElementById('to_date');
                const roNotice = document.getElementById('roSaturdayNotice');

                // Medical doc
                medicalDiv.style.display = (leaveType === 'SL') ? 'block' : 'none';
                if (leaveType !== 'SL') medicalInput.value = '';

                // Interview radio
                if (leaveType === 'CL') {
                    interviewDiv.style.display = 'block';
                    interviewRadios.forEach(r => r.required = true);
                } else {
                    interviewDiv.style.display = 'none';
                    interviewRadios.forEach(r => { r.required = false; r.checked = false; });
                }

                // RO: hide approval field, restrict to Saturdays
                if (leaveType === 'RO') {
                    approvalDiv.style.display = 'none';
                    approvedBy.required = false;
                    approvedBy.value = '';
                    roNotice.style.display = 'block';

                    fromInput.addEventListener('change', enforceROSaturday);
                    toInput.addEventListener('change', enforceROSaturday);
                    enforceROSaturday();
                } else {
                    approvalDiv.style.display = '';
                    approvedBy.required = true;
                    roNotice.style.display = 'none';

                    fromInput.removeEventListener('change', enforceROSaturday);
                    toInput.removeEventListener('change', enforceROSaturday);
                    fromInput.setCustomValidity('');
                    toInput.setCustomValidity('');
                }
            }

            document.getElementById('leaveType').addEventListener('change', toggleLeaveExtras);
            window.addEventListener('load', toggleLeaveExtras);
        </script>

        <!-- ── Monthly leave limit warning (NEW) ── -->
        <script>
            let monthlyWarningShown = false;

            function checkMonthlyLeaveLimit() {
                const leaveType = document.getElementById('leaveType').value;
                const fromVal = document.getElementById('from_date').value;
                const toVal = document.getElementById('to_date').value;

                // Skip for RO (Rostering Off) as it has its own limit logic
                if (!fromVal || !toVal || leaveType === 'RO' || leaveType === '') {
                    return;
                }

                const fromDate = new Date(fromVal);
                const toDate = new Date(toVal);

                // Get all unique months in the date range
                const months = new Set();
                for (let d = new Date(fromDate); d <= toDate; d.setDate(d.getDate() + 1)) {
                    months.add(d.toISOString().substring(0, 7));
                }

                // Check each month
                let exceedsLimit = false;
                let warningMessage = '';

                months.forEach(function (mk) {
                    const alreadyUsed = leavesUsedByMonth[mk] || 0;
                    const applyingInMk = daysInMonthFromRange(fromVal, toVal, mk);
                    const totalLeaves = alreadyUsed + applyingInMk;

                    if (totalLeaves > MONTHLY_LEAVE_LIMIT) {
                        exceedsLimit = true;
                        const monthName = new Date(mk + '-01').toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
                        warningMessage += `
                            <div style="background: #fff3cd; padding: 12px; border-radius: 6px; margin: 10px 0; border-left: 4px solid #ffc107;">
                                <p style="margin: 0; font-size: 15px; color: #333;">
                                    <strong style="color: #856404;">📅 ${monthName}</strong><br>
                                    <span style="display: inline-block; margin-top: 6px; color: #555;">
                                        You have already used <strong style="color: #d9534f;">${alreadyUsed}</strong> day(s).<br>
                                        Applying <strong style="color: #d9534f;">${applyingInMk}</strong> more will total <strong style="color: #d9534f;">${totalLeaves}</strong> days.
                                    </span>
                                </p>
                            </div>
                        `;
                    }
                });

                // Show warning only once per form interaction
                if (exceedsLimit && !monthlyWarningShown) {
                    monthlyWarningShown = true;
                    Swal.fire({
                        icon: 'warning',
                        title: 'Monthly Leave Limit Exceeded',
                        html: `
                            <div style="text-align: left;">
                                <p style="font-size: 15px; color: #555; margin-bottom: 15px;">
                                    You are exceeding the monthly limit of <strong style="color: #d9534f;">${MONTHLY_LEAVE_LIMIT}</strong> leaves per month.
                                </p>
                                ${warningMessage}
                                <div style="background: #e7f3ff; padding: 12px; border-radius: 6px; margin-top: 15px; border-left: 4px solid #2196F3;">
                                    <p style="margin: 0; font-size: 14px; color: #0c5460;">
                                        ℹ️ <strong>Note:</strong> You can still proceed with the application.
                                    </p>
                                </div>
                            </div>
                        `,
                        confirmButtonText: 'Okay, I Understand',
                        confirmButtonColor: '#3085d6',
                        width: '600px',
                        customClass: {
                            popup: 'swal-custom-popup',
                            title: 'swal-custom-title',
                            confirmButton: 'swal-custom-button'
                        }
                    });
                }
            }

            // Reset warning flag when dates or leave type changes
            document.getElementById('leaveType').addEventListener('change', function () {
                monthlyWarningShown = false;
            });
            document.getElementById('from_date').addEventListener('change', function () {
                monthlyWarningShown = false;
            });
            document.getElementById('to_date').addEventListener('change', function () {
                monthlyWarningShown = false;
                checkMonthlyLeaveLimit();
            });
        </script>

        <!-- ── Form-submit guard ── -->
        <script>
            document.querySelector('form').addEventListener('submit', function (e) {
                const leaveType = document.getElementById('leaveType').value;
                const fromValGlobal = document.getElementById('from_date').value;
                const toValGlobal = document.getElementById('to_date').value || fromValGlobal;

                if (fromValGlobal) {
                    const todayStr = new Date().toLocaleDateString('en-CA'); // Gets YYYY-MM-DD in local time
                    if (fromValGlobal < todayStr || toValGlobal < todayStr) {
                        e.preventDefault();
                        Swal.fire({ icon: 'error', title: 'Invalid Date', text: 'You cannot apply for leave on a past date or month.' });
                        return;
                    }
                    const getDayOfWeek = (dateStr) => {
                        const parts = dateStr.split('-');
                        return new Date(parts[0], parts[1] - 1, parts[2]).getDay();
                    };
                    if (getDayOfWeek(fromValGlobal) === 0 || getDayOfWeek(toValGlobal) === 0) {
                        e.preventDefault();
                        Swal.fire({ icon: 'error', title: 'Invalid Date', text: 'You cannot apply for leave on Sundays.' });
                        return;
                    }
                }

                // CL: interview_leave required
                if (leaveType === 'CL') {
                    if (!document.querySelector('input[name="interview_leave"]:checked')) {
                        e.preventDefault();
                        alert('Please select Interview Leave (Yes or No).');
                        return;
                    }
                }

                // RO: must be Saturday(s) only
                if (leaveType === 'RO') {
                    const fromVal = document.getElementById('from_date').value;
                    const toVal = document.getElementById('to_date').value || fromVal;

                    const s = new Date(fromVal), t = new Date(toVal);
                    for (let d = new Date(s); d <= t; d.setDate(d.getDate() + 1)) {
                        if (d.getDay() !== 6) {
                            e.preventDefault();
                            alert('Rostering Off can only be applied on Saturdays. Please select a Saturday.');
                            return;
                        }
                    }
                }

                // RO: offline intern monthly cap
                if (isOfflineUser && leaveType === 'RO') {
                    const fromVal = document.getElementById('from_date').value;
                    const toVal = document.getElementById('to_date').value || fromVal;

                    const months = new Set();
                    const s = new Date(fromVal), t = new Date(toVal);
                    for (let d = new Date(s); d <= t; d.setDate(d.getDate() + 1)) {
                        months.add(d.toISOString().substring(0, 7));
                    }

                    let blocked = false;
                    months.forEach(function (mk) {
                        const used = roUsedByMonth[mk] || 0;
                        const adding = daysInMonthFromRange(fromVal, toVal, mk);
                        if (used + adding > RO_LIMIT) blocked = true;
                    });

                    if (blocked) {
                        e.preventDefault();
                        alert('You have already used ' + RO_LIMIT + ' Rostering Off days in that month. Cannot apply more RO in the same month.');
                    }
                }
            });
        </script>

        <!-- ── Medical document preview ── -->
        <script>
            const fileInput = document.getElementById('leaveDocument');
            const viewBtn = document.getElementById('viewMedicalDoc');
            let tempPreview = null;

            fileInput.addEventListener('change', function () {
                if (tempPreview) {
                    URL.revokeObjectURL(tempPreview);
                    tempPreview = null;
                }

                if (this.files && this.files[0]) {
                    const file = this.files[0];

                    // Validate file type based on accept attribute
                    const acceptAttr = this.getAttribute('accept');
                    if (acceptAttr) {
                        const acceptedTypes = acceptAttr.split(',').map(type => type.trim().toLowerCase());
                        const fileName = file.name.toLowerCase();
                        const isValidType = acceptedTypes.some(type => fileName.endsWith(type));

                        if (!isValidType) {
                            Swal.fire({
                                icon: 'warning',
                                title: 'Invalid file type',
                                text: `Please upload a file with one of the following extensions: ${acceptAttr}`,
                                confirmButtonText: 'Okay'
                            });
                            this.value = '';
                            viewBtn.style.display = 'none';
                            return;
                        }
                    }

                    // Validate file size (max 5 MB)
                    if (file.size > 5 * 1024 * 1024) {
                        Swal.fire({
                            icon: 'warning',
                            title: 'File too large',
                            text: 'Please upload a document smaller than 5 MB.',
                            confirmButtonText: 'Okay'
                        });
                        this.value = '';
                        viewBtn.style.display = 'none';
                        return;
                    }

                    tempPreview = URL.createObjectURL(file);
                    viewBtn.style.display = 'inline-block';
                    viewBtn.onclick = function (e) {
                        e.preventDefault();
                        file.name.toLowerCase().endsWith('.pdf')
                            ? showPdfPreview(tempPreview)
                            : showImagePreview(tempPreview);
                    };
                } else {
                    viewBtn.style.display = 'none';
                }
            });

            function showImagePreview(url) {
                const overlay = document.createElement('div');
                overlay.className = 'image-preview-overlay';
                const content = document.createElement('div');
                content.className = 'image-preview-content';
                const cancelBtn = document.createElement('button');
                cancelBtn.className = 'image-preview-cancel-btn';
                cancelBtn.innerHTML = '✕';
                const img = document.createElement('img');
                img.src = url; img.className = 'image-preview-img';
                cancelBtn.onclick = () => document.body.removeChild(overlay);
                overlay.onclick = e => { if (e.target === overlay) document.body.removeChild(overlay); };
                content.appendChild(cancelBtn); content.appendChild(img);
                overlay.appendChild(content); document.body.appendChild(overlay);
            }

            function showPdfPreview(url) {
                const overlay = document.createElement('div');
                overlay.className = 'image-preview-overlay';
                const content = document.createElement('div');
                content.className = 'image-preview-content';
                const cancelBtn = document.createElement('button');
                cancelBtn.className = 'image-preview-cancel-btn';
                cancelBtn.innerHTML = '✕';
                const iframe = document.createElement('iframe');
                iframe.src = url; iframe.style.cssText = 'width:100%;height:80vh;border:none;';
                cancelBtn.onclick = () => document.body.removeChild(overlay);
                overlay.onclick = e => { if (e.target === overlay) document.body.removeChild(overlay); };
                content.appendChild(cancelBtn); content.appendChild(iframe);
                overlay.appendChild(content); document.body.appendChild(overlay);
            }
        </script>

        <?php
        require_once('../include/sweetAlert.php');
        if (isset($_SESSION['status'])) {
            $status = $_SESSION['status'];
            $msg = $_SESSION['msg'];
            toastMsg($status ? 'success' : 'error', $msg);
            unset($_SESSION['status'], $_SESSION['msg']);
        }
        ?>
    </div><!-- /.container-scroller -->
</body>

</html>