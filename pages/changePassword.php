<?php
session_start();
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}
include('../include/db_config.php');

// Get user details
$userId = $_SESSION['userId'];
$userQuery = $con->prepare("SELECT fullName, userRole, profilePhoto FROM signup WHERE userId = ?");
$userQuery->bind_param("s", $userId);
$userQuery->execute();
$userResult = $userQuery->get_result();
$userData = $userResult->fetch_assoc();
$userQuery->close();

if (!$userData) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>Change Password - Security Required</title>
    <?php include('../partials/_links.php'); ?>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.css">

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body, .container-scroller, .page-body-wrapper, .main-panel, .content-wrapper {
            background-color: #f5f6fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .content-wrapper { padding: 30px 20px; }

        .card {
            background: white;
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }

        .card-body { padding: 40px; }

        .card-title {
            color: #2f55ff;
            font-weight: 700;
            font-size: 24px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .card-subtitle {
            color: #666;
            font-size: 14px;
            margin-bottom: 30px;
            line-height: 1.6;
        }

        .change-pwd-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
            align-items: center;
        }

        .pwd-form-column {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .pwd-illustration-column {
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .pwd-illustration-column img {
            max-width: 100%;
            height: auto;
            max-height: 400px;
        }

        .user-info-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 10px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
            color: white;
        }

        .user-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid rgba(255, 255, 255, 0.3);
        }

        .user-details .name {
            font-weight: 600;
            font-size: 16px;
            margin: 0 0 4px 0;
        }

        .user-details .role {
            font-size: 13px;
            opacity: 0.9;
            text-transform: capitalize;
            margin: 0;
        }

        .alert-warning {
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
            color: #856404;
            border-radius: 8px;
            padding: 12px 15px;
            margin-bottom: 20px;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-warning i {
            font-size: 18px;
            color: #ffc107;
        }

        .form-group {
            margin-bottom: 0;
        }

        .form-group label {
            color: #333;
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 8px;
            display: block;
        }

        .required {
            color: #e74c3c;
        }

        .form-control {
            border: 1px solid #dfe1e6;
            border-radius: 6px;
            padding: 12px 14px;
            font-size: 14px;
            height: 44px;
            background-color: #f9f9f9;
            color: #333;
            transition: all 0.3s ease;
        }

        .form-control::placeholder {
            color: #999;
            font-size: 14px;
        }

        .form-control:focus {
            border-color: #2f55ff;
            background-color: #ffffff;
            box-shadow: 0 0 0 3px rgba(47,85,255,0.08);
            outline: none;
        }

        .input-group {
            position: relative;
        }

        .input-group .form-control {
            padding-right: 44px;
        }

        .eye-icon {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #999;
            font-size: 16px;
            z-index: 10;
            transition: color 0.3s ease;
        }

        .eye-icon:hover {
            color: #2f55ff;
        }

        .strength-meter-container {
            margin-top: 8px;
        }

        .strength-meter {
            height: 6px;
            background-color: #e9ecef;
            border-radius: 3px;
            overflow: hidden;
            margin-bottom: 6px;
        }

        .strength-bar {
            height: 100%;
            width: 0;
            border-radius: 3px;
            transition: width 0.3s ease, background-color 0.3s ease;
        }

        .strength-weak   { background-color: #e74c3c; width: 25%;  }
        .strength-fair   { background-color: #f39c12; width: 50%;  }
        .strength-good   { background-color: #3498db; width: 75%;  }
        .strength-strong { background-color: #27ae60; width: 100%; }

        .strength-text {
            font-size: 12px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .strength-text.weak   { color: #e74c3c; }
        .strength-text.fair   { color: #f39c12; }
        .strength-text.good   { color: #3498db; }
        .strength-text.strong { color: #27ae60; }

        .requirements-container {
            margin-top: 12px;
            padding: 12px;
            background-color: #f9f9f9;
            border-radius: 6px;
            font-size: 12px;
        }

        .requirement-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 4px 0;
            color: #999;
            transition: color 0.2s ease;
        }

        .requirement-item.met {
            color: #27ae60;
        }

        .requirement-icon {
            width: 16px;
            height: 16px;
            border-radius: 50%;
            border: 2px solid #ddd;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            transition: all 0.2s ease;
        }

        .requirement-item.met .requirement-icon {
            background-color: #27ae60;
            border-color: #27ae60;
            color: white;
        }

        .match-indicator {
            font-size: 12px;
            font-weight: 600;
            margin-top: 6px;
            display: none;
        }

        .match-indicator.show {
            display: block;
        }

        .match-indicator.match {
            color: #27ae60;
        }

        .match-indicator.no-match {
            color: #e74c3c;
        }

        .button-group {
            display: flex;
            gap: 12px;
            margin-top: 32px;
        }

        .btn {
            border-radius: 6px;
            font-weight: 600;
            font-size: 14px;
            padding: 12px 24px;
            height: 44px;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-primary {
            background-color: #2f55ff;
            color: white;
            flex: 1;
        }

        .btn-primary:hover:not(:disabled) {
            background-color: #0041c4;
            box-shadow: 0 4px 12px rgba(47,85,255,0.3);
            transform: translateY(-2px);
        }

        .btn-primary:disabled {
            background-color: #bdbdbd;
            cursor: not-allowed;
            opacity: 0.6;
        }

        .btn-primary.loading {
            opacity: 0.7;
            pointer-events: none;
        }

        .logout-link {
            text-align: center;
            margin-top: 20px;
            font-size: 13px;
        }

        .logout-link a {
            color: #2f55ff;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .logout-link a:hover {
            color: #0041c4;
            text-decoration: underline;
        }

        .forgot-pwd-section { text-align: right; margin-bottom: 12px; }
        .forgot-pwd-link {
            font-size: 12px; color: #2f55ff; text-decoration: none;
            font-weight: 600; transition: color 0.3s ease;
            display: inline-flex; align-items: center; gap: 4px;
        }
        .forgot-pwd-link:hover { color: #0041c4; text-decoration: underline; }

        .field-divider { height: 1px; background-color: #eee; margin: 12px 0; }

        .spinner-border-sm {
            width: 14px;
            height: 14px;
            border-width: 2px;
        }

        @media (max-width: 1024px) {
            .change-pwd-row {
                grid-template-columns: 1fr;
                gap: 30px;
            }
            .pwd-illustration-column {
                order: -1;
            }
            .card-body {
                padding: 35px;
            }
        }

        @media (max-width: 768px) {
            .card-body {
                padding: 25px;
            }
            .card-title {
                font-size: 20px;
            }
            .button-group {
                flex-direction: column;
            }
            .pwd-illustration-column img {
                max-height: 250px;
            }
        }

        /* ══════ PROFILE UPDATE CARD ══════ */
        .profile-card .card-body { padding: 28px 32px; }

        .profile-row {
            display: flex;
            gap: 28px;
            align-items: flex-start;
        }
        .profile-left  { flex: 1; min-width: 0; }
        .profile-right {
            width: 280px;
            flex-shrink: 0;
            position: sticky;
            top: 80px;
        }

        /* Current photo circle */
        .current-photo-wrap {
            text-align: center;
            margin-bottom: 14px;
        }
        .current-photo-wrap img {
            width: 90px; height: 90px;
            border-radius: 50%; object-fit: cover;
            border: 3px solid #dfe1e6;
            transition: border-color .3s;
        }
        .current-photo-wrap p {
            font-size: 11px; color: #999;
            margin: 0 0 6px; font-weight: 600;
        }

        /* Cropper container */
        .cropper-container-wrap {
            display: none;
            border: 1px solid #dfe1e6;
            border-radius: 10px;
            overflow: hidden;
            background: #fafbfc;
        }
        .cropper-container-wrap img {
            display: block;
            width: 100%;
            max-height: 240px;
        }

        /* Crop action buttons — override the global .btn */
        .crop-actions {
            display: none;
            justify-content: center;
            gap: 8px;
            margin-top: 10px;
        }
        .crop-actions .btn-crop,
        .crop-actions .btn-reset {
            height: auto !important;
            padding: 7px 16px !important;
            font-size: 12px !important;
            font-weight: 600;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all .2s;
            flex: none;
        }
        .crop-actions .btn-crop  { background: #27ae60; color: #fff; }
        .crop-actions .btn-crop:hover { background: #1e8e3a; }
        .crop-actions .btn-reset { background: #e74c3c; color: #fff; }
        .crop-actions .btn-reset:hover { background: #c0392b; }

        /* Cropped preview */
        .cropped-preview {
            display: none;
            text-align: center;
            margin-top: 12px;
        }
        .cropped-preview p {
            font-size: 11px; color: #27ae60;
            font-weight: 700; margin-bottom: 6px;
        }
        .cropped-preview img {
            width: 80px; height: 80px;
            border-radius: 50%; object-fit: cover;
            border: 3px solid #27ae60;
        }

        /* Photo guidelines */
        .photo-guidelines {
            margin-top: 8px; padding: 9px 12px;
            background: #f0f4fd; border-radius: 6px;
            border-left: 3px solid #2f55ff;
        }
        .photo-guidelines p {
            margin: 0; font-size: 11px; color: #555; line-height: 1.6;
        }

        /* Profile submit button — don't stretch full width */
        #profileSubmitBtn {
            flex: none;
            width: auto;
            padding: 10px 28px;
        }

        @media (max-width: 768px) {
            .profile-row  { flex-direction: column-reverse; gap: 16px; }
            .profile-right { width: 100%; position: static; }
            .profile-card .card-body { padding: 20px 18px; }
        }
    </style>
</head>

<body>
    <div class="container-scroller">
        <?php include('../partials/_navbar.php') ?>

        <div class="container-fluid page-body-wrapper">
            <?php
            include('../partials/_sidebar.php');
            include('../partials/_settings-panel.php');
            ?>

            <div class="main-panel">
                <div class="content-wrapper">

                    <!-- ═══════ PROFILE UPDATE CARD ═══════ -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="card profile-card">
                                <div class="card-body">
                                    <h4 class="card-title"><i class="fas fa-user-edit"></i> Update Profile</h4>
                                    <p style="color:#666;font-size:13px;margin-bottom:20px;">Update your name and profile photo</p>

                                    <form id="profileUpdateForm" action="updateProfile_db.php" method="POST" enctype="multipart/form-data">
                                        <input type="hidden" name="updateProfile" value="1">
                                        <input type="hidden" name="fullName" id="hiddenFullName">
                                        <input type="hidden" name="croppedImage" id="croppedImageData">

                                        <div class="profile-row">
                                            <!-- LEFT: Form fields -->
                                            <div class="profile-left">
                                                <div class="row">
                                                    <div class="col-md-4">
                                                        <div class="form-group mb-3">
                                                            <label>First Name <span class="required">*</span></label>
                                                            <input type="text" class="form-control" id="firstName" placeholder="First Name" required>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <div class="form-group mb-3">
                                                            <label>Middle Name <span class="required">*</span></label>
                                                            <input type="text" class="form-control" id="middleName" placeholder="Middle Name" required>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <div class="form-group mb-3">
                                                            <label>Last Name <span class="required">*</span></label>
                                                            <input type="text" class="form-control" id="lastName" placeholder="Last Name" required>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-4">
    <div class="form-group mb-3">
        <label>Mobile Number <span class="required">*</span></label>

<input type="text"
       class="form-control"
       id="mobileNo"
       name="mobileNo"
       placeholder="Enter Mobile Number"
       maxlength="10"
       pattern="[0-9]{10}"
       inputmode="numeric"
       required
       value="<?= htmlspecialchars($userData['mobileNo'] ?? '') ?>">

<small id="mobileError" style="color:#e74c3c; display:none;">
    Please enter a valid 10-digit mobile number
</small>
    </div>
</div>
                                                </div>
                                                <div class="form-group mb-3">
                                                    <label>Full Name (Auto-Generated)</label>
                                                    <input type="text" class="form-control" id="displayFullName" readonly
                                                           style="background:#eef2fc;font-weight:600;color:#2f55ff;"
                                                           value="<?= htmlspecialchars($userData['fullName'] ?? '') ?>">
                                                </div>
                                                <div class="form-group mb-2">
                                                    <label>Profile Photo <span class="required">*</span></label>
                                                    <input type="file" class="form-control" id="photoInput" name="profilePhoto"
                                                           accept="image/jpeg,image/jpg,image/png,image/webp" required
                                                           style="padding:8px 14px;height:auto;">
                                                    <div class="photo-guidelines">
                                                        <p>
                                                            <i class="fas fa-info-circle" style="color:#2f55ff;"></i>
                                                            <strong>Photo Guidelines:</strong> Image should be <strong>full-faced like a passport photo</strong>.
                                                            Image should be identical. Add your <strong>latest image</strong>.
                                                            <br>Formats: <strong>JPG, JPEG, PNG, WEBP</strong> | Max: <strong>5 MB</strong>
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- RIGHT: Photo preview & cropper -->
                                            <div class="profile-right">
                                                <div class="current-photo-wrap" id="currentPhotoWrap">
                                                    <p>Current Photo</p>
                                                    <img src="../profile-photos/<?= htmlspecialchars($userData['profilePhoto'] ?? 'default.png') ?>"
                                                         id="currentPhoto"
                                                         onerror="this.src='../profile-photos/default.png'">
                                                </div>
                                                <div class="cropper-container-wrap" id="cropperWrap">
                                                    <img id="cropperImage">
                                                </div>
                                                <div class="crop-actions" id="cropperActions">
                                                    <button type="button" class="btn-crop" onclick="cropAndPreview()">
                                                        <i class="fas fa-crop-alt"></i> Crop & Set
                                                    </button>
                                                    <button type="button" class="btn-reset" onclick="resetCropper()">
                                                        <i class="fas fa-undo"></i> Reset
                                                    </button>
                                                </div>
                                                <div class="cropped-preview" id="croppedPreview">
                                                    <p><i class="fas fa-check-circle"></i> Cropped Preview</p>
                                                    <img id="croppedImg">
                                                </div>
                                            </div>
                                        </div>

                                        <div style="margin-top:16px;">
                                            <button type="submit" id="profileSubmitBtn" class="btn btn-primary" disabled>
                                                <i class="fas fa-save"></i> Update Profile
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ═══════ PASSWORD CARD ═══════ -->
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title">
                                    Change Password
                                    </h4>
                                   

                                    <form id="changePasswordForm" action="changePassword_db.php" method="POST">
                                        <div class="change-pwd-row">

                                            <!-- LEFT COLUMN -->
                                            <div class="pwd-form-column">

                            <!-- Current Password -->
                            <div class="form-group">
                                <label for="currentPassword">
                                    Current Password <span class="required">*</span>
                                </label>
                                <div class="input-group">
                                    <input type="password" class="form-control"
                                           id="currentPassword" name="currentPassword"
                                           placeholder="Enter your current password" required>
                                    <i class="fas fa-eye eye-icon" id="toggleCurrent"
                                       onclick="togglePassword('currentPassword','toggleCurrent')"></i>
                                </div>
                            </div>

                            <!-- Forgot password link -->
                            <div class="forgot-pwd-section">
                                <a href="forgot_password.php" class="forgot-pwd-link">
                                    <i class="fas fa-question-circle"></i> Forgot password?
                                </a>
                            </div>

                            <div class="field-divider"></div>

                            <!-- New Password -->
                            <div class="form-group">
                                <label for="newPassword">
                                    New Password <span class="required">*</span>
                                </label>
                                <div class="input-group">
                                    <input type="password" class="form-control"
                                           id="newPassword" name="newPassword"
                                           placeholder="Enter new password" required>
                                    <i class="fas fa-eye eye-icon" id="toggleNew"
                                       onclick="togglePassword('newPassword','toggleNew')"></i>
                                </div>

                                <div class="strength-meter-container" id="strengthContainer" style="display:none;">
                                    <div class="strength-meter">
                                        <div class="strength-bar" id="strengthBar"></div>
                                    </div>
                                    <div class="strength-text" id="strengthText"></div>
                                </div>

                                <div class="requirements-container" id="requirementsContainer" style="display:none;">
                                    <div class="requirement-item" id="reqLength">
                                        <div class="requirement-icon">✓</div>
                                        <span>At least 8 characters</span>
                                    </div>
                                    <div class="requirement-item" id="reqUppercase">
                                        <div class="requirement-icon">✓</div>
                                        <span>One uppercase letter (A-Z)</span>
                                    </div>
                                    <div class="requirement-item" id="reqNumber">
                                        <div class="requirement-icon">✓</div>
                                        <span>One number (0-9)</span>
                                    </div>
                                    <div class="requirement-item" id="reqSpecial">
                                        <div class="requirement-icon">✓</div>
                                        <span>One special character (!@#$%^&*)</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Confirm Password -->
                            <div class="form-group">
                                <label for="confirmPassword">
                                    Confirm Password <span class="required">*</span>
                                </label>
                                <div class="input-group">
                                    <input type="password" class="form-control"
                                           id="confirmPassword" name="confirmPassword"
                                           placeholder="Confirm your password" required>
                                    <i class="fas fa-eye eye-icon" id="toggleConfirm"
                                       onclick="togglePassword('confirmPassword','toggleConfirm')"></i>
                                </div>
                                <div class="match-indicator" id="matchIndicator"></div>
                            </div>

                            <!-- Submit -->
                            <div class="button-group">
                                <button type="submit" id="submitBtn" class="btn btn-primary" disabled>
                                    <i class="fas fa-check"></i> Update Password
                                </button>
                            </div>

                        </div><!-- end form column -->

                        <!-- RIGHT ILLUSTRATION COLUMN -->
                        <div class="pwd-illustration-column">
                            <img src="../images/edit_profile.png" alt="Change Password"
                                 onerror="this.src='../images/Picture2.png'">
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

<script src="../vendors/js/vendor.bundle.base.js"></script>
<script src="../js/template.js"></script>
<script src="../js/settings.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.js"></script>

<script>
/* ── Profile Update Logic ── */
(function() {
    var cropper = null;
    var isCropped = false;

    // Auto-generate full name
    function updateFullName() {
        var f = document.getElementById('firstName').value.trim();
        var m = document.getElementById('middleName').value.trim();
        var l = document.getElementById('lastName').value.trim();
        var full = [f, m, l].filter(Boolean).join(' ');
        document.getElementById('displayFullName').value = full;
        document.getElementById('hiddenFullName').value = full;
        checkProfileForm();
    }

    document.getElementById('firstName').addEventListener('input', updateFullName);
    document.getElementById('middleName').addEventListener('input', updateFullName);
    document.getElementById('lastName').addEventListener('input', updateFullName);

    document.getElementById('mobileNo').addEventListener('input', function () {

    // Remove all non-numeric characters
    this.value = this.value.replace(/[^0-9]/g, '');

    // Allow only 10 digits
    if (this.value.length > 10) {
        this.value = this.value.slice(0, 10);
    }

    // Validation message
    const error = document.getElementById('mobileError');

    if (this.value.length > 0 && this.value.length < 10) {
        error.style.display = 'block';
    } else {
        error.style.display = 'none';
    }
});

    // Photo input change → init cropper
    document.getElementById('photoInput').addEventListener('change', function(e) {
        var file = e.target.files[0];
        if (!file) return;

        // Validate type
        var allowed = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
        if (allowed.indexOf(file.type) === -1) {
            Swal.fire({ icon:'error', title:'Invalid Format', text:'Only JPG, JPEG, PNG, WEBP images are allowed.', confirmButtonColor:'#2f55ff' });
            e.target.value = '';
            return;
        }

        // Validate size
        if (file.size > 5 * 1024 * 1024) {
            Swal.fire({ icon:'error', title:'File Too Large', text:'Image must be less than 5 MB.', confirmButtonColor:'#2f55ff' });
            e.target.value = '';
            return;
        }

        var reader = new FileReader();
        reader.onload = function(ev) {
            var img = document.getElementById('cropperImage');
            img.src = ev.target.result;
            document.getElementById('cropperWrap').style.display = 'block';
            document.getElementById('cropperActions').style.display = 'flex';
            document.getElementById('croppedPreview').style.display = 'none';
            isCropped = false;
            checkProfileForm();

            if (cropper) cropper.destroy();
            cropper = new Cropper(img, {
                aspectRatio: 1,
                viewMode: 1,
                dragMode: 'move',
                autoCropArea: 0.85,
                cropBoxResizable: true,
                cropBoxMovable: true,
                guides: true,
                center: true,
                highlight: true,
                background: true,
                responsive: true
            });
        };
        reader.readAsDataURL(file);
    });

    // Crop and preview
    window.cropAndPreview = function() {
        if (!cropper) return;
        var canvas = cropper.getCroppedCanvas({ width: 300, height: 300, imageSmoothingQuality: 'high' });
        var dataURL = canvas.toDataURL('image/jpeg', 0.9);
        document.getElementById('croppedImg').src = dataURL;
        document.getElementById('croppedPreview').style.display = 'block';
        document.getElementById('croppedImageData').value = dataURL;
        isCropped = true;
        checkProfileForm();
        Swal.fire({ icon:'success', title:'Image Cropped!', text:'Your profile photo has been adjusted.', timer:1500, showConfirmButton:false });
    };

    // Reset cropper
    window.resetCropper = function() {
        if (cropper) cropper.reset();
        document.getElementById('croppedPreview').style.display = 'none';
        document.getElementById('croppedImageData').value = '';
        isCropped = false;
        checkProfileForm();
    };

    // Check if form is valid
    function checkProfileForm() {
        var f = document.getElementById('firstName').value.trim();
        var m = document.getElementById('middleName').value.trim();
        var l = document.getElementById('lastName').value.trim();
        var hasFile = document.getElementById('photoInput').files.length > 0;
        var valid = f && m && l && hasFile && isCropped;
        document.getElementById('profileSubmitBtn').disabled = !valid;
    }

    // Form submit validation
    document.getElementById('profileUpdateForm').addEventListener('submit', function(e) {
        var f = document.getElementById('firstName').value.trim();
        var m = document.getElementById('middleName').value.trim();
        var l = document.getElementById('lastName').value.trim();
        if (!f || !m || !l) {
            e.preventDefault();
            Swal.fire({ icon:'error', title:'Required', text:'First Name, Middle Name and Last Name are required.', confirmButtonColor:'#2f55ff' });
            return;
        }
        if (!document.getElementById('photoInput').files.length) {
            e.preventDefault();
            Swal.fire({ icon:'error', title:'Photo Required', text:'Please upload a profile photo.', confirmButtonColor:'#2f55ff' });
            return;
        }
        if (!isCropped) {
            e.preventDefault();
            Swal.fire({ icon:'warning', title:'Crop Required', text:'Please crop your image before submitting.', confirmButtonColor:'#2f55ff' });
            return;
        }
    });
})();
</script>

    <script>
        /* ── Toggle password visibility ── */
        function togglePassword(fieldId, iconId) {
            const field = document.getElementById(fieldId);
            const icon  = document.getElementById(iconId);
            if (field.type === 'password') {
                field.type = 'text';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                field.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        }

        /* ── Password requirements ── */
        function validatePasswordRequirements() {
            const password        = document.getElementById('newPassword').value;
            const confirmPassword = document.getElementById('confirmPassword').value;

            const req = {
                length:    password.length >= 8,
                uppercase: /[A-Z]/.test(password),
                number:    /[0-9]/.test(password),
                special:   /[!@#$%^&*(),.?":{}|<>]/.test(password)
            };

            updateReq('reqLength',    req.length);
            updateReq('reqUppercase', req.uppercase);
            updateReq('reqNumber',    req.number);
            updateReq('reqSpecial',   req.special);

            const hasContent = password.length > 0;
            document.getElementById('strengthContainer').style.display    = hasContent ? 'block' : 'none';
            document.getElementById('requirementsContainer').style.display = hasContent ? 'block' : 'none';

            const strength = Object.values(req).filter(Boolean).length;
            updateStrength(strength, password.length);
            updateMatch(password, confirmPassword);

            const allValid = Object.values(req).every(Boolean) &&
                             password === confirmPassword && hasContent;
            document.getElementById('submitBtn').disabled = !allValid;
        }

        function updateReq(id, valid) {
            document.getElementById(id).classList.toggle('met', valid);
        }

        function updateStrength(strength, len) {
            const bar  = document.getElementById('strengthBar');
            const text = document.getElementById('strengthText');
            bar.className  = 'strength-bar';
            text.className = 'strength-text';
            if (!len) { text.textContent = ''; return; }
            const levels = [
                null,
                { cls: 'weak',   label: '<i class="fas fa-exclamation-circle"></i> Weak'   },
                { cls: 'fair',   label: '<i class="fas fa-info-circle"></i> Fair'           },
                { cls: 'good',   label: '<i class="fas fa-check-circle"></i> Good'          },
                { cls: 'strong', label: '<i class="fas fa-check-circle"></i> Strong'        },
            ];
            const l = levels[strength] || levels[1];
            bar.classList.add('strength-' + l.cls);
            text.classList.add(l.cls);
            text.innerHTML = l.label;
        }

        function updateMatch(password, confirm) {
            const el = document.getElementById('matchIndicator');
            if (!confirm) { el.classList.remove('show'); return; }
            el.classList.add('show');
            if (password === confirm) {
                el.className = 'match-indicator show match';
                el.innerHTML = '<i class="fas fa-check"></i> Passwords match';
            } else {
                el.className = 'match-indicator show no-match';
                el.innerHTML = '<i class="fas fa-times"></i> Passwords do not match';
            }
        }

        document.getElementById('newPassword').addEventListener('input', validatePasswordRequirements);
        document.getElementById('confirmPassword').addEventListener('input', validatePasswordRequirements);
        document.getElementById('currentPassword').addEventListener('input', validatePasswordRequirements);

        /* ── Form submission ── */
        document.getElementById('changePasswordForm').addEventListener('submit', function (e) {
            e.preventDefault();

            const currentPassword = document.getElementById('currentPassword').value;
            const newPassword     = document.getElementById('newPassword').value;
            const confirmPassword = document.getElementById('confirmPassword').value;
            

            if (!currentPassword || !newPassword || !confirmPassword) {
                Swal.fire({ icon: 'error', title: 'Validation Error', text: 'All fields are required', confirmButtonColor: '#2f55ff' });
                return;
            }
            if (newPassword !== confirmPassword) {
                Swal.fire({ icon: 'error', title: 'Password Mismatch', text: 'New password and confirmation do not match', confirmButtonColor: '#2f55ff' });
                return;
            }
            if (currentPassword === newPassword) {
                Swal.fire({ icon: 'warning', title: 'Same Password', text: 'New password must be different from current password', confirmButtonColor: '#2f55ff' });
                return;
            }

            const submitBtn = document.getElementById('submitBtn');
            submitBtn.classList.add('loading');
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm mr-2"></span> Updating...';

            // Submit form directly (non-AJAX)
            this.submit();
        });
    </script>

    <?php
    require_once('../include/sweetAlert.php');
    if (isset($_SESSION['status'])) {
        $status = $_SESSION['status'];
        $msg    = $_SESSION['msg'];
        if ($status != true) { ?>
            <script>Swal.fire({ icon: 'error', title: 'Error', text: '<?php echo addslashes($msg); ?>', confirmButtonColor: '#2f55ff' });</script>
        <?php } else { ?>
            <script>Swal.fire({ icon: 'success', title: 'Success', text: '<?php echo addslashes($msg); ?>', confirmButtonColor: '#2f55ff', timer: 2000, timerProgressBar: true });</script>
        <?php }
        unset($_SESSION['status'], $_SESSION['msg']);
    }
    ?>
</body>

</html>