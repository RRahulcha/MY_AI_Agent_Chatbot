<?php
session_start();
if(!isset($_SESSION['userId']) && empty($_SESSION['userId'])){
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}
include('../include/db_config.php');

// ✅ Fetch all records from TaskMaster
$tasksData = mysqli_query($con, "SELECT * FROM TaskMaster ORDER BY taskId DESC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Task Entry</title>
    <?php include('../partials/_links.php'); ?>

    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">


    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Poppins', sans-serif;
        }
        .card {
            border: none;
            border-radius: 12px;
            background: #fff;
            box-shadow: 0 4px 12px rgba(0,0,0,0.06);
            padding: 20px;
        }
        h4.page-title {
            font-weight: 600;
            color: #103d9e;
            margin-bottom: 20px;
        }
        .form-control {
            height: 2.4rem;
            border-radius: 6px;
        }
        .add-btn {
            background-color: #103d9e;
            color: white;
            border: none;
            border-radius: 50%;
            width: 35px;
            height: 35px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: 0.3s ease;
        }
        .add-btn:hover { background-color: #0d2f7a; }
        .task-row { margin-bottom: 10px; }

        table th {
            background-color: #103d9e;
            color: #fff;
        }
        table td, table th {
            vertical-align: middle !important;
        }
        .remove-btn{
            background-color: #9E1010FF;
            color: white;
            border: none;
            border-radius: 50%;
            width: 35px;
            height: 35px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: 0.3s ease;
        }
        .dataTables_filter {
    display: none; /* hides default search bar if using your custom one */
}
/* ======= TaskMaster Table Styling ======= */
.table {
    border-collapse: separate;
    border-spacing: 0;
    width: 100%;
    border-radius: 12px;
    overflow: hidden;
    background: #fff;
    box-shadow: 0 4px 12px rgba(0,0,0,0.06);
}

.table thead th {
    background: #103d9e !important;
    color: #fff;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border: none !important;
    padding: 14px 16px;
}

.table tbody tr {
    transition: all 0.2s ease-in-out;
}

.table tbody tr:hover {
    background: rgba(16, 61, 158, 0.08);
    transform: scale(1.01);
}

.table td {
    padding: 12px 16px;
    border-color: #e6e6e6 !important;
    color: #333;
}

/* ======= DataTables Customizations ======= */
.dataTables_wrapper {
    margin-top: 10px;
    background: #fff;
    border-radius: 10px;
    padding: 10px 15px 15px;
    box-shadow: 0 3px 8px rgba(0,0,0,0.05);
}

.dataTables_info {
    color: #555;
    margin-top: 8px;
}

.dataTables_paginate {
    margin-top: 12px;
}

.dataTables_paginate .paginate_button {
    border: none !important;
    background: #f0f3ff !important;
    color: #103d9e !important;
    border-radius: 6px !important;
    margin: 0 3px;
    padding: 6px 12px !important;
    transition: all 0.2s ease;
}

.dataTables_paginate .paginate_button:hover {
    background: #103d9e !important;
    color: #fff !important;
}

.dataTables_paginate .paginate_button.current {
    background: #103d9e !important;
    color: #fff !important;
}

/* ======= Search Bar Styling ======= */
#masterSearch {
    border: 1px solid #d0d4e4;
    border-radius: 8px;
    padding: 10px 14px;
    font-size: 15px;
    outline: none;
    transition: 0.3s ease;
}

#masterSearch:focus {
    border-color: #103d9e;
    box-shadow: 0 0 4px rgba(16, 61, 158, 0.4);
}

/* ======= Buttons inside table ======= */
.btn-sm {
    padding: 5px 10px;
    font-size: 13px;
    border-radius: 6px;
    transition: 0.3s;
}

.btn-primary.btn-sm {
    background-color: #103d9e;
    border: none;
}

.btn-primary.btn-sm:hover {
    background-color: #0d2f7a;
}

.btn-danger.btn-sm {
    background-color: #dc3545;
    border: none;
}

.btn-danger.btn-sm:hover {
    background-color: #b02a37;
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

                <!-- ================= Task Entry Form ================= -->
                <div class="row">
                    <div class="col-12 grid-margin stretch-card">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="page-title">Task Entry</h4>

                                <form id="taskForm">
                                    <div class="row align-items-center">
                                        <div class="col-md-4">
                                            <div class="form-group">
                                                <label>Task Type <span class="text-danger">*</span></label>
                                                <select class="js-example-basic-single form-control" id="taskType" name="taskType" required>
                                                    <option value="" selected disabled>-- Select Task Type --</option>
                                                    <option value="Project Work">Project Work</option>
                                                    <option value="Student Training">Student Training</option>
                                                    <option value="Other">Other</option>
                                                    <option value="Lunch Break">Lunch Break</option>
                                                    <option value="Break">Break</option>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="col-md-8">
                                            <div class="form-group">
                                                <label>Task <span class="text-danger">*</span></label>
                                                <div id="taskFields">
                                                    <div class="input-group mb-2 task-row">
                                                        <input type="text" class="form-control" name="task[]" placeholder="Enter task">
                                                        <button type="button" class="add-btn" id="addTaskBtn" title="Add another task">
                                                            <i class="bi bi-plus-lg"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <button type="button" id="saveBtn" class="btn btn-primary mt-3">Save Tasks</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- ✅ Edit Task Modal -->
<div class="modal fade" id="editTaskModal" tabindex="-1" aria-labelledby="editTaskModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title" id="editTaskModalLabel">Edit Task</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form id="editTaskForm">
          <input type="hidden" id="editTaskId" name="taskId">
          <div class="form-group mb-3">
            <label>Task Type</label>
            <select class="form-control" id="editTaskType" name="taskType" required>
              <option value="Project Work">Project Work</option>
              <option value="Student Training">Student Training</option>
              <option value="Other">Other</option>
              <option value="Lunch Break">Lunch Break</option>
              <option value="Break">Break</option>
            </select>
          </div>
          <div class="form-group mb-3">
            <label>Task</label>
            <input type="text" class="form-control" id="editTask" name="task" required>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="updateTaskBtn">Update</button>
      </div>
    </div>
  </div>
</div>

                <!-- ================= TaskMaster Records Table ================= -->
                <div class="row mt-4">
                    <div class="col-12 grid-margin stretch-card">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="page-title">TaskMaster Records</h4>
                                <div class="table-responsive">
                                    <input type="text" id="masterSearch" class="form-control mb-3" placeholder="Search by Task Type or Task...">
                                    <table class="table table-bordered table-hover">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Task Type</th>
                                                <th>Task</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if(mysqli_num_rows($tasksData) > 0): ?>
                                                <?php $i=1; while($row = mysqli_fetch_assoc($tasksData)): ?>
                                                    <tr>
                                                        <td><?= $i++ ?></td>
                                                        <td><?= htmlspecialchars($row['taskType']) ?></td>
                                                        <td><?= htmlspecialchars($row['task']) ?></td>
                                                        <td>
                                                            <button class="btn btn-sm btn-primary editTask" 
                                                                data-id="<?= $row['taskId'] ?>" 
                                                                data-type="<?= htmlspecialchars($row['taskType']) ?>" 
                                                                data-task="<?= htmlspecialchars($row['task']) ?>">
                                                                <i class="bi bi-pencil-square"></i>
                                                            </button>

                                                            <button class="btn btn-sm btn-danger deleteTask" data-id="<?= $row['taskId'] ?>">
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                        </td>
                                                    </tr>
                                                <?php endwhile; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="4" class="text-center text-muted">No tasks found</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div> <!-- content-wrapper -->
        </div> <!-- main-panel -->
    </div> <!-- page-body-wrapper -->
</div> <!-- container-scroller -->

<!-- JS -->
<script src="../vendors/js/vendor.bundle.base.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
$(document).ready(function(){

    // Initialize select2
    $('.js-example-basic-single').select2({
        placeholder: "-- Select Option --",
        allowClear: true,
        width: '100%'
    });

    // Add new task input dynamically
    $(document).on("click", "#addTaskBtn", function(){
        let newTaskRow = `
            <div class="input-group mb-2 task-row">
                <input type="text" class="form-control" name="task[]" placeholder="Enter task">
                <button type="button" class="remove-btn" title="Remove this task">
                    <i class="bi bi-dash-lg"></i>
                </button>
            </div>`;
        $("#taskFields").append(newTaskRow);
    });

    // Remove a task input
    $(document).on("click", ".remove-btn", function(){
        $(this).closest(".task-row").remove();
    });

    // ✅ Save tasks to DB
    $("#saveBtn").click(function(){
        let taskType = $("#taskType").val();
        let tasks = $("input[name='task[]']").map(function(){ return $(this).val(); }).get();

        if(!taskType){
            Swal.fire({ icon: 'warning', title: 'Missing', text: 'Please select Task Type.' });
            return;
        }

        tasks = tasks.filter(t => t.trim() !== "");
        if(tasks.length === 0){
            Swal.fire({ icon: 'warning', title: 'Missing', text: 'Please enter at least one task.' });
            return;
        }

        $.ajax({
            url: "../ajax/add_taskmaster.php",
            type: "POST",
            dataType: "json",
            data: { taskType: taskType, task: tasks },
            success: function(res){
                if(res.success){
                    Swal.fire({
                        icon: 'success',
                        title: 'Success',
                        text: res.message,
                        showConfirmButton: false,
                        timer: 1200
                    }).then(() => {
                        location.reload(); // refresh table
                    });
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: res.message });
                }
            },
            error: function(xhr){
                console.log("Error:", xhr.responseText);
                Swal.fire({ icon: 'error', title: 'Error', text: 'Something went wrong.' });
            }
        });
    });

    // ✅ Delete Task
    $(document).on("click", ".deleteTask", function(){
        let id = $(this).data("id");

        Swal.fire({
            title: "Are you sure?",
            text: "This task will be deleted!",
            icon: "warning",
            showCancelButton: true,
            confirmButtonText: "Yes, delete it!",
            cancelButtonText: "Cancel"
        }).then((result) => {
            if(result.isConfirmed){
                $.ajax({
                    url: "../ajax/delete_taskmaster.php",
                    type: "POST",
                    dataType: "json",
                    data: { id: id },
                    success: function(res){
                        if(res.success){
                            Swal.fire({
                                icon: 'success',
                                title: 'Deleted',
                                text: res.message,
                                timer: 1200,
                                showConfirmButton: false
                            }).then(() => location.reload());
                        } else {
                            Swal.fire({ icon: 'error', title: 'Error', text: res.message });
                        }
                    }
                });
            }
        });
    });

});

// ✅ Open Edit Modal and fill data
$(document).on("click", ".editTask", function(){
    let id = $(this).data("id");
    let type = $(this).data("type");
    let task = $(this).data("task");

    $("#editTaskId").val(id);
    $("#editTaskType").val(type);
    $("#editTask").val(task);

    $("#editTaskModal").modal("show");
});

// ✅ Update Task (AJAX)
$("#updateTaskBtn").click(function(){
    let id = $("#editTaskId").val();
    let type = $("#editTaskType").val();
    let task = $("#editTask").val();

    if(task.trim() === ""){
        Swal.fire({ icon: 'warning', title: 'Missing', text: 'Task cannot be empty.' });
        return;
    }

    $.ajax({
        url: "../ajax/update_taskmaster.php",
        type: "POST",
        dataType: "json",
        data: { taskId: id, taskType: type, task: task },
        success: function(res){
            if(res.success){
                Swal.fire({
                    icon: 'success',
                    title: 'Updated',
                    text: res.message,
                    showConfirmButton: false,
                    timer: 1200
                }).then(() => location.reload());
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: res.message });
            }
        },
        error: function(xhr){
            console.log(xhr.responseText);
            Swal.fire({ icon: 'error', title: 'Error', text: 'Something went wrong.' });
        }
    });
});
// // ✅ Initialize DataTable
// let taskTable = $('table').DataTable({
//     "pageLength": 10,
//     "ordering": true,
//     "lengthChange": false
// });

// // ✅ Master search (optional)
// $('#masterSearch').on('keyup', function() {
//     taskTable.search(this.value).draw();
// });
</script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script>
$(document).ready(function(){
    // ✅ Initialize DataTable
    let taskTable = $('table').DataTable({
        "pageLength": 10,
        "ordering": true,
        "lengthChange": false
    });

    // ✅ Master search
    $('#masterSearch').on('keyup', function() {
        taskTable.search(this.value).draw();
    });
});
</script>
</body>
</html>
