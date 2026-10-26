<?php
session_start();
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}
if ($_SESSION['userRole'] !== 'admin') {
    echo "<script>alert('You cannot open this page. Please log in with the correct role.'); window.location.href = 'login.php';</script>";
    exit();
}
include('../include/db_config.php');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php include('../partials/_links.php') ?>
    <style>
        /* ── Force dark text in all form controls (matching interaction entry) ── */
        .form-control,
        .form-control:focus,
        .form-control:hover,
        select.form-control,
        select.form-control option {
            color: #111 !important;
            opacity: 1 !important;
        }
        .form-control::placeholder { color: #999 !important; }
        select.form-control { -webkit-text-fill-color: #111; }

        .required-star { color: #e24b4a; font-weight: 600; }

        /* Section label */
        .form-section-label {
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.7px;
            text-transform: uppercase;
            color: #8b92a5;
            margin: 22px 0 14px 0;
            padding-bottom: 8px;
            border-bottom: 1px solid #eef0f6;
        }

        .reg-form-wrap .form-group label {
            font-size: 13px;
            font-weight: 500;
            color: #4a4f6a;
            margin-bottom: 5px;
        }

        .reg-form-wrap .form-control {
            border-radius: 7px;
            border: 1px solid #dde1ee;
            font-size: 13.5px;
            height: 38px;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .reg-form-wrap .form-control:focus {
            border-color: #4B49AC;
            box-shadow: 0 0 0 3px rgba(75, 73, 172, 0.1);
        }

        .reg-form-wrap select.form-control {
            appearance: none;
            -webkit-appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%238b92a5' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            padding-right: 32px;
        }

        .btn-submit-reg {
            background: #4B49AC;
            color: #fff;
            border: none;
            border-radius: 7px;
            padding: 10px 28px;
            font-size: 13.5px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }

        .btn-submit-reg:hover { background: #3d3c96; }

        .btn-reset-reg {
            background: #f4f5f7;
            color: #4a4f6a;
            border: 1px solid #dde1ee;
            border-radius: 7px;
            padding: 10px 24px;
            font-size: 13.5px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.2s;
            margin-left: 10px;
        }

        .btn-reset-reg:hover { background: #eaebf0; }

        .card { border-radius: 10px; border: 1px solid #e3e5ec; box-shadow: none; }
        .card-title {
            font-size: 16px;
            font-weight: 600;
            color: #2e3346;
            margin-bottom: 4px;
        }

        .card-subtitle-hint {
            font-size: 13px;
            color: #8b92a5;
            margin-bottom: 0;
        }

        .card-title-wrap {
            border-bottom: 1px solid #eef0f6;
            padding-bottom: 16px;
            margin-bottom: 4px;
        }

        .input-hint {
            font-size: 11.5px;
            color: #8b92a5;
            margin-top: 4px;
        }
    </style>
</head>

<body>
<div class="container-scroller">
    <?php include('../partials/_navbar.php') ?>
    <div class="container-fluid page-body-wrapper">
        <?php include('../partials/_sidebar.php'); ?>

        <div class="main-panel">
            <div class="content-wrapper">

                <?php
                require_once('../include/sweetAlert.php');
                if (isset($_SESSION['status'])) {
                    $status = $_SESSION['status'];
                    $msg    = $_SESSION['msg'];
                    if (!$status) toastMsg('error', $msg);
                    else          toastMsg('success', $msg);
                    unset($_SESSION['status'], $_SESSION['msg']);
                }
                ?>

                <div class="row">
                    <div class="col-md-12 grid-margin stretch-card">
                        <div class="card">
                            <div class="card-body">

                                <div class="card-title-wrap">
                                    <p class="card-title">Employee / Intern Registration</p>
                                    <p class="card-subtitle-hint">Fill in the details below to register a new employee or intern.</p>
                                </div>

                                <div class="row">
                                    <div class="col-lg-8 reg-form-wrap">

                                        <form class="row" id="adminRegForm" action="register_db" method="POST"
                                              enctype="multipart/form-data" onsubmit="return adminValidate();">

                                            <!-- Hidden combined fullName for register_db -->
                                            <input type="hidden" name="fullName" id="fullName_hidden">

                                            

                                            <div class="col-md-4 form-group">
                                                <label>First Name <span class="required-star">*</span></label>
                                                <input type="text" class="form-control" id="firstName"
                                                       name="firstName" placeholder="First name" required>
                                            </div>
                                            <div class="col-md-4 form-group">
                                                <label>Middle Name  <span class="required-star">*</span></label>
                                                <input type="text" class="form-control" id="middleName"
                                                       name="middleName" placeholder="Middle name" required>
                                            </div>
                                            <div class="col-md-4 form-group">
                                                <label>Last Name <span class="required-star">*</span></label>
                                                <input type="text" class="form-control" id="lastName"
                                                       name="lastName" placeholder="Last name" required>
                                            </div>

                                            <div class="col-md-6 form-group">
                                                <label>Email <span class="required-star">*</span></label>
                                                <input type="email" class="form-control" id="email"
                                                       name="email" placeholder="Email address" required>
                                            </div>

                                            <div class="col-md-6 form-group">
                                                <label>Mobile No. <span class="required-star">*</span></label>
                                                <input type="text" class="form-control" name="mobNo" id="mobNo"
                                                       placeholder="10-digit mobile number" required
                                                       maxlength="10" pattern="\d{10}"
                                                       oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0,10);">
                                            </div>

                                            <div class="col-md-6 form-group">
                                                <label>Date of Birth <span class="required-star">*</span></label>
                                                <input type="date" class="form-control" id="dob"
                                                       name="dob" required>
                                            </div>

                                            
                                            <div class="col-md-6 form-group">
                                                <label>Intern ID <span class="required-star">*</span></label>
                                                <input type="text" class="form-control" id="internId"
                                                       name="internId" placeholder="Intern ID" required>
                                            </div>

                                            <div class="col-md-6 form-group">
                                                <label>Designation <span class="required-star">*</span></label>
                                                <select class="form-control" name="designation" required>
                                                    <option value="" disabled selected>Select designation</option>
                                                    <option value="seniorIntern">Senior Intern</option>
                                                    <option value="juniorIntern">Junior Intern</option>
                                                    <option value="staff">Staff</option>
                                                </select>
                                            </div>

                                            <div class="col-md-6 form-group">
                                                <label>Office <span class="required-star">*</span></label>
                                                <select class="form-control" name="office" required>
                                                    <option value="" disabled selected>Select office</option>
                                                    <option value="pune">Pune</option>
                                                    <option value="kinwat">Kinwat</option>
                                                </select>
                                            </div>

                                            <div class="col-md-6 form-group">
                                                <label>Intern Type <span class="required-star">*</span></label>
                                                <select class="form-control" name="type" required>
                                                    <option value="" disabled selected>Select type</option>
                                                    <option value="Offline">Offline</option>
                                                    <option value="Online">Online</option>
                                                </select>
                                            </div>

                                            <div class="col-md-6 form-group">
                                                <label>Expected Punch In <span class="required-star">*</span></label>
                                                <input type="time" class="form-control"
                                                       id="expectedPunchIn" name="expectedPunchIn" required>
                                            </div>

                                            <div class="col-md-6 form-group">
                                                <label>Expected Punch Out <span class="required-star">*</span></label>
                                                <input type="time" class="form-control"
                                                       id="expectedPunchOut" name="expectedPunchOut" required>
                                            </div>

                                           

                                            <div class="col-md-6 form-group">
                                                <label>Username <span class="required-star">*</span></label>
                                                <input type="text" class="form-control" id="username"
                                                       name="username" placeholder="Username" required>
                                            </div>

                                            <div class="col-md-6 form-group">
                                                <label>Password <span class="required-star">*</span></label>
                                                <input type="password" class="form-control" id="password"
                                                       name="password" placeholder="Password" required>
                                            </div>

                                            

                                            <div class="col-md-6 form-group">
                                                <label>Upload Photo</label>
                                                <input type="file" class="file-upload-default"
                                                       name="upload" id="imageUpload">
                                                <div class="input-group col-xs-12">
                                                    <input type="text" class="form-control file-upload-info"
                                                           disabled placeholder="Choose image (JPG / PNG)">
                                                    <span class="input-group-append">
                                                        <button class="file-upload-browse btn btn-primary" type="button">Browse</button>
                                                    </span>
                                                </div>
                                                <div class="input-hint">Accepted formats: JPG, JPEG, PNG</div>
                                            </div>

                                            <!-- Actions -->
                                            <div class="col-12" style="margin-top: 8px;">
                                                <button type="submit" class="btn-submit-reg" name="signUp" value="Sign Up">
                                                    Register
                                                </button>
                                                <button type="button" class="btn-reset-reg"
                                                        onclick="document.getElementById('adminRegForm').reset();">
                                                    Reset
                                                </button>
                                            </div>

                                        </form>

                                    </div>

                                    <div class="col-lg-4 d-none d-lg-flex align-items-center justify-content-center">
                                        <img src="../images/reg.png" class="w-100" style="max-width:280px; opacity:0.85;" alt="">
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

<script>
    // Name fields — letters and spaces only
    ['firstName', 'middleName', 'lastName'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('input', function () {
            this.value = this.value.replace(/[^a-zA-Z\s]/g, '');
        });
    });

    // Image upload validation
    document.getElementById('imageUpload').addEventListener('change', function () {
        var file = this.files[0];
        if (file) {
            var ext = file.name.split('.').pop().toLowerCase();
            if (!['jpg', 'jpeg', 'png'].includes(ext)) {
                alert('Please select a JPEG or PNG image file.');
                this.value = '';
            }
        }
    });

    function combineNames() {
        var first  = document.getElementById('firstName').value.trim();
        var middle = document.getElementById('middleName').value.trim();
        var last   = document.getElementById('lastName').value.trim();
        document.getElementById('fullName_hidden').value = first + (middle ? ' ' + middle : '') + ' ' + last;
    }

    function adminValidate() {
        var firstName = document.getElementById('firstName').value.trim();
        var lastName  = document.getElementById('lastName').value.trim();
        var mobile    = document.getElementById('mobNo').value.trim();

        if (!firstName) {
            Swal.fire({ icon: 'warning', title: 'Missing Field', text: 'Please enter the First Name.', confirmButtonColor: '#4B49AC' });
            document.getElementById('firstName').focus();
            return false;
        }
        if (!lastName) {
            Swal.fire({ icon: 'warning', title: 'Missing Field', text: 'Please enter the Last Name.', confirmButtonColor: '#4B49AC' });
            document.getElementById('lastName').focus();
            return false;
        }
        if (!/^\d{10}$/.test(mobile)) {
            Swal.fire({ icon: 'error', title: 'Invalid Mobile Number', text: 'Please enter a valid 10-digit mobile number.', confirmButtonColor: '#4B49AC' });
            document.getElementById('mobNo').focus();
            return false;
        }

        combineNames();
        return true;
    }
</script>

<script src="../vendors/js/vendor.bundle.base.js"></script>
<script src="../js/off-canvas.js"></script>
<script src="../js/hoverable-collapse.js"></script>
<script src="../js/template.js"></script>
<script src="../js/settings.js"></script>
<script src="../js/todolist.js"></script>
<script src="../js/dashboard.js?v=<?php echo time(); ?>"></script>
<script src="../js/Chart.roundedBarCharts.js"></script>
<script src="../js/file-upload.js"></script>

</body>
</html>