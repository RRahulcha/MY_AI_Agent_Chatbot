<?php
session_start();
if(!isset($_SESSION['userId']) && empty($_SESSION['userId'])){
    // header('location:login');
    // die();
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}
include('../include/db_config.php');
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>Project Master</title>
    <?php
    include('../partials/_links.php')
    ?>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        .btn-secondary{
            color: white;
        }
        
        #cancelBtn{
            display: none;
        }
    </style>
    <style>
    .form-control
    {
        height:2.3rem;
    }
    .select2-container .select2-selection--single {
    box-sizing: border-box;
    cursor: pointer;
    padding: 1%;
    display: block;
    height: 37px;
    user-select: none;
    -webkit-user-select: none;
}
.select2-container--default .select2-selection--multiple .select2-selection__choice{
    font-size: 1rem;
}

.task
{
    display:none;
}

.required
{
   color:red; 
}
</style>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
  <script>
      $(document).ready(function()
      {
      });
  </script>
</head>

<body>
    <div class="container-scroller">

        <?php
        include('../partials/_navbar.php')
        ?>

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
                                    <h4 class="card-title" id="pageTitle">Add New Task</h4>
                                    
                                    <form class="forms-sample" action="taskEntry_db.php" method="post">
                                        <div class="row">
                                            <div class="col-md-4">
                                                <?php 
                                                 if(isset($_GET['taskId'])){
                                        $taskId = $_GET['taskId'];
                                        $query = mysqli_query($con, "SELECT projectMaster.projectId, projectMaster.projectName, taskEntry.taskId, taskEntry.task, taskEntry.location, taskEntry.expectedDeadline FROM taskEntry JOIN projectMaster ON taskEntry.projectId = projectMaster.projectId WHERE taskId = '$taskId'");
                                        $result = mysqli_fetch_assoc($query);
                                    
                                    }
                                                 
                                                ?>
                                                <input type="hidden" name="taskId" value="<?php echo $taskId ?>">
                                                <div class="form-group">
                                                    <label>Select Project</label>
                                                    <select class="js-example-basic-single w-100 remov" style="width:100%" id="projectId" name="projectId">
                                                        <option value="" selected="" disabled="">-- Select Project --</option>
                                                        <optgroup label="Allocated Project">
                                                            <?php
                                                            $userId = $_SESSION['userId'];
                                                                $q = mysqli_query($con, "SELECT allocatedProject.projectId, projectMaster.projectName FROM `allocatedProject` LEFT JOIN projectMaster ON allocatedProject.projectId = projectMaster.projectId WHERE userId = '$userId'");
                                                            while($r = mysqli_fetch_assoc($q)){
                                                                $allocatedProject .= "projectId != '{$r['projectId']}'";
                                                            ?>
                                                            <option value="<?php echo $r['projectId'] ?>"><?php echo $r['projectName'] ?></option>
                                                            <?php
                                                            }
                                                            ?>
                                                        </optgroup>
                                                        <optgroup label="Unallocated Project">
                                                            <?php
                                                            $q = mysqli_query($con, "SELECT projectName, projectId FROM projectMaster WHERE $allocatedProject");
                                                            while($r = mysqli_fetch_assoc($q)){
                                                            ?>
                                                            <option value="<?php echo $r['projectId'] ?>"><?php echo $r['projectName'] ?></option>
                                                            <?php
                                                            }
                                                            ?>
                                                        </optgroup>
                                                        <?php 
                                                    if(isset($_GET['taskId'])){ 
                                                    ?>
                                                   <option selected="" value="<?php echo $result['projectId'] ?>"><?php echo $result['projectName'] ?></option>
                                                   <?php 
                                                    }
                                                    ?>
                                                    </select>
                                                </div>
                                            </div>
                                            
                                            <div class="col-md-4">
                                                <div class="form-group">
                                                    <label>Select Existing Task <span class="required">*</span></label>
                                                    <select class="js-example-basic-single w-100" style="width:100%" onchange="taskFun()" required id="task" name="task">
                                                        <option value="" selected="" disabled="">-- Select Task --</option>
                                                        <option value="task">Add New Task</option>
                                                        <?php
                                                        $q = mysqli_query($con, "SELECT DISTINCT(taskEntry.taskId), taskEntry.projectId, taskEntry.task FROM taskEntry LEFT JOIN taskStatus ON taskEntry.taskId = taskStatus.taskId WHERE taskEntry.userId = '{$_SESSION['userId']}' ORDER BY taskEntry.dateTime DESC, taskEntry.id DESC;");
                                                        while($r = mysqli_fetch_assoc($q)){
                                                         
                                                        ?>
                                                        <option value="<?php echo $r['taskId'] ?>"><?php echo $r['task'] ?></option>
                                                        <?php
                                                        
                                                        }
                                                        ?>
                                                        
                                                       
                                                    </select>
                                              
                                                </div>
                                            </div>
                                            
                                            <div class="col-md-4" id="taskDiv">
                                                <div class="form-group">
                                                    <label for="client">Task</label>
                                                    <input type="text" class="form-control" name="newTask" id="newTask" value="<?php echo $result['task'] ?>">
                                                </div>
                                            </div>
                                            
                                            <div class="col-md-2">
                                                <div class="form-group">
                                                    <label for="client">Select Location <span class="required">*</span></label>
                                                    <select class="form-control remov" required id="exampleSelectGender" name="location">
                                                        <option value="" selected="" disabled="">-- Select Location --</option>
                                                          <option>Office</option>
                                                          <option>Work From Home</option>
                                                          
                                                          <?php 
                                                    if(isset($_GET['taskId'])){ 
                                                    ?>
                                                   <option selected="" value="<?php echo $result['location'] ?>"><?php echo $result['location'] ?></option>
                                                   <?php 
                                                    }
                                                    ?>
                                                    </select>
                                                </div>
                                            </div>

                                            <div class="mb-3">
                <label for="start_date" class="form-label">Expected Start Date</label>
                <input type="date" class="form-control" id="start_date" name="start_date" required>
            </div>
            <div class="mb-3">
                <label for="end_date" class="form-label">Expected End Date</label>
                <input type="date" class="form-control" id="end_date" name="end_date" required>
            </div>
                                            
                                            <div class="col-md-2">
                                                <div class="form-group">
                                                    <label for="client">Expected Time</label>
                                                    <input type="text" class="form-control js-datepicker" name="expectedDeadline" id="expectedDeadline" value="<?php echo $result['expectedDeadline'] ?>">
                                                </div>
                                            </div>
                                        </div>
                                         <?php
                                         
                                        if(isset($_GET['taskId'])){
                                        ?>
                                        <button type="submit" id="submitBtn" class="btn btn-primary mr-2" name="update">Update</button>
                                        <?php } 
                                        else
                                        {
                                            ?>
                                            
                                        <button type="submit" id="submitBtn" class="btn btn-primary mr-2" name="start">Submit</button>
                                        
                                        <?php
                                        }
                                        ?>
                                        
                                        <a href="projectMaster" id="cancelBtn">
                                            <button type="button" class="btn btn-dark">Cancel</button>
                                        </a>
                                    </form>
                                </div>
                            </div>
                        </div>


                        <div class="col-lg-12 grid-margin stretch-card">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title">Tasks</h4>
                                    <div class="table-responsive">
                                        <table class="table table-hover" id="taskEntryTable">
                                            <thead>
                                                <tr>
                                                     <th>Task Update</th>
                                                    <th>Project Name</th>
                                                    <th>Task <span style="font-size:10px;">self/allocated</span></th>
                                                    <th>Expected Time</th>
                                                    <th>Man Hr.</th>
                                                     <th>Location</th>
                                                    <th>Status</th>
                                                    <th>Action</th>
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

   <script src="../vendors/js/vendor.bundle.base.js"></script>
    <script>
        
        $(document).ready(() => {
            let dataTable = $('#taskEntryTable').DataTable({
                'order': [[2, 'desc']],
                'processing': true,
                'serverSide': true,
                'serverMethod': 'post',
                'ajax': {
                    'url':'../ajax/taskEntry?dataTable='
                },
                'responsive': true,
                'columns': [
                    {data:'TaskUpdate'},
                    {data:'projectName'},
                    {data:'task'},
                    {data:'expectedDeadline'},
                    {data:'manHr'},
                    {data:'location'},
                    {data:'status'},
                    {data:'action'},
                ],
                'columnDefs': [
                    { "orderable": false, "targets": [0,6,7] }
                ]
            });
            
            dataTable.draw();
        });
    
    
        $("#taskDiv").hide(); 
        
        let taskFun = () => {
            
            if($("#task").val() == "task"){
                $("#taskDiv").show(); 
                $("#newTask").attr('required', 'required');
            } else {
                $("#newTask").removeAttr('required').val('');
                $("#taskDiv").hide();
            }
        }
       
<?php 

if(isset($_GET['taskId']))
{
echo "$('#task').val('task');taskFun()";
}

?>
    
    </script>

    <script src="../vendors/typeahead.js/typeahead.bundle.min.js"></script>
    <script src="../vendors/select2/select2.min.js"></script>
    <script src="../js/template.js"></script>
    <script src="../js/settings.js"></script>
    <script src="../js/select2.js"></script>
    
    <!-- Date picker script -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <!-- Date picker script end -->
    
    <!-- This page script -->
    
    <!-- This page script end -->
    
    <!-- Data Table Script Link -->
    <script src="../vendors/datatables.net/jquery.dataTables.js"></script>
    <script src="../vendors/datatables.net-bs4/dataTables.bootstrap4.js"></script>
    <!-- Data Table Script Link End -->
    
    
    <!-- plugins:js -->
	<script src="../vendors/js/vendor.bundle.base.js"></script>
	<!-- endinject -->
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
	<script src="../js/dashboard.js?v=<?php echo time(); ?>"></script>
	<script src="../js/Chart.roundedBarCharts.js"></script>
	<!-- End custom js for this page-->
    
    <script>
        flatpickr(".js-datepicker", {
            enableTime: true,
            dateFormat: "d-m-Y H:i",
            minDate: "<?php echo date('d-m-Y H:i') ?>"
            // minTime: ""
        });
        
        window.addEventListener('onbeforeunload', function (e) {
            status = document.getElementById("body").value;
            if (status == "running") {
                e.preventDefault();
                e.returnValue = '';
            }
        })
        
        
        
        $("#projectId").on("change", function(){
            var projectId = $("#projectId").val();
            
            $.ajax({
               type : 'POST',
               url  : '../ajax/taskEntry',
               data : {projectId: projectId},
               success:function(result){
                   console.log(result);
                   $("#task").html(result);
               }
            });
        });
        
        $(".remov option").each(function() {
          $(this).siblings('[value="'+ this.value +'"]').remove();
        });
    </script>

    <?php
    require_once('../include/sweetAlert.php');
    if(isset($_SESSION['status'])){
        $status = $_SESSION['status'];
        $msg = $_SESSION['msg'];
        if($status != true){
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





