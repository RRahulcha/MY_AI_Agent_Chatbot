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
    <link rel="stylesheet" href="https://cdn.datatables.net/rowreorder/1.3.1/css/rowReorder.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.4.0/css/responsive.dataTables.min.css">
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

         <?php /*   <div class="main-panel">
                <div class="content-wrapper">
                    <div class="row">
                        <div class="col-12 grid-margin stretch-card">
                            <div class="card">
                                <div class="card-body"></div>
                                                
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
                        </div> */ ?>


                        <div class="col-lg-12 grid-margin stretch-card">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title">Allocated Task</h4>
                                    <div class="table-responsive">
                                        <?php


          
        
$sql = "SELECT * FROM leaves";

$result = mysqli_query($con,$sql) or die("query failed");
?>
                                        <table class="table table-striped table-bordered" style="width:100%" id="employee_data">
                                            <thead>
                                                <tr>
                                                    <th>Created Date</th>
                                                    <th>Leave ID : </th>
                                                     <th>Leave D</th>
                                                      <th>Leave type</th>
                                                       <th>Leave Day</th>
                                                        <th>From Date</th>
                                                         <th>To Date</th>
                                                         <th>Reason</th>
                                                         <th>Approved by</th>
                                                         <th>Inform Colleagues</th>
                                                         <th>User ID</th>
                                                         <th>Status</th>
                                                         
                                                </tr>
                                            </thead>

                     <tbody>
                                                                     <?php
while($res = mysqli_fetch_assoc($result))
{   
                     ?>
                         <tr>
                             <td><?php echo $res['created_date'] ?></td>
                              <td><?php echo $res['leave_id'] ?></td>
                               <td><?php echo $res['leave_d'] ?></td>
                               <td><?php echo $res['leave_type'] ?></td>
                               
                               <td><?php echo $res['leave_day'] ?></td>
                             <td><?php echo $res['from_date'] ?></td>
                             <td><?php echo $res['to_date'] ?></td>
                             <td><?php echo $res['reason'] ?></td>
                             <td><?php echo $res['approved_by'] ?></td>
                             <td><?php echo $res['inform_colleagues'] ?></td>
                             <td><?php echo $res['userId'] ?></td>
                             <td><?php echo $res['status'] ?></td>
                             

                              
                               
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
      

    <script src="../vendors/js/vendor.bundle.base.js"></script>
    <script src="../vendors/typeahead.js/typeahead.bundle.min.js"></script>
    <script src="../vendors/select2/select2.min.js"></script>
    <script src="../js/template.js"></script>
    <script src="../js/settings.js"></script>
    <script src="../js/typeahead.js"></script>
    <script src="../js/select2.js"></script>
    
    <script src="https://code.jquery.com/jquery-3.5.1.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap4.min.js"></script>
     
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