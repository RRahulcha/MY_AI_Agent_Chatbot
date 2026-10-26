<?php
session_start();
if (!isset($_SESSION['userId']) && empty($_SESSION['userId'])) {
    // header('location:login');
    // die();
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}
include('../include/db_config.php');
 $type = $_SESSION['int_type'];
if($type == "Reset") {
    $types = "";
} else {
   $types = " AND type='$type'";  
}

if(!empty($_POST['types'])){
    $types = " AND type='{$_POST['types']}'";  
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>Attendance Report</title>
    <?php
    include('../partials/_links.php');
    ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/4.5.2/css/bootstrap.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap4.min.css">
    
    <style>
        .star {
            font-size: 18px;
            color: red;
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
                        <?php

                        $from = $_REQUEST['from'];

                        $to = $_REQUEST['to'];
                         
                        $intern = $_REQUEST['intern'];
                        if (!empty($_REQUEST['from']) && !empty($_REQUEST['to'])) {
                            $condition = " AND date between '$from' AND '$to' ";
                            $condition2 = " AND from_date between '$from' AND '$to' ";
                        }
                        if (!empty($intern)) {
//                            $intern_filter = " AND attendance.userId='$intern' ";
                            $intern_filter = " userId='$intern' AND ";
                            
                        }
?>
                        <div class="col-lg-12 grid-margin stretch-card">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title">Attendance Report</h4>
                                    <form action="seed_att_report.php" method="POST">
                                        <div class="row">
                                            <div class="col-md-4">

                                                <label>From Date</label>
                                                <input type="date" class="form-control" name="from" id="date" placeholder="FormDate" required="" value="<?php echo $from ?>">

                                            </div>

                                            <div class="col-md-4">
                                                <label>To Date</label>
                                                <input type="date" class="form-control" name="to" id="dateTime" placeholder="To Date" required="" value="<?php echo $to ?>">

                                            </div>
                                            
                                            <div class="col-md-2" style="padding-top:32px;">
                                                <button type="submit" name="show" id="show" class="btn btn-primary">Show</button>



                                                <a onclick="ExportToExcel('xlsx')" class="btn btn-warning">Export</a>
                                            </div>

                                        </div>
                                    </form>
                                    <br>
                                <?php
                                if(isset($_REQUEST['show'])){
                                    $start_date=$_REQUEST['from'];
                                    $end_date=$_REQUEST['to'];
                                ?>
                                    <div class="table-responsive">

                                        <table class="table table-striped table-bordered" id="employee_data" style="width:100%">
                                            <thead>
                                                <tr>
                                                    <th>Sr.No.</th>
                                                    <th>Intern Name</th>
                                                    <th>Punch in time</th>
                                                    <th>Punch out time</th>
                                                    <th>Date</th>
                                                    <th>Working Hours</th>
                                                    <th>OT Working Hours</th>
                                                    <th>Late Mark</th>
                                                    <!--<th>Updated To Senior?</th>-->
                                                </tr>
                                            </thead>

                                            <tbody>
                                                <?php
                                                $id=1;
                                                
                                               while ($start_date <= $end_date) {
                                                   
                                                   $date = date('Y-m-d', strtotime($start_date));

                                                   //$query_fulName = mysqli_query($con,"SELECT * FROM `signup` WHERE $intern_filter status = 'Active' $types AND department = 'SEED-Batch_3'"); 
                                                   $query_fulName = mysqli_query($con,"SELECT * FROM `signup` WHERE status = 'Active' $types AND department = 'SEED-Batch_4'"); 
                                                   while ($fetch_fullName = mysqli_fetch_assoc($query_fulName)) { 
                                                   $atte = mysqli_query($con,"SELECT * FROM `attendance` WHERE userId='{$fetch_fullName['userId']}' AND date='$date'  LIMIT 1");
                                                   if(mysqli_num_rows($atte)>0){
                                                           
                                                ?>
                                                    <tr>

                                                        <td>
                                                            <?php echo $id++ ?>
                                                        </td>
                                                        <td>
                                                            <?php echo $fetch_fullName['fullName'] ?>
                                                        </td>
                                                       <?php
                                                       
                                                           $res = mysqli_fetch_assoc($atte);
                                                           
                                                         $punch_time  =  $res['punchInTime'];
                                                          $out_time   =  $res['punchOutTime'];
                                                           $man_hours =  $res['manHours'];
                                                           $ot_manhours = $res['OTManHour'];
//                                                           $date1=$res['date'];
                                                               if($res['lateMark']=="yes"){
                                                                $startTime = new DateTime("10:35:00");
                                                                $endTime = new DateTime( $res['punchInTime']);
                                                                $interval = $startTime->diff($endTime);
                                                                
                                                                // Format the interval to a string
                                                                $lateDuration = $interval->format('%H:%I:%S');
                                                               } else {
                                                                   $lateDuration = "-";
                                                               }
                                                           ?>
                                                          
                                                        <td>
                                                            <?php echo $punch_time; ?>
                                                        </td>
                                                        <td>
                                                            <?php echo $out_time; ?>
                                                        </td>
<!--
                                                        <td>
                                                            <?php echo $date; ?>
                                                        </td>
-->
                                                        <td><?php 
                                                          echo $date; ?>
                                                        </td>

                                                        <td>
                                                            <?php echo $man_hours; ?>
                                                        </td>
                                                        <td>
                                                            <?php echo $ot_manhours; ?>
                                                        </td>
                                                        <td>
                                                            <?php echo $lateDuration;  ?>
                                                         
                                                        </td>


                                                           <!--<td>
                                                            <?php 
                                                            if($res['updatedToSenior']!=""){
                                                                $res['updatedToSenior'];
                                                                
                                                            } else {
                                                                echo "--";
                                                                
                                                            }
                                                            ?>
                                                        </td>-->
                                                           <?php
                                                           
                                                        /*else{
                                                           
                                                            $atte = mysqli_query($con,"SELECT * FROM `leaves` WHERE userId='{$fetch_fullName['userId']}' from_date='$date' $intern_filter LIMIT 1");
                                                          if(mysqli_num_rows($atte)>0){
                                                              $punch_time  =  "ON Leave";
                                                            $out_time  =  "ON Leave";
                                                              $man_hours = "ON Leave";
                                                               $ot_manhours = "ON Leave";
                                                              $lateDuration = "ON LEAVE";
//                                                              $date=$res['date'];
                                                              
                                                          } else {
                                                              
                                                                 $punch_time  =  "Absent";
                                                                 $out_time  =  "Absent";
                                                               $man_hours = "Absent";
                                                               $ot_manhours = "Absent";
                                                               $lateDuration = "Absent";
//                                                              $date=date_create($res['date']);
                                                              
                                                          }
                                                           
                                                       }*/
                                                       }
                                                   ?>

                                                    </tr>
                                                <?php
                                                       
                                                   }
                                                   
                                                   $start_date = date('Y-m-d', strtotime("+1 day", strtotime($start_date)));
                                                   
                                                }
                                
                                                ?>
                                            </tbody>
                                            
                                        </table>
                                       
                                    </div>
                                <?php } ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script>
    function fetch_data(){

        var adm = document.getElementById('projectId2').value;
$.ajax('update_session.php', {
    type: 'POST',  // http method
    data: { adm: adm },  // data to submit
    success: function (data) {
       
        document.getElementById('empId').innerHTML = data;

        
   
    }
});

}
        window.onload=fetch_data;
    </script>
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
   <script src="../js/dashboard.js?v=1.0.1"></script>
    <script src="../js/Chart.roundedBarCharts.js"></script>
    <script src="../vendors/select2/select2.min.js"></script>
    <script src="../js/select2.js"></script>    
    <!-- End custom js for this page-->


    <?php
    require_once('../include/sweetAlert.php');
    if (isset($_SESSION['msg'])) {
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
    <script>
        $(document).ready(function() {
            $('#employee_data').DataTable();
        });
    </script>
 <table class="" id="employee_data1" style="display :none">
                                            <thead>
                                                <tr>
                                                    <th>Sr.No.</th>
                                                    <th>Intern Name</th>
                                                    <th>Punch in time</th>
                                                    <th>Punch out time</th>
                                                    <th>Date</th>
                                                    <th>Working Hours</th>
                                                    <th>OT Working Hours</th>
                                                    <th>Late Mark</th>
                                                    <!--<th>Updated To Senior?</th>-->
                                                </tr>
                                            </thead>

                                            <tbody>
                                                <?php
                                                $id=1;
                                                $start_date=$_REQUEST['from'];
                                               while ($start_date <= $end_date) {
                                                   
                                                   $date = date('Y-m-d', strtotime($start_date));

                                                   //$query_fulName = mysqli_query($con,"SELECT * FROM `signup` WHERE $intern_filter status = 'Active' $types AND department = 'SEED-Batch_3'"); 
                                                   $query_fulName = mysqli_query($con,"SELECT * FROM `signup` WHERE status = 'Active' $types AND department = 'SEED-Batch_4'"); 
                                                   while ($fetch_fullName = mysqli_fetch_assoc($query_fulName)) { 
                                                   $atte = mysqli_query($con,"SELECT * FROM `attendance` WHERE userId='{$fetch_fullName['userId']}' AND date='$date'  LIMIT 1");
                                                   if(mysqli_num_rows($atte)>0){
                                                           
                                                ?>
                                                    <tr>

                                                        <td>
                                                            <?php echo $id++ ?>
                                                        </td>
                                                        <td>
                                                            <?php echo $fetch_fullName['fullName'] ?>
                                                        </td>
                                                       <?php
                                                       
                                                           $res = mysqli_fetch_assoc($atte);
                                                           
                                                         $punch_time  =  $res['punchInTime'];
                                                          $out_time   =  $res['punchOutTime'];
                                                           $man_hours =  $res['manHours'];
                                                           $ot_manhours = $res['OTManHour'];
//                                                           $date1=$res['date'];
                                                               if($res['lateMark']=="yes"){
                                                                $startTime = new DateTime("10:35:00");
                                                                $endTime = new DateTime( $res['punchInTime']);
                                                                $interval = $startTime->diff($endTime);
                                                                
                                                                // Format the interval to a string
                                                                $lateDuration = $interval->format('%H:%I:%S');
                                                               } else {
                                                                   $lateDuration = "-";
                                                               }
                                                           ?>
                                                          
                                                        <td>
                                                            <?php echo $punch_time; ?>
                                                        </td>
                                                        <td>
                                                            <?php echo $out_time; ?>
                                                        </td>
<!--
                                                        <td>
                                                            <?php echo $date; ?>
                                                        </td>
-->
                                                        <td><?php 
                                                          echo $date; ?>
                                                        </td>

                                                        <td>
                                                            <?php echo $man_hours; ?>
                                                        </td>
                                                        <td>
                                                            <?php echo $ot_manhours; ?>
                                                        </td>
                                                        <td>
                                                            <?php echo $lateDuration;  ?>
                                                         
                                                        </td>


                                                           <!--<td>
                                                            <?php 
                                                            if($res['updatedToSenior']!=""){
                                                                $res['updatedToSenior'];
                                                                
                                                            } else {
                                                                echo "--";
                                                                
                                                            }
                                                            ?>
                                                        </td>-->
                                                           <?php
                                                           
                                                        /*else{
                                                           
                                                            $atte = mysqli_query($con,"SELECT * FROM `leaves` WHERE userId='{$fetch_fullName['userId']}' from_date='$date' $intern_filter LIMIT 1");
                                                          if(mysqli_num_rows($atte)>0){
                                                              $punch_time  =  "ON Leave";
                                                            $out_time  =  "ON Leave";
                                                              $man_hours = "ON Leave";
                                                               $ot_manhours = "ON Leave";
                                                              $lateDuration = "ON LEAVE";
//                                                              $date=$res['date'];
                                                              
                                                          } else {
                                                              
                                                                 $punch_time  =  "Absent";
                                                                 $out_time  =  "Absent";
                                                               $man_hours = "Absent";
                                                               $ot_manhours = "Absent";
                                                               $lateDuration = "Absent";
//                                                              $date=date_create($res['date']);
                                                              
                                                          }
                                                           
                                                       }*/
                                                       }
                                                   ?>

                                                    </tr>
                                                <?php
                                                       
                                                   }
                                                   
                                                   $start_date = date('Y-m-d', strtotime("+1 day", strtotime($start_date)));
                                                   
                                                }
                                
                                                ?>
                                            </tbody>
                                            
                                        </table>
<script type="text/javascript" src="https://unpkg.com/xlsx@0.15.1/dist/xlsx.full.min.js"></script>
	<script>
	
	function ExportToExcel(type, fn, dl) {
       var elt = document.getElementById('employee_data1');
       var wb = XLSX.utils.table_to_book(elt, { sheet: "sheet1" });
       return dl ?
         XLSX.write(wb, { bookType: type, bookSST: true, type: 'base64' }):
         XLSX.writeFile(wb, fn || ('Attendance Report.' + (type || 'xlsx')));
    }
	</script>

</body>

</html>