<?php
session_start();
if (!isset($_SESSION['userId']) && empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}
include('../include/db_config.php');
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>Task Entry</title>
    <?php include('../partials/_links.php') ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.4.1/css/responsive.bootstrap4.min.css">

    <style>
        .btn-secondary {
            color: white;
        }

        #cancelBtn {
            display: none;
        }

        .form-control {
            height: 2.3rem;
        }

        .required {
            color: red;
        }

        .select2-container .select2-selection--single {
            height: 37px !important;
            display: flex;
            align-items: center;
        }

        .card {
            border: none;
            border-radius: 12px;
            background: #fff;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);
            padding: 15px;
        }

        .table thead th {
            background-color: #103d9eff;
            color: #fff;
            font-weight: 600;
        }

        .table tbody tr:hover {
            background-color: #f1f5ff;
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
                                    <h4 class="card-title" id="pageTitle">Overtime</h4>

                                    <form class="forms-sample" id="taskForm" method="post">
                                        <div class="row">
                                            <!-- ✅ Task Type Dropdown -->
                                            <?php
                                            $q = mysqli_query($con, "SELECT expectedPunchOut FROM singup WHERE userId = '$_SESSION[userId]' ");
                                            $r = mysqli_fetch_assoc($q);

                                            $expectedPunchOut = $r['expectedPunchOut'];
                                            ?>
                                            <div class="col-md-4">
                                                <div class="form-group">
                                                    <label>Expected Punch Out Time <span
                                                            class="required">*</span></label>
                                                    <input type="time" name="expectedPunchOut" class="form-control"
                                                        min="<?= date("H:i", strtotime($expectedPunchOut . '+30 mins')) ?>"
                                                        required>

                                                </div>
                                            </div>

                                            <!-- ✅ Dynamic Task Dropdown / Textbox -->
                                            <div class="col-md-4" id="taskContainer" style="display:none;">
                                                <div class="form-group">
                                                    <label id="taskLabel">Reason of Overtime<span
                                                            class="required">*</span></label>
                                                    <textarea id="reasonInput" class="form-control"
                                                        name="overtimeReason" rows="2" required maxlength="255"
                                                        placeholder="Enter reason..."></textarea>

                                                    <label class="text-danger">Note: Overtime is allowed above
                                                        30 minutes of
                                                        expected punch out time only.</label>
                                                </div>
                                            </div>

                                            <!-- ✅ Select Project Dropdown -->
                                            <div class="col-md-4" id="projectContainer">
                                                <div class="form-group">
                                                    <label>Select Project <span class="required">*</span></label>
                                                    <select class="js-example-basic-single w-100" id="projectId"
                                                        name="projectId">
                                                        <option value="" selected disabled>-- Select Project --</option>
                                                        <optgroup label="Allocated Project">
                                                             <?php
                                                             $userId = $_SESSION['userId'];
                                                             $q = mysqli_query($con, "SELECT allocatedProject.projectId, projectMaster.projectName, projectMaster.client 
                                                             FROM allocatedProject 
                                                             LEFT JOIN projectMaster ON allocatedProject.projectId = projectMaster.projectId 
                                                             WHERE userId = '$userId' AND projectMaster.status = 1 ORDER BY projectMaster.projectName ASC");
                                                             while ($r = mysqli_fetch_assoc($q)) {
                                                                 $pName  = $r['projectName'];
                                                                 $client = trim($r['client'] ?? '');
                                                                 $label  = (!empty($client) && strtoupper($client) !== 'NA') ? "{$pName} - {$client}" : "{$pName}";
                                                                 echo "<option value='{$r['projectId']}'>" . htmlspecialchars($label) . "</option>";
                                                             }
                                                             ?>
                                                         </optgroup>
                                                         <optgroup label="Unallocated Project">
                                                             <?php
                                                             $q = mysqli_query($con, "SELECT projectId, projectName, client FROM projectMaster WHERE status = 1 ORDER BY projectName ASC");
                                                             while ($r = mysqli_fetch_assoc($q)) {
                                                                 $pName  = $r['projectName'];
                                                                 $client = trim($r['client'] ?? '');
                                                                 $label  = (!empty($client) && strtoupper($client) !== 'NA') ? "{$pName} - {$client}" : "{$pName}";
                                                                 echo "<option value='{$r['projectId']}'>" . htmlspecialchars($label) . "</option>";
                                                             }
                                                             ?>
                                                         </optgroup>
                                                    </select>
                                                </div>
                                            </div>

                                            <!-- ✅ Remark -->
                                            <div class="col-md-4">
                                                <div class="form-group">
                                                    <label>Remark <span class="required">*</span></label>
                                                    <textarea class="form-control" name="remark" id="remark" rows="1"
                                                        placeholder="Enter brief remark (optional)..."></textarea>
                                                </div>
                                            </div>
                                        </div>

                                        <button type="button" id="startBtn" class="btn btn-primary mr-2">Start</button>
                                        <a href="projectMaster" id="cancelBtn">
                                            <button type="button" class="btn btn-dark">Cancel</button>
                                        </a>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- ✅ Task Table -->
                        <div class="col-lg-12 grid-margin stretch-card">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title">Tasks</h4>
                                    <div class="table-responsive">
                                        <table class="table table-hover" id="taskEntryTable">
                                            <thead>
                                                <tr>
                                                    <th>Sr. No.</th>
                                                    <th>Work Type</th>
                                                    <th>Task</th>
                                                    <th>Project</th>
                                                    <th>Start Time</th>
                                                    <th>Man Hr.</th>
                                                    <th>End Time</th>
                                                    <th>Status</th>
                                                    <th>Action</th>
                                                </tr>
                                            </thead>
                                            <tbody id="taskTableBody"></tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Core JS -->
    <script src="../vendors/js/vendor.bundle.base.js"></script>

    <!-- Sidebar and template scripts -->
    <script src="../js/off-canvas.js"></script>
    <script src="../js/hoverable-collapse.js"></script>
    <script src="../js/template.js"></script>
    <script src="../js/settings.js"></script>
    <script src="../js/todolist.js"></script>

    <!-- Plugins -->
    <script src="../vendors/datatables.net/jquery.dataTables.js"></script>
    <script src="../vendors/datatables.net-bs4/dataTables.bootstrap4.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.4.1/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.4.1/js/responsive.bootstrap4.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <!-- Your Custom Script -->
    <script>
        $(document).ready(function () {

            // ✅ Initialize Select2
            $('.js-example-basic-single').select2({
                placeholder: "-- Select Option --",
                allowClear: true,
                width: '100%'
            });

            // ✅ Load table data
            loadTasks();

            function loadTasks() {
                $.ajax({
                    url: "../ajax/fetch_taskentrynew.php",
                    type: "GET",
                    success: function (data) {
                        if ($.fn.DataTable.isDataTable('#taskEntryTable')) {
                            $('#taskEntryTable').DataTable().destroy();
                        }
                        $("#taskTableBody").html(data);
                        $('#taskEntryTable').DataTable({
                            responsive: true,
                            "order": [],
                            "language": {
                                "emptyTable": "No tasks found"
                            }
                        });
                    },
                    error: function (err) {
                        console.log("Error loading tasks:", err.responseText);
                    }
                });
            }

            // ✅ Task type change logic
            $("#taskType").change(function () {
                let taskType = $(this).val();

                // Hide everything by default
                $("#taskContainer").hide();
                $("#taskTextbox").hide();
                $("#taskDropdown").hide();
                $("#projectContainer").hide();

                // --- CASE 1: BREAK TYPES ---
                if (taskType === "Lunch Break" || taskType === "Break") {
                    return; // nothing extra to show
                }

                // --- CASE 2: STANDUP / SCRUM (ONLY REMARK VISIBLE) ---
                if (taskType === "Standup Meeting" || taskType === "Scrum Call") {
                    // remark will remain visible automatically
                    return;
                }

                // --- CASE 3: OTHER TYPE -> textbox ---
                if (taskType === "Other") {
                    $("#taskContainer").show();
                    $("#taskLabel").text("Task");
                    $("#taskTextbox").show();

                    if ($("#taskDropdown").hasClass("select2-hidden-accessible")) {
                        $("#taskDropdown").select2('destroy');
                    }
                    $("#taskDropdown").hide();
                    return;
                }

                // --- CASE 4: PROJECT WORK → dropdown + project select ---
                if (taskType === "Project Work") {
                    $("#taskContainer").show();
                    $("#taskDropdown").show();
                    $("#taskLabel").text("Select Task");
                    $("#projectContainer").show();

                    if (!$("#taskDropdown").hasClass("select2-hidden-accessible")) {
                        $("#taskDropdown").select2({ width: "100%" });
                    }

                    fetchTasks(taskType);
                    return;
                }

                // --- CASE 5: Other normal categories (Student Training etc.) ---
                $("#taskContainer").show();
                $("#taskDropdown").show();
                $("#taskLabel").text("Select Task");

                if (!$("#taskDropdown").hasClass("select2-hidden-accessible")) {
                    $("#taskDropdown").select2({ width: "100%" });
                }

                fetchTasks(taskType);
            });



            function fetchTasks(type) {
                $.ajax({
                    url: "../ajax/fetch_tasks.php",
                    type: "POST",
                    data: { category: type },
                    success: function (data) {
                        $("#taskDropdown").html(data);
                    }
                });
            }

            // ✅ Start task with SweetAlert validation
            $("#startBtn").click(function () {
                let workType = $("#taskType").val();
                let task = $("#taskDropdown").val() || $("#taskTextbox").val();
                let remark = $("#remark").val();
                let projectId = $("#projectId").val();

                // For Standup Meeting / Scrum Call → task = workType
                if (workType === "Standup Meeting" || workType === "Scrum Call") {
                    task = workType;
                }


                // ✅ Skip validation for Breaks
                if (workType === "Lunch Break" || workType === "Break") {
                    task = workType;
                } else {
                    if (!workType || !task) {
                        Swal.fire({
                            icon: 'warning',
                            title: 'Missing Fields',
                            text: 'Please fill all required fields.'
                        });
                        return;
                    }
                    if (workType === "Project Work" && !projectId) {
                        Swal.fire({
                            icon: 'warning',
                            title: 'Project Required',
                            text: 'Please select a project for Project Work.'
                        });
                        return;
                    }
                }

                $.ajax({
                    url: "../ajax/task_actions.php",
                    type: "POST",
                    dataType: "json",
                    data: {
                        action: "start",
                        workType: workType,
                        task: task,
                        remark: remark,
                        projectId: projectId
                    },
                    success: function (res) {
                        if (res.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Success',
                                text: res.message,
                                timer: 1500,
                                showConfirmButton: false
                            });
                            $("#taskForm")[0].reset();
                            $('.js-example-basic-single').val(null).trigger('change');
                            $("#taskContainer, #projectContainer").hide();
                            loadTasks();
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: res.message
                            });
                        }
                    },
                    error: function (xhr) {
                        console.log("Error starting task:", xhr.responseText);
                    }
                });
            });

            // ✅ Update status with SweetAlert
            window.updateStatus = function (id, status) {
                $.ajax({
                    url: "../ajax/task_actions.php",
                    type: "POST",
                    dataType: "json",
                    data: { action: "updateStatus", id: id, status: status },
                    success: function (res) {
                        if (res.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Updated',
                                text: res.message,
                                timer: 1200,
                                showConfirmButton: false
                            });
                            loadTasks();
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: res.message
                            });
                        }
                    },
                    error: function (xhr) {
                        console.log("Error updating status:", xhr.responseText);
                    }
                });
            };
        });
    </script>


    <?php
    require_once('../include/sweetAlert.php');
    if (isset($_SESSION['status'])) {
        $status = $_SESSION['status'];
        $msg = $_SESSION['msg'];
        echo "<script>toastMsg('" . ($status ? 'success' : 'error') . "', '$msg');</script>";
        unset($_SESSION['status'], $_SESSION['msg']);
    }
    ?>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</body>

</html>