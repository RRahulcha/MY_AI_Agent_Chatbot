<?php
session_start();

if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href='login.php';</script>";
    exit;
}

include('../include/db_config.php');

// Restrict to HR only
$query = mysqli_query($con, "SELECT designation FROM signup WHERE userId = '{$_SESSION['userId']}'");
$user = mysqli_fetch_assoc($query);

if (!$user || $user['designation'] != 'hr') {
    echo "<script>alert('Unauthorized Access'); window.location.href='logout.php';</script>";
    exit;
}

// ── Fetch active senior interns for the "Interviewed By" dropdown ──────────
$internOptions = mysqli_query(
    $con,
    "SELECT userId, fullName
     FROM signup
     WHERE designation IN ('seniorIntern', 'resourceAllocator')
       AND status = 'active'
     ORDER BY fullName ASC" 
);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>Interaction Entry</title>
    <?php include('../partials/_links.php'); ?>
    <style>
        .required {
            color: red;
            font-weight: 600;
        }

        #referredByBox {
            transition: all 0.3s ease;
        }

        /* ── Force dark text in all form controls ── */
        .form-control,
        .form-control:focus,
        .form-control:hover,
        select.form-control,
        select.form-control option {
            color: #111 !important;
            opacity: 1 !important;
        }

        .form-control::placeholder {
            color: #999 !important;
            opacity: 1 !important;
        }

        select.form-control {
            -webkit-text-fill-color: #111;
        }

        /* ── Custom 12-hour time picker ── */
        .time-picker-wrap {
            display: flex;
            gap: 6px;
            align-items: center;
        }

        .time-picker-wrap select {
            flex: 1;
            color: #111 !important;
            -webkit-text-fill-color: #111;
        }

        .time-picker-wrap .ampm-select {
            flex: 0 0 80px;
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

                    <?php if (isset($_SESSION['msg'])): ?>
                        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
                        <?php unset($_SESSION['msg']);
                        unset($_SESSION['status']); ?>
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-12 grid-margin stretch-card">
                            <div class="card">
                                <div class="card-body">

                                    <h4 class="card-title">Candidate Interaction Entry</h4>

                                    <form class="forms-sample" method="POST" action="interaction_entry_process.php"
                                        id="interactionForm" onsubmit="return validateAndPrepare()">

                                        <!-- Hidden: combined full name sent to server -->
                                        <input type="hidden" name="applicant_name" id="applicant_name">

                                        <div class="row">

                                            <!-- First Name -->
                                            <div class="col-md-4">
                                                <div class="form-group">
                                                    <label>First Name <span class="required">*</span></label>
                                                    <input type="text" name="first_name" id="first_name"
                                                        class="form-control" placeholder="Enter first name" required>
                                                </div>
                                            </div>

                                            <!-- Middle Name -->
                                            <div class="col-md-4">
                                                <div class="form-group">
                                                    <label>Middle Name</label>
                                                    <input type="text" name="middle_name" id="middle_name"
                                                        class="form-control" placeholder="Enter middle name">
                                                </div>
                                            </div>

                                            <!-- Last Name -->
                                            <div class="col-md-4">
                                                <div class="form-group">
                                                    <label>Last Name <span class="required">*</span></label>
                                                    <input type="text" name="last_name" id="last_name"
                                                        class="form-control" placeholder="Enter last name" required>
                                                </div>
                                            </div>

                                            <!-- Email -->
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>Email ID <span class="required">*</span></label>
                                                    <input type="email" name="email" id="email" class="form-control"
                                                        placeholder="Enter email address" required>
                                                </div>
                                            </div>

                                            <!-- Mobile -->
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>Mobile Number <span class="required">*</span></label>
                                                    <input type="tel" name="mobile" id="mobile" class="form-control"
                                                        placeholder="10-digit mobile number" required maxlength="10"
                                                        pattern="[0-9]{10}"
                                                        oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0,10);">
                                                </div>
                                            </div>

                                            <!-- Application Date -->
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>Application Date <span class="required">*</span></label>
                                                    <input type="date" name="application_date" id="application_date"
                                                        class="form-control" value="<?= date('Y-m-d'); ?>" required>
                                                </div>
                                            </div>

                                            <!-- Interview Date -->
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>Interview Date</label>
                                                    <input type="date" name="interview_date" id="interview_date"
                                                        class="form-control" min="<?= date('Y-m-d'); ?>">
                                                </div>
                                            </div>

                                            <!-- Interview Time — 12-hour picker -->
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>Interview Time</label>
                                                    <input type="hidden" name="interview_time"
                                                        id="interview_time_hidden">
                                                    <div class="time-picker-wrap">
                                                        <select id="time_hour" class="form-control"
                                                            onchange="syncTime()">
                                                            <option value="">HH</option>
                                                            <?php for ($h = 1; $h <= 12; $h++): ?>
                                                                <option value="<?= str_pad($h, 2, '0', STR_PAD_LEFT) ?>">
                                                                    <?= str_pad($h, 2, '0', STR_PAD_LEFT) ?>
                                                                </option>
                                                            <?php endfor; ?>
                                                        </select>
                                                        <span style="font-weight:bold; font-size:18px;">:</span>
                                                        <select id="time_minute" class="form-control"
                                                            onchange="syncTime()">
                                                            <option value="">MM</option>
                                                            <?php for ($m = 0; $m < 60; $m += 5): ?>
                                                                <option value="<?= str_pad($m, 2, '0', STR_PAD_LEFT) ?>">
                                                                    <?= str_pad($m, 2, '0', STR_PAD_LEFT) ?>
                                                                </option>
                                                            <?php endfor; ?>
                                                        </select>
                                                        <select id="time_ampm" class="form-control ampm-select"
                                                            onchange="syncTime()">
                                                            <option value="AM">AM</option>
                                                            <option value="PM">PM</option>
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Interview Mode -->
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>Interview Mode</label>
                                                    <select name="interview_mode" id="interview_mode"
                                                        class="form-control">
                                                        <option value="">Select</option>
                                                        <option value="Online">Online</option>
                                                        <option value="Offline">Offline</option>
                                                    </select>
                                                </div>
                                            </div>

                                            <!-- Branch -->
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>Branch</label>
                                                    <select name="center" id="center" class="form-control">
                                                        <option value="">Select</option>
                                                        <option value="Pune">Pune</option>
                                                        <option value="Kinwat">Kinwat</option>
                                                    </select>
                                                </div>
                                            </div>

                                            <!-- Source -->
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>Source</label>
                                                    <select name="source" class="form-control" id="sourceSelect"
                                                        onchange="toggleReferral()">
                                                        <option value="">Select</option>
                                                        <option value="LinkedIn">LinkedIn</option>
                                                        <option value="Referral">Referral</option>
                                                        <option value="Indeed">Indeed</option>
                                                        <option value="Instagram">Instagram</option>
                                                        <option value="Facebook">Facebook</option>
                                                    </select>
                                                </div>
                                            </div>

                                            <!-- Referred By (shown only when Source = Referral) -->
                                            <div class="col-md-6" id="referredByBox" style="display:none;">
                                                <div class="form-group">
                                                    <label>Referred By <span class="required">*</span></label>
                                                    <input type="text" name="referred_by" id="referred_by"
                                                        class="form-control" placeholder="Enter referrer's full name">
                                                </div>
                                            </div>

                                            <!-- Resume Link -->
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>Resume Link</label>
                                                    <input type="url" name="resume_link" id="resume_link"
                                                        class="form-control" placeholder="https://drive.google.com/...">
                                                </div>
                                            </div>

                                            <!-- Interview Status -->
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>Interview Status</label>
                                                    <select name="interview_status" id="interview_status"
                                                        class="form-control">
                                                        <option value="Scheduled">Scheduled</option>
                                                    </select>
                                                </div>
                                            </div>

                                            <!-- ── Interviewed By (dynamic — senior interns from DB) ────────────── -->
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>Interviewed By</label>
                                                    <select name="interviewed_by" id="interviewed_by"
                                                        class="form-control">
                                                        <option value="">Select</option>
                                                        <?php
                                                        if ($internOptions && mysqli_num_rows($internOptions) > 0):
                                                            while ($intern = mysqli_fetch_assoc($internOptions)): ?>
                                                                <option
                                                                    value="<?= htmlspecialchars($intern['userId'], ENT_QUOTES, 'UTF-8') ?>">
                                                                    <?= htmlspecialchars($intern['fullName'], ENT_QUOTES, 'UTF-8') ?>
                                                                </option>
                                                                <?php
                                                            endwhile;
                                                        else: ?>
                                                            <option value="" disabled>No senior interns found</option>
                                                        <?php endif; ?>
                                                    </select>
                                                </div>
                                            </div>

                                        </div><!-- /.row -->

                                        <button type="submit" class="btn btn-primary mr-2">Submit</button>
                                        <button type="reset" class="btn btn-light"
                                            onclick="resetForm(); return false;">Reset</button>

                                    </form>

                                </div>
                            </div>
                        </div>
                    </div>

                </div><!-- /.content-wrapper -->
            </div><!-- /.main-panel -->
        </div><!-- /.page-body-wrapper -->
    </div><!-- /.container-scroller -->

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        /* ── Referral toggle ── */
        function toggleReferral() {
            const source = document.getElementById('sourceSelect').value;
            const box = document.getElementById('referredByBox');
            const input = document.getElementById('referred_by');

            if (source === 'Referral') {
                box.style.display = 'block';
                input.setAttribute('required', 'required');
            } else {
                box.style.display = 'none';
                input.removeAttribute('required');
                input.value = '';
            }
        }

        /* ── Sync 12-hr picker → hidden 24-hr field ── */
        function syncTime() {
            const hour = document.getElementById('time_hour').value;
            const minute = document.getElementById('time_minute').value;
            const ampm = document.getElementById('time_ampm').value;
            const hidden = document.getElementById('interview_time_hidden');

            if (!hour || !minute) { hidden.value = ''; return; }

            let h24 = parseInt(hour, 10);
            if (ampm === 'AM') {
                if (h24 === 12) h24 = 0;
            } else {
                if (h24 !== 12) h24 += 12;
            }
            hidden.value = String(h24).padStart(2, '0') + ':' + minute;
        }

        /* ── Combine name fields before submit ── */
        function combineNames() {
            const first = document.getElementById('first_name').value.trim();
            const middle = document.getElementById('middle_name').value.trim();
            const last = document.getElementById('last_name').value.trim();
            let fullName = first;
            if (middle) fullName += ' ' + middle;
            fullName += ' ' + last;
            document.getElementById('applicant_name').value = fullName;
        }

        /* ── Validate and prepare form before submit ── */
        function validateAndPrepare() {
            syncTime();
            combineNames();

            const firstName = document.getElementById('first_name').value.trim();
            const middleName = document.getElementById('middle_name').value.trim();
            const lastName = document.getElementById('last_name').value.trim();
            const email = document.getElementById('email').value.trim();
            const mobile = document.getElementById('mobile').value.trim();
            const appDate = document.getElementById('application_date').value;
            const source = document.getElementById('sourceSelect').value;
            const referredBy = document.getElementById('referred_by').value.trim();

            if (!firstName || !lastName) {
                Swal.fire({
                    icon: 'error', title: 'Missing Field',
                    text: 'Please enter First and Last name',
                    confirmButtonColor: '#4B49AC'
                });
                return false;
            }

            if (!email) {
                Swal.fire({
                    icon: 'error', title: 'Missing Field',
                    text: 'Please enter Email ID', confirmButtonColor: '#4B49AC'
                });
                return false;
            }

            const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailPattern.test(email)) {
                Swal.fire({
                    icon: 'error', title: 'Invalid Email',
                    text: 'Please enter a valid email address', confirmButtonColor: '#4B49AC'
                });
                return false;
            }

            if (!mobile) {
                Swal.fire({
                    icon: 'error', title: 'Missing Field',
                    text: 'Please enter Mobile Number', confirmButtonColor: '#4B49AC'
                });
                return false;
            }

            if (mobile.length !== 10 || !/^[0-9]{10}$/.test(mobile)) {
                Swal.fire({
                    icon: 'error', title: 'Invalid Mobile',
                    text: 'Please enter a valid 10-digit mobile number', confirmButtonColor: '#4B49AC'
                });
                return false;
            }

            if (!appDate) {
                Swal.fire({
                    icon: 'error', title: 'Missing Field',
                    text: 'Please select Application Date', confirmButtonColor: '#4B49AC'
                });
                return false;
            }

            if (source === 'Referral' && !referredBy) {
                Swal.fire({
                    icon: 'error', title: 'Missing Field',
                    text: 'Please enter Referred By name when Source is Referral',
                    confirmButtonColor: '#4B49AC'
                });
                return false;
            }

            return true;
        }

        /* ── Reset button ── */
        function resetForm() {
            document.getElementById('referredByBox').style.display = 'none';
            document.getElementById('referred_by').removeAttribute('required');
            document.getElementById('referred_by').value = '';
            document.getElementById('time_hour').value = '';
            document.getElementById('time_minute').value = '';
            document.getElementById('time_ampm').value = 'AM';
            document.getElementById('interview_time_hidden').value = '';
            document.getElementById('applicant_name').value = '';
            document.getElementById('interactionForm').reset();
            document.getElementById('application_date').value = '<?= date('Y-m-d'); ?>';
        }
    </script>

</body>

</html>