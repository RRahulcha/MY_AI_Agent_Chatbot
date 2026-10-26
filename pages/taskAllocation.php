<?php
session_start();
if (!isset($_SESSION['userId']) && empty($_SESSION['userId'])) {
    // header('location:login');
    // die();
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}

if ($_SESSION['designation'] === 'seniorIntern' || $_SESSION['userRole'] === 'senior_intern') {
    echo "<script>
        alert('Unauthorized access');
        window.location.href = 'srEmpDashboard.php';
    </script>";
    exit;
}

include('../include/db_config.php');
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>SETTribe AMS - Task Allocation</title>

    <?php
    include('../partials/_links.php')
        ?>

    <link rel="stylesheet" href="https://cdn.datatables.net/rowreorder/1.3.1/css/rowReorder.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.4.0/css/responsive.dataTables.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" />
    <style>
        .star {
            font-size: 18px;
            color: red;
        }

      /* Select2 Single Select */
.select2-container .select2-selection--single {
    box-sizing: border-box;
    cursor: pointer;
    padding-top: 6px;
    display: block;
    height: 40px;
    user-select: none;
}

/* Adjust the dropdown arrow spacing */
.select2-container--default .select2-selection--single .select2-selection__arrow {
    margin: 5px;
}

/* Select2 Multi-Select Styling */
.select2-container--default .select2-selection--multiple {
    min-height: 40px;
    max-height: 150px; /* Prevents overflow */
    overflow-y: auto; /* Enables scrollbar if content overflows */
    display: flex;
    flex-wrap: wrap; /* Ensures proper wrapping of items */
    align-items: center;
}

/* Styling for Selected Items in Multi-Select */
.select2-container--default .select2-selection--multiple .select2-selection__choice {
    font-size: 14px;
    margin: 2px;
    padding: 5px 10px;
    background-color: #f1f1f1;
    border-radius: 4px;
    color: #333;
}

/* Ensures the input takes the remaining space */
.select2-container--default .select2-selection--multiple .select2-search--inline {
    flex: 1;
}



/* Button Group Wrapper */
.button-group {
    display: flex;
    gap: 15px; /* space between buttons */
    margin: 15px 0;
    justify-content: center; /* center the buttons */
}

/* General Style for Buttons */
.button-group button {
    width: 50px;
    height: 50px;
    border-radius: 50%; /* circular */
    font-size: 22px;
    font-weight: bold;
    color: #fff;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    /* box-shadow: 0px 4px 8px rgba(0,0,0,0.2); */
    transition: all 0.3s ease-in-out;
}


/* ===== Allocated Task Table Modern Style ===== */
#allocatedTaskTable {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    background: #fff;
    border-radius: 8px;
    overflow: hidden;
    font-family: 'Segoe UI', sans-serif;
    font-size: 14px;
}

/* ===== Allocated Task Table Modern Style with Borders ===== */
#allocatedTaskTable {
    width: 100%;
    border-collapse: collapse; /* ensures borders touch */
    background: #fff;
    font-family: 'Segoe UI', sans-serif;
    font-size: 14px;
    /* border: 1px solid #ddd; outer border */
}

/* Table header style */
#allocatedTaskTable thead {
    background-color: #103d9eff;
    color: #ffffffff;
}

#allocatedTaskTable thead th {
    padding: 12px 35px;
    font-weight: 600;
    text-align: left;
    border: 1px solid #ddd; /* header cell border */
    white-space: nowrap;
}

/* Table body rows */
/* #allocatedTaskTable tbody tr:nth-child(even) {
    background-color: #f1f5ff;
} */

#allocatedTaskTable tbody tr:hover {
     background-color: #dce4f8ff;
}

/* Table body cells */
#allocatedTaskTable tbody td {
    padding: 10px 15px;
    border: 1px solid #ddd; /* cell border */
    vertical-align: middle;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Action column buttons */
#allocatedTaskTable .btn {
    padding: 4px 8px;
    font-size: 13px;
    border-radius: 5px;
}

/* Optional: shadow for modern look */
.table-responsive {
    margin-bottom: 20px;
    box-shadow: 0px 2px 8px rgba(0,0,0,0.05);
    border-radius: 8px;
}


    </style>
</head>

<body>
    <div class="container-scroller">

        <?php
        include('../partials/_navbar.php')
            ?>

        <div class="container-fluid page-body-wrapper">

            <?php
            include('../partials/_sidebar.php');
            ?>

            <div class="main-panel">
                <div class="content-wrapper">
                    <div class="row">
                        <div class="col-12 grid-margin stretch-card">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title">Task Allocation</h4>
                                    <form class="forms-sample" action="taskAllocation_db.php" method="POST"
                                        style="align-items: center;">


                                        <?php
                                        $task = $project = $employee = $priority = $expectedDeadline ="";
                                        $multEmp = "multiple";
                                        $taskBtn = true;

                                        if (isset($_GET['edit'])) {
                                            $id = mysqli_real_escape_string($con, $_GET['edit']);
                                            // $q = mysqli_query($con, "SELECT te.taskId, te.id , te.task as task, te.projectId as project, te.userId AS userId, te.priority as priority, te.expectedDeadline as expectedDeadLine, p.projectId, p.projectName as ProjectName
                                            // FROM taskEntry te
                                            // JOIN projectMaster p ON te.projectId=p.projectId
                                            // WHERE te.id = '$id'");

                                            // $q = mysqli_query($con, "SELECT taskId, id ,task,projectId as project, userId, priority , expectedDeadline, 
                                            // FROM taskEntry
                                            // WHERE id = '$id'");

                                            $q = mysqli_query($con, "SELECT 
                                                                    te.id,
                                                                    te.taskId,
                                                                    te.task,
                                                                    te.priority,
                                                                    te.status,
                                                                    te.expectedDeadline,
                                                                    te.userId,
                                                                    te.projectId,
                                                                    pm.projectName AS ProjectName,
                                                                    u.fullName
                                                                FROM taskEntry te
                                                                JOIN projectMaster pm ON te.projectId = pm.projectId
                                                                JOIN signup u ON te.userId = u.userId
                                                                WHERE te.id = '$id'
                                                                ");
                                            $r = mysqli_fetch_assoc($q);
                                            $task = $r['task'];
                                            $project = $r['projectId'];
                                            $employee = $r['userId'];
                                            $priority = $r['priority'];
                                            $expectedDeadline = $r['expectedDeadline'];
                                            // $ProjectName=$r['ProjectName'];
                                            $multEmp = "";
                                            $taskBtn = false;
                                        }
                                        ?>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label for="project">Project</label>
                                                    <select class="js-example-basic-single w-100" name="project"
                                                        id="project" required>
                                                        <option value="" selected disabled>-- Select Project --</option>
                                                         <?php
                                                         $q = mysqli_query($con, "SELECT projectName, client, projectId FROM projectMaster WHERE status = 1 ORDER BY projectName ASC");
                                                         while ($r = mysqli_fetch_assoc($q)) {
                                                             $selected = ($project == $r['projectId']) ? "selected" : "";
                                                             $pName = $r['projectName'];
                                                             $pClient = trim($r['client'] ?? '');
                                                             $pLabel = (!empty($pClient) && strtoupper($pClient) !== 'NA') ? ($pName . ' - ' . $pClient) : $pName;
                                                             ?>
                                                             <option value="<?php echo $r['projectId'] ?>" <?php echo $selected ?>><?php echo htmlspecialchars($pLabel) ?></option>
                                                             <?php
                                                         }
                                                         ?>
                                                    </select>
                                                </div>
                                            </div>


                                        </div>

                                        <!-- Task Fields Wrapper -->
                                        <div id="taskWrapper">
                                            <div class="taskRow">
                                                <div class="row" style="align-items: flex-end;">
                                                    <div class="col-md-6">
                                                        <div class="form-group">
                                                            <label for="task">Task <span class="star">*</span></label>
                                                            <input type="text" class="form-control" name="task[]"
                                                                value="<?php echo $task; ?>" placeholder="Enter Task"
                                                                required>
                                                        </div>
                                                    </div>

                                                    <div class="col-md-6">
                                                        <div class="form-group">
                                                            <label>Employees <span class="star">*</span></label>
                                                            <select class="js-example-basic-single w-100"
                                                                name="employees[]" required id="employees">
                                                                <option value="" selected disabled>-- Select Employee --
                                                                </option>
                                                                <?php
                                                                $q = mysqli_query($con, "SELECT fullName, userId FROM signup WHERE designation != 'admin'");
                                                                while ($r = mysqli_fetch_assoc($q)) {
                                                                    // Ensure $employee is properly set for pre-selection
                                                                    $selected = (isset($employee) && $employee == $r['userId']) ? "selected" : "";
                                                                    echo "<option value='{$r['userId']}' $selected>{$r['fullName']}</option>";
                                                                }
                                                                ?>

                                                            </select>
                                                        </div>
                                                    </div>

                                                    <div class="col-md-6">
                                                        <div class="form-group">
                                                            <label for="priority">Priority</label>
                                                            <select class="form-control" name="priority[]">
                                                                <option selected disabled value="">-- Select Priority --
                                                                </option>
                                                                <option value="High" <?php echo ($priority == 'High') ? 'selected' : ''; ?>>High</option>
                                                                <option value="Medium" <?php echo ($priority == 'Medium') ? 'selected' : ''; ?>>Medium</option>
                                                                <option value="Low" <?php echo ($priority == 'Low') ? 'selected' : ''; ?>>Low</option>
                                                            </select>
                                                        </div>
                                                    </div>

                                                    <div class="col-md-6">
                                                        <div class="form-group">
                                                            <label for="expectedDeadline">Expected Completion
                                                                Time</label>
                                                                
                                                           <input type="datetime-local" class="form-control"
                                                                id="expectedDeadline"
                                                                  name="expectedDeadline[]"
                                                                      value="<?php echo isset($expectedDeadline) ? $expectedDeadline : ''; ?>"
                                                                 min="<?php echo date('Y-m-d\TH:i'); ?>" required>


                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                        </div>

                                       <!-- Buttons (only one set outside task wrapper) -->
                                        <div class="col-md-1 button-group">
                                            <button type="button" class="btn btn-success add-task">+</button>
                                            <button type="button" class="btn btn-danger remove-task">-</button>
                                        </div>



                                        <?php if (isset($_GET['edit'])) { ?>
                                            <button type="submit" class="btn btn-primary" name="update"
                                                value="<?php echo $_GET['edit'] ?>">Update</button>
                                        <?php } else { ?>
                                            <button type="submit" class="btn btn-primary" name="allocate"
                                                onclick="">Allocate</button>
                                        <?php } ?>
                                    </form>

                                </div>
                            </div>
                        </div>

                        <div class="col-lg-12 grid-margin stretch-card">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title">Allocated Task</h4>
                                    <div class="table-responsive">
                                        <table class="table table-hover" id="allocatedTaskTable">
                                            <thead>
                                                <tr>
                                                    <th>Sr No.</th>
                                                    <th>Project Name</th>
                                                    <th>Task</th>
                                                    <th>Allocated To :</th>
                                                    <th>Priority :</th>
                                                    <th>Status :</th>
                                                    <th>Action :</th>
                                                </tr>
                                            </thead>
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

    <!-- plugins:js -->
    <script src="../vendors/js/vendor.bundle.base.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
    <!-- End plugin js for this page -->
    <!-- Plugin js for this page -->
    <script src="../vendors/chart.js/Chart.min.js"></script>
    <script src="../vendors/datatables.net/jquery.dataTables.js"></script>
    <script src="../vendors/datatables.net-bs4/dataTables.bootstrap4.js"></script>
    <script src="../js/dataTables.select.min.js"></script>

    <!-- End plugin js for this page -->
    <!-- inject:js -->
    <script src="../js/off-canvas.js"></script>
    <script src="../js/hoverable-collapse.js"></script>
    <script src="../js/template.js"></script>
    <script src="../js/settings.js"></script>
    <script src="../js/todolist.js"></script>
    <!-- endinject -->
    <!-- Custom js for this page-->
    <script src="../js/dashboard.js?v=1.0.1"></script>
    <script src="../js/Chart.roundedBarCharts.js"></script>

    <script>
        document.addEventListener("DOMContentLoaded", function () {
            let expectedDeadline = document.getElementById("expectedDeadline");

            function setMinDateTime() {
                let now = new Date();
                let year = now.getFullYear();
                let month = String(now.getMonth() + 1).padStart(2, '0'); // Ensure 2 digits
                let day = String(now.getDate()).padStart(2, '0'); // Ensure 2 digits
                let hours = String(now.getHours()).padStart(2, '0'); // Ensure 2 digits
                let minutes = String(now.getMinutes()).padStart(2, '0'); // Ensure 2 digits

                let minDateTime = `${year}-${month}-${day}T${hours}:${minutes}`;
                expectedDeadline.min = minDateTime;

                // If the input has an invalid value, reset it
                if (expectedDeadline.value < minDateTime) {
                    expectedDeadline.value = minDateTime;
                }
            }

            // Set min date-time on page load
            setMinDateTime();

            // Update min date-time every second to keep it accurate
            setInterval(setMinDateTime, 1000);
        });
    </script>

    <script>
        $(document).ready(() => {
            $('#allocatedTaskTable').DataTable({
                'processing': true,
                'serverSide': true,
                'serverMethod': 'post',
                'ajax': {
                    'url': '../ajax/taskAllocation.php?dataTable='
                },
                'responsive': true,
                'columns': [
                    { data: 'srNo' },
                    {data:'ProjectName'},
                    { data: 'task' },
                    { data: 'fullName' },
                    { data: 'priority' },
                    { data: 'status' },
                    { data: 'action' },
                ],
                'columnDefs': [
                    { "orderable": false, "targets": [3, 4] }
                ],
                     
                "rowCallback": function(row, data, displayIndex) {
            var table = $('#allocatedTaskTable').DataTable();
            var pageInfo = table.page.info();
            // Calculate serial number
            $('td:eq(0)', row).html(pageInfo.start + displayIndex + 1);
        }

            });
        });


    </script>

    
<script>
$(document).ready(function () {
    // Function to initialize Select2
    function initializeSelect2(selector) {
        $(selector).select2({
            placeholder: "-- Select Employee --",
            allowClear: true
        });
    }

    // Initialize Select2 on page load
    initializeSelect2(".js-example-basic-single");

    // Function to load employee options dynamically
    function loadEmployeeOptions(selectElement) {
        $.ajax({
            url: "fetchEmployees.php",
            type: "GET",
            success: function (data) {
                $(selectElement).html(data);
                initializeSelect2(selectElement); // Reinitialize Select2 after setting options
            }
        });
    }

    // Function to update button visibility
    function updateButtons() {
        let taskRows = $(".taskRow");
        $(".remove-task").toggle(taskRows.length > 1); // Show remove button only if more than one row exists
    }

    // Add Task Event
    $(".add-task").click(function () {
        let newTask = $(".taskRow:first").clone(); // Clone first task row
        newTask.find("input, select").val(""); // Clear input values

        // Remove previous Select2 instance and reset attributes
        newTask.find(".select2-container").remove();
        let newSelect = newTask.find(".js-example-basic-single");

        // Remove Select2 attributes and generate unique ID
        let newId = "employees-" + $(".taskRow").length;
        newSelect.attr("id", newId).removeClass("select2-hidden-accessible").removeAttr("data-select2-id tabindex aria-hidden");

        $("#taskWrapper").append(newTask); // Append cloned row

        // Load employee options for the new select element
        loadEmployeeOptions(newSelect);
        

        updateButtons(); // Update button visibility
    });

    // Remove Task Event (only last row can be removed)
    $(document).on("click", ".remove-task", function () {
        let taskRows = $(".taskRow");
        if (taskRows.length > 1) {
            taskRows.last().remove();
            updateButtons(); // Update button visibility
        } else {
            alert("At least one task must remain.");
        }
    });

    updateButtons(); // Set initial button visibility
});

    </script>

<!-- for delete button -->
<script>
function deleteFun(page, id) {
    Swal.fire({
        title: "Are you sure?",
        text: "This task will be deleted permanently!",
        icon: "warning",
        showCancelButton: true,
        confirmButtonColor: "#3085d6",
        cancelButtonColor: "#d33",
        confirmButtonText: "Yes, delete it!"
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = page + "?delete=" + id;
        }
    });
}
</script>


    <?php
    require_once('../include/sweetAlert.php');
    if (isset($_SESSION['status'])) {
        $status = $_SESSION['status'];
        $msg = $_SESSION['msg'];
        if ($status != true) {
            toastMsg('error', $msg);
        } else {
            toastMsg('success', $msg);
        }
        unset($_SESSION['status']);
        unset($_SESSION['msg']);
    }
    ?>




</body>

</html>