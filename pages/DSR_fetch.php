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
    <title>SETTribe AMS - Task Allocation</title>
    <?php
    include('../partials/_links.php')
    ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/4.5.2/css/bootstrap.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap4.min.css">
    <style>
        .star{
            font-size:18px;
            color:red;
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
                    <?php /*    <div class="col-12 grid-margin stretch-card">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title">Task Allocation</h4>
                                    <form class="forms-sample" action="taskAllocation_db" method="POST" style="align-items: center;">
                                        <?php
                                        if(isset($_GET['edit'])){
                                            $id = $_GET['edit'];
                                            $q = mysqli_query($con, "SELECT taskId, id, task, projectId, userId, priority FROM taskEntry WHERE id = '$id'");
                                            $r = mysqli_fetch_assoc($q);
                                            $task = $r['task'];
                                            $project = $r['projectId'];
                                            $employee = $r['userId'];
                                            $priority = $r['priority'];
                                            
                                        } else {
                                            $multEmp = "multiple";
                                            $taskBtn = true;
                                        }
                                        ?>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label for="project">Project</label>
                                                    <select class="js-example-basic-single w-100" name="project" id="project">
                                                        <option value="" selected="" disabled="">-- Select Project --</option>
                                                        <?php
                                                        $q = mysqli_query($con, "SELECT projectName, projectId FROM projectMaster WHERE status = 1");
                                                        while($r = mysqli_fetch_assoc($q)){
                                                            if($project == $r['projectId']){
                                                                $selected = "selected";
                                                            } else {
                                                                $selected = "";
                                                            }
                                                        ?>
                                                        <option value="<?php echo $r['projectId'] ?>" <?php echo $selected ?>><?php echo $r['projectName'] ?></option>
                                                        <?php
                                                        }
                                                        ?>
                                                    </select>
                                                </div>
                                            </div>
                                            
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>Employees <span class="star">*</span></label>
                                                    <select class="js-example-basic-multiple w-100" <?php echo $multEmp ?> name="employees[]" required id="employees">
                                                        <?php
                                                        $q = mysqli_query($con, "SELECT fullName, userId FROM signup WHERE designation != 'admin' ");
                                                        while($r = mysqli_fetch_assoc($q)){
                                                            if($employee == $r['userId']){
                                                                $selected = "selected";
                                                            } else {
                                                                $selected = "";
                                                            }
                                                        ?>
                                                        <option value="<?php echo $r['userId'] ?>" <?php echo $selected ?>><?php echo $r['fullName'] ?></option>
                                                        <?php
                                                        }
                                                        ?>
                                                    </select>
                                                </div>    
                                            </div>
                                        </div>
                                        
                                        <div id="taskDiv">
                                            <div class="row" style="align-items: flex-end;">
                                                <div class="col-md-5">
                                                    <div class="form-group">
                                                        <label for="task">Task <span class="star">*</span></label>
                                                        <input type="text" class="form-control" id="task" value="<?php echo $task ?>" name="task[]" placeholder="Enter Task" required="">
                                                    </div>    
                                                </div>
                                                
                                                <div class="col-md-5">
                                                    <div class="form-group">
                                                        <label for="priority">Priority</label>
                                                        <select class="form-control" name="priority[]" id="priority">
                                                            <option selected="" disabled="" value="">-- Select Priority --</option>
                                                            <option value="High" <?php if($priority == "High"){ echo "selected"; } ?>>High</option>
                                                            <option value="Medium" <?php if($priority == "Medium"){ echo "selected"; } ?>>Medium</option>
                                                            <option value="Low" <?php if($priority == "Low"){ echo "selected"; } ?>>Low</option>
                                                        </select>
                                                    </div>    
                                                </div>
                                                
                                                <div class="col-md-2 mb-4">
                                                    <?php
                                                    if($taskBtn == true){
                                                    ?>
                                                    <button id="add" class="btn btn-success btn-rounded btn-icon" type="button" style="outline:none;">
                                                        <i class="mdi mdi-plus"></i>
                                                    </button>
        
                                                    <button class="btn btn-danger btn-rounded btn-icon" id="remove" type='button'>
                                                        <i class="mdi mdi-minus"></i>
                                                    </button>
                                                    <?php
                                                    }
                                                    ?>
                                                </div>
                                            </div>
                                        </div>
                                        <?php 
                                        if(isset($_GET['edit'])){
                                        ?>
                                        <button type="submit" class="btn btn-primary mr-2" name="update" value="<?php echo $_GET['edit'] ?>">Update</button>
                                        <a href="taskAllocation"><button class="btn btn-light" type="button">Cancel</button></a>
                                        <?php
                                        } else {
                                        ?>
                                        <button type="submit" class="btn btn-primary mr-2" name="allocate">Allocate</button>
                                        <?php
                                        }
                                        ?>
                                    </form>
                                </div>
                            </div>
                        </div> <?php */ ?>
                        


                        <div class="col-lg-12 grid-margin stretch-card">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title">Allocated Task</h4>
                                    <div class="table-responsive">
                                        <?php


          
        
$sql = "SELECT * FROM moms";

$result = mysqli_query($con,$sql) or die("query failed");
?>
                                        <table class="table table-striped table-bordered" id="employee_data" style="width:100%">
                                            <thead>
                                                <tr>
                                                    <th>Meeting Called By</th>
                                                    <th>Date & Time : </th>
                                                     <th>Meetingtype</th>
                                                      <th>Meetingmode</th>
                                                       <th>Agenda</th>
                                                        <th>Attendies</th>
                                                         <th>Apologies</th>
                                                         <th>KeyPoints</th>
                                                    
                                                   
                                                </tr>
                                            </thead>

                     <tbody>
                                                                     <?php
while($res = mysqli_fetch_assoc($result))
{   
                     ?>
                         <tr>
                             <td><?php echo $res['meeting_called_by'] ?></td>
                              <td><?php echo $res['date_time'] ?></td>
                               <td><?php echo $res['meeting_type'] ?></td>
                               <td><?php echo $res['mode'] ?></td>
                               
                               <td><?php echo $res['agenda'] ?></td>
                             <td><?php echo $res['attendies'] ?></td>
                             <td><?php echo $res['apologies'] ?></td>
                             <td><?php echo $res['key_points'] ?></td>
                              
                         </tr>
                         <?php
                         }
                         ?>
                     </tbody>
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
    <script src="../vendors/typeahead.js/typeahead.bundle.min.js"></script>
    <script src="../vendors/select2/select2.min.js"></script>
    <script src="../js/template.js"></script>
    <script src="../js/settings.js"></script>
    <script src="../js/typeahead.js"></script>
    <script src="../js/select2.js"></script>
    
    <script src="https://code.jquery.com/jquery-3.5.1.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js
"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap4.min.js
"></script>
    
    
    <!--<script>-->
    <!--    window.addEventListener('onbeforeunload', function (e) {-->
    <!--        status = document.getElementById("body").value;-->
    <!--        if (status == "running") {-->
    <!--            e.preventDefault();-->
    <!--            e.returnValue = '';-->
    <!--        }-->
    <!--    })-->
        
        
    <!--    $("#project").on('change', function() {-->
    <!--        let project = $(this).val();-->
    <!--        console.log(project);-->
    <!--        $.ajax({-->
    <!--            type : 'GET',-->
    <!--            url  : '../ajax/taskAllocation',-->
    <!--            data : {project : project},-->
    <!--            success : function (data){-->
    <!--                $("#employees").html(data);-->
    <!--            }-->
    <!--        })-->
    <!--    })-->
    
    <!--    $(document).ready(function on() {-->
    
    <!--        var count = 2;-->
    <!--        $("#remove").hide();-->
    <!--        $("#add").on("click", function on() {-->
    <!--            $("#taskDiv").append("<div class='row'><div class='col-md-5'><div class='form-group'><label for='task" + count + "'>Task <span class='star'>*</span></label><input class='form-control' id='task" + count + "' required type='text' placeholder='Enter Task' name='task[]'></div></div><div class='col-md-5'><div class='form-group'><label for='priority" + count + "'>Priority </label><select name='priority[]' class='form-control' id='priority" + count + "'><option selected disabled>-- Select Priority --</option><option value='High'>High</option><option value='Medium'>Medium</option><option value='Low'>Low</option></select></div></div></div>");-->
    <!--            count = count + 1;-->
    <!--            $("#remove").show();-->
    <!--        });-->
    <!--        $("#remove").on("click", function () {-->
    <!--            $("#taskDiv").children().last().remove();-->
    <!--            count = count - 1;-->
                
    <!--            if(count <=2){-->
    <!--                $("#remove").hide();-->
    <!--            }-->
    <!--        });-->
    <!--    });  -->
        
    <!--    $(document).ready(() => {-->
    <!--        let dataTable = $('#allocatedTaskTable').DataTable({-->
                // 'order': [[2, 'desc']],
    <!--            'processing': true,-->
    <!--            'serverSide': true,-->
    <!--            'serverMethod': 'post',-->
    <!--            'ajax': {-->
    <!--                'url':'../ajax/momReport?dataTable='-->
    <!--            },-->
    <!--            'responsive': true,-->
    <!--            'columns': [-->
    <!--                {data:'Meetingcalledby'},-->
    <!--                {data:'Date&Time'},-->
    <!--                {data:'Meetingtype'},-->
    <!--                {data:'Meetingmode'},-->
    <!--                {data:'Agenda'}-->
    <!--                {data:'Attendies'}-->
    <!--                {data:'Apologies'}-->
    <!--                {data:'KeyPoints'}-->
    <!--                {data:'userid'},-->
    <!--            ],-->
    <!--            'columnDefs': [-->
    <!--                { "orderable": false, "targets": [3,4] }-->
    <!--            ]-->
    <!--        });-->
            
    <!--        dataTable.draw();-->
    <!--    });-->
    <!--</script>-->

    <?php
    require_once('../include/sweetAlert.php');
    if(isset($_SESSION['msg'])){
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
    <script>  
 $(document).ready(function(){  
      $('#employee_data').DataTable();  
 });  
 </script> 
</body>

</html>