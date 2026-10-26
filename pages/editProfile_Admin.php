<?php
session_start();
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    // header('location:login');
    // exit;
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}
if ($_SESSION['userRole'] !== 'admin' && $_SESSION['userRole'] !== 'hr') {
    echo "<script>
        alert('You cannot open this page. Please log in with the correct role.');
        window.location.href = 'login.php';
    </script>";
    exit();
}
include('../include/db_config.php');

$emp = null;
$isEdit = false;

if (isset($_GET['userId'])) {
    $userId = mysqli_real_escape_string($con, $_GET['userId']);
    $result = mysqli_query($con, "SELECT * FROM signup WHERE userId='$userId' LIMIT 1");
    if ($result && mysqli_num_rows($result) > 0) {
        $emp = mysqli_fetch_assoc($result);
        $isEdit = true;
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <title>Edit Profile</title>
    <?php include('../partials/_links.php') ?>
</head>

<body>
    <div class="container-scroller">
        <?php include('../partials/_navbar.php') ?>
        <div class="container-fluid page-body-wrapper">
            <?php include('../partials/_sidebar.php');
            include('../partials/_settings-panel.php'); ?>
            <div class="main-panel">
                <div class="content-wrapper">
                    <div class="card">
                        <div class="card-body">
                            <h4 class="card-title">Edit Intern</h4>



                            <form class="pt-3 py-2 px-4 px-sm-5 row" action="updateProfile_Admin.php" method="POST" enctype="multipart/form-data">

                                <!-- Hidden field to identify the intern -->
                                <?php if ($isEdit): ?>
                                    <input type="hidden" name="userId" value="<?= htmlspecialchars($emp['userId']) ?>">
                                <?php endif; ?>


                                <!--/* <form class="pt-3 py-2 px-4 px-sm-5 row" 
      action="<?= $isEdit ? 'update_db.php' : 'register_db.php' ?>" 
      method="POST" enctype="multipart/form-data">

     Hidden userId field for edit 
    <?php if ($isEdit): ?>
        <input type="hidden" name="userId" value="<?= $emp['userId'] ?>">
    <?php endif; ?> */-->

                                <div class="form-group col-md-12">
                                    <label>Full Name</label>
                                    <input type="text" class="form-control form-control-lg"
                                        name="fullName" required
                                        value="<?= $isEdit ? htmlspecialchars($emp['fullName']) : '' ?>">
                                </div>

                                <div class="form-group col-md-12">
                                    <label>Email</label>
                                    <input type="email" class="form-control form-control-lg"
                                        name="email" required
                                        value="<?= $isEdit ? htmlspecialchars($emp['email']) : '' ?>">
                                </div>

                                <div class="form-group col-md-12">
                                    <label>Office</label>
                                    <select class="form-control form-control-lg" name="office" required>
                                        <option value="" disabled <?= !$isEdit ? 'selected' : '' ?>>Select Office</option>
                                        <option value="pune" <?= $isEdit && $emp['office'] == 'pune' ? 'selected' : '' ?>>Pune</option>
                                        <option value="kinwat" <?= $isEdit && $emp['office'] == 'kinwat' ? 'selected' : '' ?>>Kinwat</option>
                                    </select>
                                </div>

                                <!-- Add same pattern for all other fields                     -->
                                <div class="form-group col-md-6">
                                    <label>Expected PunchIn</label>
                                    <input type="time" class="form-control form-control-lg"
                                        name="expectedPunchIn" required
                                        value="<?= $isEdit ? $emp['expectedPunchIn'] : '' ?>">
                                </div>

                                <div class="form-group col-md-6">
                                    <label>Expected PunchOut</label>
                                    <input type="time" class="form-control form-control-lg"
                                        name="expectedPunchOut" required
                                        value="<?= $isEdit ? $emp['expectedPunchOut'] : '' ?>">
                                </div>

                                <div class="form-group col-md-6">
                                    <label><?= $emp['employment_type'] == "Employee" ? "Employee" : "Intern" ;?> Id</label>
                                    <input type="text" class="form-control form-control-lg"
                                        name="internId" required
                                        value="<?= $isEdit ? $emp['employment_type'] == "Employee" ?  htmlspecialchars($emp['emp_id']) : htmlspecialchars($emp['internId']) : '' ?>">
                                </div>

                                <div class="form-group col-md-6">
                                    <label>Designation</label>
                                    <select class="form-control form-control-lg" name="designation" required>
                                        <option value="" disabled>Select Designation</option>
                                        <option value="seniorIntern" <?= $isEdit && $emp['designation'] == 'seniorIntern' ? 'selected' : '' ?>>Senior Intern</option>
                                        <option value="juniorIntern" <?= $isEdit && $emp['designation'] == 'juniorIntern' ? 'selected' : '' ?>>Junior Intern</option>
                                    </select>
                                </div>

                                <div class="form-group col-md-6">
                                    <label>Date Of Birth</label>
                                    <input type="date" class="form-control form-control-lg"
                                        name="dob" required
                                        value="<?= $isEdit ? $emp['dob'] : '' ?>">
                                </div>


                                <div class="form-group col-md-6">
                                    <label>Mobile No</label>
                                    <input type="text"
                                        class="form-control form-control-lg"
                                        name="mobileNo"
                                        id="mobileNo"
                                        placeholder="Enter Mobile No."
                                        required
                                        maxlength="10"
                                        pattern="^[6-9][0-9]{9}$"
                                        title="Please enter a valid 10-digit mobile number starting with 6, 7, 8, or 9"
                                        value="<?= isset($emp['mobileNo']) ? htmlspecialchars($emp['mobileNo']) : '' ?>"
                                        oninput="this.value = this.value.replace(/[^0-9]/g, '');">
                                </div>



                                <div class="form-group col-md-6">
                                    <label>Username</label>
                                    <input type="text" class="form-control form-control-lg"
                                        name="username" required
                                        value="<?= $isEdit ? htmlspecialchars($emp['username']) : '' ?>">
                                </div>

                                <div class="form-group col-md-6">
                                    <label>Password</label>
                                    <input type="password" class="form-control form-control-lg" value="<?= $emp['password'] ?>"
                                        name="password" <?= $isEdit ? '' : 'required' ?>>

                                </div>

                                <div class="form-group col-md-6">
                                    <label>Intern Type</label>
                                    <select class="form-control form-control-lg" name="type" required>
                                        <option value="" disabled>Select Intern Type</option>
                                        <option value="Offline" <?= $isEdit && $emp['type'] == 'Offline' ? 'selected' : '' ?>>Offline</option>
                                        <option value="Online" <?= $isEdit && $emp['type'] == 'Online'  ? 'selected' : '' ?>>Online</option>
                                    </select>
                                </div>

                                <div class="form-group col-md-6">
                                    <label>Enable Face Detection</label>
                                    <div class="form-check mt-3" style="padding-left:1.5rem;">
                                        <label class="form-check-label">
                                            <input type="checkbox" class="form-check-input" name="face_detection_enabled" value="1" <?= (!$isEdit || $emp['face_detection_enabled'] == 1) ? 'checked' : '' ?>>
                                            Enable Face Detection during Selfie Capture
                                        <i class="input-helper"></i></label>
                                    </div>
                                </div>
                                <div class="form-group col-md-6">
                                    <label>Profile Image</label>
                                    <!-- Hidden file input -->
                                    <input type="file" class="file-upload-default" name="profilePhoto" id="profilePhoto" accept="image/*" style="display:none;">

                                    <div class="input-groups">
                                        <input type="text" class="form-control file-upload-info" disabled placeholder="Upload Image">
                                        <span class="input-group-append">
                                            <button class="file-upload-browse btn btn-primary" type="button">Upload</button>
                                        </span>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <button type="submit" class="btn btn-block btn-primary font-weight-medium auth-form-btn"
                                        name="<?= $isEdit ? 'update' : 'signUp' ?>">
                                        <?= $isEdit ? 'Update' : 'Sign Up' ?>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script>
        document.querySelector(".file-upload-browse").addEventListener("click", function() {
            document.getElementById("profilePhoto").click();
        });

        document.getElementById("profilePhoto").addEventListener("change", function() {
            const fileName = this.value.split("\\").pop();
            document.querySelector(".file-upload-info").value = fileName;
        });
    </script>
    <?php
    require_once('../include/sweetAlert.php');
    if (isset($_SESSION['status'])) {
        toastMsg($_SESSION['status'] ? 'success' : 'error', $_SESSION['msg']);
        unset($_SESSION['status'], $_SESSION['msg']);
    }
    ?>
</body>

</html>