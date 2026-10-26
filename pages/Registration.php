<?php
//session_start();
include('../include/db_config.php'); 

$registration_message = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $firstName  = trim(mysqli_real_escape_string($con, $_POST['firstName'] ?? ''));
    $middleName = trim(mysqli_real_escape_string($con, $_POST['middleName'] ?? ''));
    $lastName   = trim(mysqli_real_escape_string($con, $_POST['lastName'] ?? ''));

    // Combine into full name
    $name = $firstName . (!empty($middleName) ? ' ' . $middleName : '') . ' ' . $lastName;

    $employment_type = mysqli_real_escape_string($con, $_POST['employment_type'] ?? '');
    $id_number       = mysqli_real_escape_string($con, $_POST['id_number'] ?? '');
    $branch          = mysqli_real_escape_string($con, $_POST['branch'] ?? '');
    $mode            = mysqli_real_escape_string($con, $_POST['mode'] ?? '');
    $username        = mysqli_real_escape_string($con, $_POST['username'] ?? '');
    $password_raw    = $_POST['password'] ?? '';
    $mobileNo        = mysqli_real_escape_string($con, $_POST['mobileNo'] ?? '');
    $dob             = mysqli_real_escape_string($con, $_POST['dob'] ?? '');
    $token           = mysqli_real_escape_string($con, $_POST['token'] ?? '');

    if (empty($firstName) || empty($lastName) || empty($employment_type) || ($employment_type !== 'Staff' && empty($id_number)) || empty($branch) || empty($mode) || empty($username) || empty($password_raw)) {
        $registration_message = "<div class='reg-alert reg-alert-error'><i class='fas fa-exclamation-circle'></i> Please fill all required fields.</div>";
    } elseif (!preg_match('/^[0-9]{10}$/', $mobileNo)) {
        $registration_message = "<div class='reg-alert reg-alert-error'><i class='fas fa-exclamation-circle'></i> Enter a valid 10-digit mobile number.</div>";
    } else {
        $idCondition = ($employment_type !== 'Staff' && !empty($id_number)) ? "(emp_id = '$id_number' || internId = '$id_number') || " : "";
        $sql = "SELECT * FROM signup WHERE {$idCondition} username = '$username' || mobileNo = '$mobileNo' || email = '$username'";
        $q   = mysqli_query($con, $sql);
        $row = mysqli_fetch_assoc($q);

        if ($row) {
            $emp_idR   = $row['emp_id'];
            $internIdR = $row['internId'];
            $usernameR = $row['username'];
            $mobileNoR = $row['mobileNo'];
            $emailR    = $row['email'];

            $message = "Record";
            if ($employment_type !== 'Staff' && !empty($emp_idR) && $emp_idR == $id_number)                           $message = "Employee ID";
            elseif ($employment_type !== 'Staff' && !empty($internIdR) && $internIdR == $id_number)                   $message = "Intern ID";
            elseif (!empty($mobileNoR) && $mobileNoR == $mobileNo)                                                   $message = "Mobile Number";
            elseif ((!empty($emailR) && $emailR == $username) || (!empty($usernameR) && $usernameR == $username))    $message = "Email";

            $registration_message = "<div class='reg-alert reg-alert-error'><i class='fas fa-exclamation-circle'></i> $message already exists!</div>";
        } else {
            $designation       = ($employment_type === 'Staff') ? "staff" : "juniorIntern";
            $department        = "Office";
            $userRole          = ($employment_type === 'Staff') ? "staff" : "junior_intern";
            $regstatus         = "Pending";
            $profilePhoto      = "default.png";
            $type              = $mode;
            $office            = $branch;
            $status            = "Active";
            $registration_date = date('Y-m-d H:i:s');
            $password          = $password_raw;

            $prefix = ($employment_type === 'Staff') ? "STF" : (($employment_type === 'Employee') ? "EMP" : "INT");

            // Pick the next number based on the highest EXISTING numeric suffix
            // for this prefix (NOT srNo / insertion order, which can be out of
            // sequence and was the cause of the duplicate-key fatal error).
            $result = mysqli_query($con,
                "SELECT userId FROM signup
                 WHERE userId LIKE '$prefix%'
                 ORDER BY CAST(SUBSTRING(userId, 4) AS UNSIGNED) DESC
                 LIMIT 1"
            );
            $row = mysqli_fetch_assoc($result);

            if ($row && !empty($row['userId'])) {
                $last_num = (int) filter_var($row['userId'], FILTER_SANITIZE_NUMBER_INT);
                $new_num  = $last_num + 1;
            } else {
                $new_num = 1;
            }

            $emp_id   = ($employment_type === "Employee") ? $id_number : "";
            $internId = ($employment_type === "Intern")   ? $id_number : "";

            // Retry with the next number if a duplicate userId is hit
            // (covers race conditions / numbering gaps).
            $inserted = false;
            $maxTries = 10;

            for ($attempt = 0; $attempt < $maxTries && !$inserted; $attempt++) {
                $new_userId = $prefix . str_pad($new_num, 3, "0", STR_PAD_LEFT);

                $sql = "INSERT INTO signup 
                (userId, fullName, employment_type, emp_id, internId, branch, mode, mobileNo, dob, email, username, password, registration_date, designation, department, userRole, regstatus, status, profilePhoto, type, office, token)
                VALUES 
                ('$new_userId', '$name', '$employment_type', '$emp_id', '$internId', '$branch', '$mode', '$mobileNo', '$dob', '$username', '$username', '$password', '$registration_date', '$designation', '$department', '$userRole', '$regstatus', '$status', '$profilePhoto', '$type', '$office', '$token')";

                try {
                    if (mysqli_query($con, $sql)) {
                        $inserted = true;
                    }
                } catch (mysqli_sql_exception $e) {
                    if (strpos($e->getMessage(), 'unique_userid') !== false) {
                        // userId collision -> bump number and retry
                        $new_num++;
                        continue;
                    }
                    // Any other DB error -> show it, stop retrying
                    $registration_message = "<div class='reg-alert reg-alert-error'><i class='fas fa-exclamation-circle'></i> Error: " . htmlspecialchars($e->getMessage()) . "</div>";
                    break;
                }
            }

            if ($inserted) {
                echo "<script>
                    setTimeout(function() {
                        Swal.fire({
                            icon: 'success',
                            title: 'Registration Successful!',
                            text: 'Your account is pending approval.',
                            confirmButtonColor: '#4B49AC'
                        }).then(() => { window.location.href = 'login.php'; });
                    }, 300);
                </script>";
            } elseif (empty($registration_message)) {
                $registration_message = "<div class='reg-alert reg-alert-error'><i class='fas fa-exclamation-circle'></i> Could not generate a unique account ID. Please try again.</div>";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SETTribe AMS – Registration</title>
    <?php include('../partials/_links.php'); ?>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body { background: #f4f5f7; min-height: 100vh; }

        /* ── Outer page wrapper ── */
        .reg-page {
            min-height: 100vh;
            display: flex;
            align-items: stretch;
            justify-content: center;
            padding: 32px 16px;
        }

        /* ── Main card — two columns ── */
        .reg-card {
            background: #fff;
            border-radius: 14px;
            border: 1px solid #e3e5ec;
            width: 100%;
            max-width: 1000px;
            display: flex;
            overflow: hidden;
            align-self: center;
        }

        /* ── Left: form panel ── */
        .reg-form-panel {
            flex: 1;
            padding: 40px 44px;
            overflow-y: auto;
        }

        /* ── Right: image panel ── */
.reg-image-panel {
    width: 420px;
    flex-shrink: 0;

    background-color: #356BF5;

    background-image: url("../images/Picture2.png");
    background-repeat: no-repeat;

    /* move image upward */
    background-position: center 90px;

    /* zoom slightly to hide white line */
    background-size: 82%;

    display: flex;
    flex-direction: column;
    justify-content: flex-start;
    align-items: center;

    padding: 70px 30px 40px;

    color: #fff;
    overflow: hidden;
}
        .reg-image-panel .panel-title {
            font-size: 22px;
            font-weight: 700;
            text-align: center;
            line-height: 1.35;
            margin-bottom: 12px;
            letter-spacing: -0.2px;
        }

        .reg-image-panel .panel-subtitle {
            font-size: 13.5px;
            text-align: center;
            opacity: 0.82;
            line-height: 1.6;
        }

        /* Hide image panel on small screens */
        @media (max-width: 820px) {
            .reg-image-panel { display: none; }
            .reg-form-panel  { padding: 32px 24px; }
        }

        @media (max-width: 480px) {
            .reg-form-panel { padding: 24px 16px; }
        }

        /* ── Logo ── */
        .reg-logo { margin-bottom: 22px; }
        .reg-logo img { height: 36px; }

        /* ── Heading ── */
        .reg-heading {
            margin-bottom: 24px;
            border-bottom: 1px solid #eef0f6;
            padding-bottom: 18px;
        }

        .reg-heading h2 {
            font-size: 16px;
            font-weight: 600;
            color: #2e3346;
            margin-bottom: 3px;
        }

        .reg-heading p {
            font-size: 12.5px;
            color: #8b92a5;
        }

        /* ── Section label ── */
        .form-section-label {
            font-size: 10.5px;
            font-weight: 600;
            letter-spacing: 0.7px;
            text-transform: uppercase;
            color: #8b92a5;
            margin: 20px 0 12px 0;
            padding-bottom: 7px;
            border-bottom: 1px solid #f0f1f7;
        }

        /* ── Form rows / cols ── */
        .form-row {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .form-col      { flex: 1; min-width: 140px; }
        .form-col-full { flex: 0 0 100%; }

        .form-group { margin-bottom: 14px; }

        .form-group label {
            display: block;
            font-size: 12.5px;
            font-weight: 500;
            color: #4a4f6a;
            margin-bottom: 5px;
        }

        .required-star { color: #e24b4a; margin-left: 2px; }

        /* ── Inputs ── */
        .form-control {
            width: 100%;
            height: 37px;
            padding: 0 11px;
            font-size: 13px;
            color: #2e3346;
            background: #fff;
            border: 1px solid #dde1ee;
            border-radius: 7px;
            transition: border-color 0.2s, box-shadow 0.2s;
            appearance: none;
            -webkit-appearance: none;
        }

        .form-control:focus {
            outline: none;
            border-color: #4B49AC;
            box-shadow: 0 0 0 3px rgba(75, 73, 172, 0.1);
        }

        .form-control::placeholder { color: #b5bac9; }

        select.form-control {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%238b92a5' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 11px center;
            padding-right: 30px;
            cursor: pointer;
        }

        /* ── Input hints ── */
        .input-hint      { font-size: 11px; color: #8b92a5; margin-top: 4px; }
        .input-hint.warn { color: #e24b4a; }

        /* ── Password toggle ── */
        .pw-wrap { position: relative; }
        .pw-wrap .form-control { padding-right: 36px; }
        .pw-toggle {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #8b92a5;
            font-size: 13px;
            background: none;
            border: none;
            padding: 0;
            line-height: 1;
        }
        .pw-toggle:hover { color: #4a4f6a; }

        /* ── Alert ── */
        .reg-alert {
            border-radius: 7px;
            padding: 10px 13px;
            font-size: 12.5px;
            font-weight: 500;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .reg-alert-error {
            background: #fff2f2;
            color: #c0392b;
            border: 1px solid #f5c6c6;
        }

        /* ── Submit button ── */
        .btn-reg {
            width: 100%;
            height: 41px;
            background: #4B49AC;
            color: #fff;
            font-size: 13.5px;
            font-weight: 600;
            border: none;
            border-radius: 7px;
            cursor: pointer;
            letter-spacing: 0.3px;
            transition: background 0.2s, transform 0.1s;
            margin-top: 6px;
        }

        .btn-reg:hover  { background: #3d3c96; }
        .btn-reg:active { transform: scale(0.99); }

        /* ── Footer link ── */
        .reg-footer {
            text-align: center;
            margin-top: 16px;
            font-size: 12.5px;
            color: #8b92a5;
        }

        .reg-footer a { color: #4B49AC; text-decoration: none; font-weight: 500; }
        .reg-footer a:hover { text-decoration: underline; }
    </style>
</head>
<body>

<div class="reg-page">
    <div class="reg-card">

        <!-- ── Left: Form Panel ── -->
        <div class="reg-form-panel">

            <div class="reg-logo">
                <img src="../images/logos/SETTribe-logo.png" alt="SETTribe">
            </div>

            <div class="reg-heading">
                <h2>Attendance Management System</h2>
                <p>Employee / Intern Registration</p>
            </div>

            <?php echo $registration_message; ?>

            <form method="POST" id="regForm" onsubmit="return validateForm();">

                <!-- Personal Details -->
                <div class="form-section-label">Personal Details</div>

                <div class="form-row">
                    <div class="form-col form-group">
                        <label>First Name <span class="required-star">*</span></label>
                        <input type="text" name="firstName" id="firstName" class="form-control"
                               placeholder="First name" required
                               value="<?php echo htmlspecialchars($_POST['firstName'] ?? ''); ?>">
                    </div>
                    <div class="form-col form-group">
                        <label>Middle Name <span class="required-star">*</span></label>
                        <input type="text" name="middleName" id="middleName" class="form-control"
                               placeholder="Middle name" required
                               value="<?php echo htmlspecialchars($_POST['middleName'] ?? ''); ?>">
                    </div>
                    <div class="form-col form-group">
                        <label>Last Name <span class="required-star">*</span></label>
                        <input type="text" name="lastName" id="lastName" class="form-control"
                               placeholder="Last name" required
                               value="<?php echo htmlspecialchars($_POST['lastName'] ?? ''); ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-col form-group">
                        <label>Mobile Number <span class="required-star">*</span></label>
                        <input type="tel" name="mobileNo" id="mobileNo" class="form-control"
                               placeholder="10-digit mobile number" required maxlength="10"
                               value="<?php echo htmlspecialchars($_POST['mobileNo'] ?? ''); ?>">
                    </div>
                    <div class="form-col form-group">
                        <label>Date of Birth <span class="required-star">*</span></label>
                        <input type="date" name="dob" id="dob" class="form-control"
                               value="<?php echo htmlspecialchars($_POST['dob'] ?? ''); ?>" required>
                    </div>
                </div>


                <div class="form-row">
                    <div class="form-col form-group">
                        <label>Employment Type <span class="required-star">*</span></label>
                        <select name="employment_type" id="employment_type" class="form-control" required>
                            <option value="">Select type</option>
                            <option value="Employee" <?php if (($_POST['employment_type'] ?? '') == 'Employee') echo 'selected'; ?>>Employee</option>
                            <option value="Intern"   <?php if (($_POST['employment_type'] ?? '') == 'Intern')   echo 'selected'; ?>>Intern</option>
                            <option value="Staff"    <?php if (($_POST['employment_type'] ?? '') == 'Staff')    echo 'selected'; ?>>Staff</option>
                        </select>
                    </div>
                    <div class="form-col form-group" id="id_col">
                        <label id="id_label">Employee / Intern ID <span class="required-star">*</span></label>
                        <input type="text" name="id_number" id="id_number" class="form-control"
                               placeholder="Enter your ID" required
                               value="<?php echo htmlspecialchars($_POST['id_number'] ?? ''); ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-col form-group">
                        <label>Branch <span class="required-star">*</span></label>
                        <select name="branch" id="branch" class="form-control" required>
                            <option value="">Select branch</option>
                            <option value="Pune"   <?php if (($_POST['branch'] ?? '') == 'Pune')   echo 'selected'; ?>>Pune</option>
                            <option value="Kinwat" <?php if (($_POST['branch'] ?? '') == 'Kinwat') echo 'selected'; ?>>Kinwat</option>
                        </select>
                    </div>
                    <div class="form-col form-group">
                        <label>Mode <span class="required-star">*</span></label>
                        <select name="mode" id="mode" class="form-control" required>
                            <option value="">Select mode</option>
                            <option value="Online"  <?php if (($_POST['mode'] ?? '') == 'Online')  echo 'selected'; ?>>Online</option>
                            <option value="Offline" <?php if (($_POST['mode'] ?? '') == 'Offline') echo 'selected'; ?>>Offline</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label>Email Address <span class="required-star">*</span></label>
                    <input type="email" name="username" id="username" class="form-control"
                           placeholder="Enter your email address" required
                           value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                    <div class="input-hint warn">Your email address will be used as your username.</div>
                </div>

                <div class="form-group">
                    <label>Password <span class="required-star">*</span></label>
                    <div class="pw-wrap">
                        <input type="password" name="password" id="password" class="form-control"
                               placeholder="Create a password" required>
                        <button type="button" class="pw-toggle" id="togglePassword" aria-label="Toggle password visibility">
                            <i class="fas fa-eye" id="eyeIcon"></i>
                        </button>
                    </div>
                </div>

                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token ?? ''); ?>">

                <button type="submit" class="btn-reg">Create Account</button>

            </form>

            <div class="reg-footer">
                Already registered? <a href="login.php">Log in</a>
            </div>

        </div>
        <!-- ── End Left Panel ── -->

        <!-- ── Right: Image Panel ── -->
        <div class="reg-image-panel">
           
        </div>
        <!-- ── End Right Panel ── -->

    </div>
</div>

<script>
    // DOB max = today
    document.getElementById('dob').setAttribute('max', new Date().toISOString().split('T')[0]);

    // Password toggle
    document.getElementById('togglePassword').addEventListener('click', function () {
        const pw   = document.getElementById('password');
        const icon = document.getElementById('eyeIcon');
        if (pw.type === 'password') {
            pw.type = 'text';
            icon.classList.replace('fa-eye', 'fa-eye-slash');
        } else {
            pw.type = 'password';
            icon.classList.replace('fa-eye-slash', 'fa-eye');
        }
    });

    // Mobile — digits only
    document.getElementById('mobileNo').addEventListener('input', function () {
        this.value = this.value.replace(/\D/g, '').slice(0, 10);
    });

    // Name fields — letters + spaces only
    ['firstName', 'middleName', 'lastName'].forEach(function (id) {
        document.getElementById(id).addEventListener('input', function () {
            this.value = this.value.replace(/[^a-zA-Z\s]/g, '');
        });
    });

    // Dynamic ID label & visibility based on employment type
    function updateIdField() {
        const type = document.getElementById('employment_type').value;
        const idCol = document.getElementById('id_col');
        const idInput = document.getElementById('id_number');
        const idLabel = document.getElementById('id_label');

        if (type === 'Staff') {
            if (idCol) idCol.style.display = 'none';
            if (idInput) {
                idInput.required = false;
                idInput.value = '';
            }
        } else {
            if (idCol) idCol.style.display = 'block';
            if (idInput) idInput.required = true;

            const map = { Employee: 'Employee ID', Intern: 'Intern ID' };
            if (idLabel) {
                idLabel.innerHTML = (map[type] || 'Employee / Intern ID') + ' <span class="required-star">*</span>';
            }
            if (idInput) {
                idInput.placeholder = map[type] ? 'Enter ' + map[type] : 'Enter your ID';
            }
        }
    }

    document.getElementById('employment_type').addEventListener('change', updateIdField);
    updateIdField(); // Run initial state check

    function validateForm() {
        const firstName = document.getElementById('firstName').value.trim();
        const lastName  = document.getElementById('lastName').value.trim();
        const middle    = document.getElementById('middleName').value.trim();
        const mobile    = document.getElementById('mobileNo').value.trim();

        if (!firstName) {
            Swal.fire({ icon: 'warning', title: 'Missing Field', text: 'Please enter your First Name.', confirmButtonColor: '#4B49AC' });
            document.getElementById('firstName').focus();
            return false;
        }
        if (!middle) {
            Swal.fire({ icon: 'warning', title: 'Missing Field', text: 'Please enter your Middle Name.', confirmButtonColor: '#4B49AC' });
            document.getElementById('middleName').focus();
            return false;
        }
        if (!lastName) {
            Swal.fire({ icon: 'warning', title: 'Missing Field', text: 'Please enter your Last Name.', confirmButtonColor: '#4B49AC' });
            document.getElementById('lastName').focus();
            return false;
        }
        if (!/^\d{10}$/.test(mobile)) {
            Swal.fire({ icon: 'error', title: 'Invalid Mobile Number', text: 'Please enter a valid 10-digit mobile number.', confirmButtonColor: '#4B49AC' });
            document.getElementById('mobileNo').focus();
            return false;
        }
        return true;
    }
</script>

<script src="../vendors/js/vendor.bundle.base.js"></script>
<script src="../js/off-canvas.js"></script>
<script src="../js/hoverable-collapse.js"></script>
<script src="../js/template.js"></script>
<script src="../js/settings.js"></script>
<script src="../js/todolist.js"></script>

</body>
</html>