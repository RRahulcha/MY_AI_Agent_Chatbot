<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);

// Check if user is logged in
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    header("Location: login.php");
    exit;
}

// Role based access
if ($_SESSION['userRole'] !== 'senior_intern' && $_SESSION['userRole'] !== 'hr') {
    echo '<script>
        alert("You are not authorized to access this page.");
        window.location.href = "logout.php";
    </script>';
    die();
}

include('../include/db_config.php');

// Get user mode
$userId = $_SESSION['userId'];
$userQuery = mysqli_query($con, "SELECT type, expectedPunchOut FROM signup WHERE userId = '$userId'");
$userData = mysqli_fetch_assoc($userQuery);
$userMode = $userData['type'];  // "Online" or "Offline"
$expectedPunchOut = $userData['expectedPunchOut'];

// Handle key pending form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_key_pending'])) {
    $emp_id = $_SESSION['emp_id'];
    $emp_name = $_SESSION['emp_name'];
    $todayDate = date('Y-m-d');
    
    // Get early punch reason
    $earlyPunchReason = mysqli_real_escape_string($con, $_POST['earlyPunchReason'] ?? '');
    
    // If user selected key pending tasks
    if (isset($_POST['project']) && is_array($_POST['project'])) {
        $projects = $_POST['project'];
        $tasks = $_POST['task'];
        $reasons = $_POST['reason'];
        $deadlines = $_POST['deadline'];
        
        $count = count($projects);
        
        for ($i = 0; $i < $count; $i++) {
            // Skip if any required field is empty
            if (empty($projects[$i]) || empty($tasks[$i]) || empty($deadlines[$i])) {
                continue;
            }
            
            $projectId = $projects[$i];
            
            // Get project name
            $pRes = mysqli_query($con, "SELECT projectName FROM projectMaster WHERE projectId='$projectId'");
            $pRow = mysqli_fetch_assoc($pRes);
            $projectName = $pRow['projectName'] ?? 'Unknown Project';
            
            // Sanitize inputs
            $taskDesc = mysqli_real_escape_string($con, $tasks[$i]);
            $reason = mysqli_real_escape_string($con, $reasons[$i] ?? '');
            $deadline = mysqli_real_escape_string($con, $deadlines[$i]);
            
            mysqli_query($con, "
                INSERT INTO key_pendings
                (user_id, project_id, task_description, reason, deadline, punch_out_date, early_punch_reason)
                VALUES
                ('$userId', '$projectId', '$taskDesc', '$reason', '$deadline', '$todayDate', '$earlyPunchReason')
            ");
        }
        
        // Store early punch reason in session for punch out page
        $_SESSION['early_punch_reason'] = $earlyPunchReason;
        $_SESSION['key_pending_submitted'] = true;
        
        // Redirect to punch out process
        if ($userMode === 'Online') {
            header("Location: captureSelfie.php?punch=out");
        } else {
            header("Location: qrCodeScanner_copy.php?punch=Out");
        }
        exit();
    }
    // If user selected NO key pending (direct punch out)
    else if (isset($_POST['direct_punch_out'])) {
        // Store early punch reason in session for punch out page
        $_SESSION['early_punch_reason'] = $earlyPunchReason;
        
        // Redirect to punch out process
        if ($userMode === 'Online') {
            header("Location: captureSelfie.php?punch=out");
        } else {
            header("Location: qrCodeScanner_copy.php?punch=Out");
        }
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Punch Out Process</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body {
            background: #f5f5f5;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .punchout-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            width: 100%;
            max-width: 500px;
            border: 1px solid #eee;
        }

        .header-section {
            background: #1977e1;
            color: white;
            padding: 25px;
            text-align: center;
        }

        .header-section h2 {
            margin: 0;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            font-size: 22px;
        }

        .step-indicator {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-top: 15px;
        }

        .step {
            width: 25px;
            height: 25px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.3);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 12px;
        }

        .step.active {
            background: white;
            color: #1977e1;
        }

        .content-section {
            padding: 25px;
        }

        .heading {
            color: #1977e1 !important;
            font-weight: 600;
            margin-bottom: 15px;
            text-align: center;
        }

        .text-muted {
            color: #6c757d !important;
            font-size: 14px;
        }

        /* Key Pending Popup Styles */
        #keyPendingPopup {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.6);
            z-index: 9999;
            align-items: center;
            justify-content: center;
        }

        .modal-container {
            background: #fff;
            padding: 25px;
            border-radius: 10px;
            width: 500px;
            min-width: 400px;
            max-width: 800px;
            margin: 20px auto;
            text-align: left;
            max-height: 80vh;
            overflow-y: auto;
            position: relative;
            border: 1px solid #eee;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        #keyPendingPopup form {
            max-height: 70vh;
            overflow-y: auto;
            padding-right: 5px;
        }

        #keyPendingPopup form::-webkit-scrollbar {
            width: 6px;
        }

        #keyPendingPopup form::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }

        #keyPendingPopup form::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 10px;
        }

        #keyPendingPopup form::-webkit-scrollbar-thumb:hover {
            background: #555;
        }

        @media (max-width: 576px) {
            .modal-container {
                width: 90%;
                min-width: unset;
                max-width: 95%;
                max-height: 85vh;
                padding: 15px;
            }
        }

        .early-punch-reason {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #eee;
        }

        .early-punch-reason label {
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 8px;
            display: block;
            color: #333;
        }

        .early-punch-reason textarea {
            resize: vertical;
            min-height: 80px;
            border: 1px solid #ddd;
            border-radius: 6px;
            padding: 10px;
        }

        .early-punch-reason .note {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
            font-style: italic;
        }

        .form-control {
            border: 1px solid #ddd;
            border-radius: 6px;
            padding: 10px;
            font-size: 14px;
        }

        .form-control:focus {
            border-color: #1977e1;
            box-shadow: 0 0 0 0.2rem rgba(25, 119, 225, 0.25);
        }

        .form-label {
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 5px;
            color: #333;
        }

        .btn {
            border-radius: 6px;
            font-weight: 600;
            padding: 10px 20px;
            font-size: 14px;
        }

        .btn-success {
            background: #28a745;
            border-color: #28a745;
        }

        .btn-success:hover {
            background: #218838;
            border-color: #1e7e34;
        }

        .btn-primary {
            background: #1977e1;
            border-color: #1977e1;
        }

        .btn-primary:hover {
            background: #0d6efd;
            border-color: #0d6efd;
        }

        .btn-secondary {
            background: #6c757d;
            border-color: #6c757d;
        }

        .btn-secondary:hover {
            background: #5c636a;
            border-color: #565e64;
        }

        .btn-danger {
            background: #dc3545;
            border-color: #dc3545;
        }

        .btn-danger:hover {
            background: #bb2d3b;
            border-color: #b02a37;
        }

        .btn-outline-secondary {
            color: #6c757d;
            border-color: #6c757d;
        }

        .btn-outline-secondary:hover {
            background: #6c757d;
            border-color: #6c757d;
            color: white;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 13px;
        }

        .btn-custom {
            padding: 12px 25px;
            border-radius: 6px;
            font-weight: 600;
            transition: all 0.3s;
            font-size: 14px;
        }

        .btn-custom-success {
            background: #28a745;
            border: 1px solid #28a745;
            color: white;
        }

        .btn-custom-success:hover {
            background: #218838;
            border-color: #1e7e34;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(40, 167, 69, 0.2);
        }

        .btn-custom-secondary {
            background: #6c757d;
            border: 1px solid #6c757d;
            color: white;
        }

        .btn-custom-secondary:hover {
            background: #5c636a;
            border-color: #565e64;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(108, 117, 125, 0.2);
        }

        .btn-close {
            background: transparent;
            border: none;
            font-size: 1.5rem;
            opacity: 0.7;
        }

        .btn-close:hover {
            opacity: 1;
        }

        .text-end {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        .mb-3 {
            margin-bottom: 15px !important;
        }

        .mb-4 {
            margin-bottom: 20px !important;
        }

        .mt-2 {
            margin-top: 10px !important;
        }

        .mt-3 {
            margin-top: 15px !important;
        }

        .mt-4 {
            margin-top: 20px !important;
        }

        .d-flex {
            display: flex !important;
        }

        .justify-content-center {
            justify-content: center !important;
        }

        .gap-2 {
            gap: 10px;
        }

        .gap-3 {
            gap: 15px;
        }

        .position-absolute {
            position: absolute !important;
        }

        .top-0 {
            top: 0 !important;
        }

        .end-0 {
            right: 0 !important;
        }

        .m-3 {
            margin: 15px !important;
        }

        .small {
            font-size: 13px;
        }

        hr {
            margin: 20px 0;
            border: 0;
            border-top: 1px solid #eee;
        }

        optgroup {
            font-weight: 600;
            color: #333;
        }

        optgroup option {
            font-weight: normal;
            color: #555;
        }

        /* Mobile adjustments */
        @media (max-width: 480px) {
            .punchout-container {
                max-width: 95%;
            }
            
            .content-section {
                padding: 15px;
            }
            
            .header-section {
                padding: 15px;
            }
            
            .header-section h2 {
                font-size: 18px;
            }
            
            .btn-custom {
                padding: 10px 15px;
                font-size: 13px;
            }
        }
    </style>
</head>

<body>
    <div class="punchout-container">
        <div class="header-section">
            <h2><i class="bi bi-clock"></i> Punch Out Process</h2>
            <div class="step-indicator">
                <div class="step active">1</div>
                <div class="step">2</div>
                <div class="step">3</div>
            </div>
        </div>
        
        <div class="content-section">
            <h4 class="heading">📌 Key Pending Tasks</h4>
            <p class="text-center text-muted mb-4">Do you have any key pending tasks that need to be completed later?</p>
            
            <div class="d-flex justify-content-center gap-3">
                <button class="btn btn-custom btn-custom-success" onclick="openKeyPendingForm()">
                    <i class="bi bi-check-circle"></i> Yes, I Have
                </button>
                <button class="btn btn-custom btn-custom-secondary" onclick="directPunchOut()">
                    <i class="bi bi-x-circle"></i> No, Direct Punch Out
                </button>
            </div>
            
            <div class="mt-4 text-center">
                <a href="srEmpDashboard.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>
    </div>

    <!-- Key Pending Popup (For YES option) -->
    <div id="keyPendingPopup" style="display:none;">
        <div class="modal-container">
            <button type="button" class="btn-close position-absolute top-0 end-0 m-3" 
                    onclick="closeKeyPendingPopup()"></button>
            
            <form method="POST" action="addKeyPending_db.php" id="keyPendingForm">
                <input type="hidden" name="process_key_pending" value="1">
                <h5 class="heading" style="margin-bottom: 15px; text-align: center;">📌 Key Pending Tasks</h5>
                <p class="text-muted small mb-3">Please enter any pending tasks that need to be completed later.</p>

                <!-- FORM CONTAINER -->
                <div id="projectFormContainer">
                    <!-- FORM BLOCK (First one) -->
                    <div class="project-form-block">
                        <label class="form-label">Project</label>
                        <select name="project[]" class="form-control" required>
                            <option value="" selected disabled>-- Select Project --</option>
                            <optgroup label="Allocated Project">
                                <?php
                                $q = mysqli_query($con, "SELECT allocatedProject.projectId, projectMaster.projectName 
                                    FROM allocatedProject 
                                    LEFT JOIN projectMaster ON allocatedProject.projectId = projectMaster.projectId 
                                    WHERE userId = '$userId' AND projectMaster.status = 1");
                                while ($r = mysqli_fetch_assoc($q)) {
                                    echo "<option value='{$r['projectId']}'>{$r['projectName']}</option>";
                                }
                                ?>
                            </optgroup>
                            <optgroup label="Unallocated Project">
                                <?php
                                $q = mysqli_query($con, "SELECT projectId, projectName FROM projectMaster WHERE status = 1");
                                while ($r = mysqli_fetch_assoc($q)) {
                                    echo "<option value='{$r['projectId']}'>{$r['projectName']}</option>";
                                }
                                ?>
                            </optgroup>
                        </select>
                        <br>
                        <label class="form-label">Task Description</label>
                        <textarea class="form-control" name="task[]" rows="2" required maxlength="255"
                            placeholder="Enter task details..."></textarea>
                        <br>
                        <label class="form-label">Reason</label>
                        <textarea class="form-control" name="reason[]" rows="2" required maxlength="255"
                            placeholder="Enter reason for pending task..."></textarea>
                        <br>
                        <label class="form-label">Deadline</label>
                        <input type="datetime-local" class="form-control" name="deadline[]" required>
                        <br>

                        <!-- + BUTTON -->
                        <div class="text-end mb-3">
                            <button type="button" class="btn btn-primary btn-sm" onclick="addProjectForm()">
                                + Add Another Project
                            </button>
                        </div>

                        <!-- Remove button (hidden for first block) -->
                        <div class="text-end" style="display: none;">
                            <button type="button" class="btn btn-danger btn-sm remove-project-btn" 
                                    onclick="removeProjectForm(this)">
                                − Remove This Project
                            </button>
                        </div>
                        <hr>
                    </div>
                </div>
                
                <!-- Early Punch Out Reason Field -->
                <div class="early-punch-reason">
                    <label for="earlyPunchReason">Early Punch Out Reason *</label>
                    <textarea class="form-control" id="earlyPunchReason" name="earlyPunchReason" 
                        rows="3" required maxlength="500" 
                        placeholder="Please mention the reason for early punch out..."></textarea>
                    <div class="note">Required for both key pending and direct punch out</div>
                </div>

                <!-- SUBMIT -->
                <div class="text-center mt-4">
                    <button type="submit" class="btn btn-success btn-custom">
                        <i class="bi bi-save"></i> Save & Continue
                    </button>
                    <button type="button" class="btn btn-secondary btn-custom" onclick="closeKeyPendingPopup()">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Confirmation Popup for NO option -->
    <div id="directPunchOutPopup" style="display:none; position: fixed; top: 0; left: 0; width:100%; height:100%;
        background: rgba(0,0,0,0.6); z-index:9999; align-items:center; justify-content:center;">
        
        <div class="modal-container">
            <h5 class="heading" style="margin-bottom:15px;">⚠️ Early Punch Out Reason Required</h5>
            
            <!-- Early Punch Out Reason Field -->
            <div class="mb-3">
                <label class="form-label" style="text-align: left; display: block; font-size: 14px;">
                    Reason for Early Punch Out *
                </label>
                <textarea id="directPunchReason" class="form-control" 
                    rows="3" required maxlength="500"
                    placeholder="Please mention the reason for early punch out..."></textarea>
            </div>
            
            <div class="d-flex justify-content-center gap-2 mt-3">
                <form method="POST" action="" id="directPunchForm" style="display: inline;">
                    <input type="hidden" name="direct_punch_out" value="1">
                    <input type="hidden" name="earlyPunchReason" id="hiddenEarlyReason">
                    <button type="button" class="btn btn-success btn-sm" onclick="submitDirectPunchOut()">
                        Continue to Punch Out
                    </button>
                </form>
                <button class="btn btn-secondary btn-sm" onclick="closeDirectPunchOutPopup()">
                    Cancel
                </button>
            </div>
        </div>
    </div>

    <script>
        // Open key pending form
        function openKeyPendingForm() {
            document.getElementById("keyPendingPopup").style.display = "flex";
        }

        // Close key pending popup
        function closeKeyPendingPopup() {
            document.getElementById("keyPendingPopup").style.display = "none";
        }

        // Open direct punch out popup
        function directPunchOut() {
            document.getElementById("directPunchOutPopup").style.display = "flex";
        }

        // Close direct punch out popup
        function closeDirectPunchOutPopup() {
            document.getElementById("directPunchOutPopup").style.display = "none";
        }

        // Submit direct punch out (NO key pending)
        function submitDirectPunchOut() {
            const reason = document.getElementById("directPunchReason").value.trim();
            
            if (reason === "") {
                Swal.fire({
                    icon: 'warning',
                    title: 'Required',
                    text: 'Please enter reason for early punch out',
                    confirmButtonText: 'OK',
                    confirmButtonColor: '#28a745'
                });
                return;
            }
            
            // Set the hidden input value
            document.getElementById("hiddenEarlyReason").value = reason;
            
            // Submit the form
            document.getElementById("directPunchForm").submit();
        }

        // Add new project form block
        function addProjectForm() {
            const container = document.getElementById("projectFormContainer");
            const firstBlock = document.querySelector(".project-form-block");

            if (!firstBlock) return;

            const block = firstBlock.cloneNode(true);

            // Clear values in cloned block
            block.querySelectorAll("select, textarea, input").forEach(el => {
                el.value = "";
            });

            // Show remove button for cloned blocks
            const removeDiv = block.querySelector('.text-end');
            if (removeDiv) {
                removeDiv.style.display = 'block';
            }

            // Ensure remove button works
            const removeBtn = block.querySelector('.btn-danger');
            if (removeBtn) {
                removeBtn.onclick = function() {
                    removeProjectForm(this);
                };
            }

            container.appendChild(block);
        }

        // Remove project form block
        function removeProjectForm(btn) {
            const projectBlocks = document.querySelectorAll('.project-form-block');

            if (projectBlocks.length > 1) {
                btn.closest('.project-form-block').remove();
            } else {
                Swal.fire({
                    icon: 'warning',
                    title: 'Cannot Remove',
                    text: 'You must have at least one project entry.',
                    timer: 2000,
                    showConfirmButton: false,
                    confirmButtonColor: '#28a745'
                });
            }
        }

        // Validate key pending form
        document.getElementById('keyPendingForm')?.addEventListener('submit', function(e) {
            const earlyReason = document.getElementById('earlyPunchReason').value.trim();
            
            if (earlyReason === "") {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Required',
                    text: 'Please enter reason for early punch out',
                    confirmButtonText: 'OK',
                    confirmButtonColor: '#28a745'
                });
                return;
            }
            
            // Validate at least one project entry has all required fields
            const projectBlocks = document.querySelectorAll('.project-form-block');
            let hasValidEntry = false;
            
            projectBlocks.forEach(block => {
                const project = block.querySelector('select[name="project[]"]').value;
                const task = block.querySelector('textarea[name="task[]"]').value.trim();
                const deadline = block.querySelector('input[name="deadline[]"]').value;
                
                if (project && task && deadline) {
                    hasValidEntry = true;
                }
            });
            
            if (!hasValidEntry) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Incomplete Form',
                    text: 'Please fill all required fields for at least one project entry',
                    confirmButtonText: 'OK',
                    confirmButtonColor: '#28a745'
                });
            }
        });
    </script>
</body>
</html>