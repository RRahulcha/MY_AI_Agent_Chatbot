<?php
session_start();
if (!isset($_SESSION['userId']) && empty($_SESSION['userId'])) {
    // header('location:login');
    // die();
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}
if ($_SESSION['userRole'] !== 'admin') {
    // Display alert and then redirect
    echo "<script>
        alert('You cannot open this page. Please log in with the correct role.');
        window.location.href = 'login.php';
    </script>";
    exit(); // Ensure the script stops execution after the redirect
}
include('../include/db_config.php');

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>Active Interns Report</title>
    <?php
    include('../partials/_links.php')
    ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/4.5.2/css/bootstrap.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap4.min.css">
    <style>
.details-row .card {
    background-color: #f8f9fa;
    border: 1px solid #ddd;
    border-radius: 8px;
}

.details-row .card-body {
    padding: 15px;
    font-family: 'Segoe UI', sans-serif;
    font-size: 14px;
}

.details-row .detail-line {
    display: flex;
    justify-content: flex-start;
    gap: 15px;
    border-bottom: 1px solid #eee;
    padding: 5px 0;
}

.details-row strong {
    min-width: 160px; /* aligns keys neatly */
    color: #003366;
    font-weight: 600;
}

.details-row span {
    color: #333;
}


.details-row .card-body {
    padding: 15px;
}



td.details-control {
  background: url('https://www.datatables.net/examples/resources/details_open.png') no-repeat center center;
  cursor: pointer;
}
tr.shown td.details-control {
  background: url('https://www.datatables.net/examples/resources/details_close.png') no-repeat center center;
}

        .star {
            font-size: 18px;
            color: red;
        }
        .btn.btn-icon{
            padding: 5px;
        }
        
        .btn i {
            font-size: 20px; /* Adjust icon size */
        }

        .btn:hover i {
            font-size: 30px;
        }


        #employee_data {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    background: #fff;
    border-radius: 8px;
    overflow: hidden;
    font-family: 'Segoe UI', sans-serif;
    font-size: 14px;
}


/* #reportTable {
    width: 100%;
    border-collapse: collapse; 
    background: #fff;
    font-family: 'Segoe UI', sans-serif;
    font-size: 14px;
    border: 1px solid #ddd; outer border
} */

/* Table header style */
#employee_data thead {
    background-color: #103d9eff;
    color: #ffffffff;
}

#employee_data thead th {
    padding: 12px 15px;
    font-weight: 600;
    text-align: left;
    border: 1px solid #ddd; /* header cell border */
    white-space: nowrap;
    padding-right: 30px !important;
}

#employee_data tbody tr:nth-child(odd) {
     background-color: #f1f5ff;
}


#employee_data tbody tr:hover {
    background-color: #dce4f8ff;
}

/* Table body cells */
#employee_data tbody td {
    padding: 10px 15px;
    border: 1px solid #ddd; /* cell border */
    vertical-align: middle;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.swal2-icon {
    margin: 0 auto !important;   
}




@media (max-width:768px) {
    .table thead{
        display: none;
    }
    .table, .table tbody, .table tr, .table td{
        display: block;
        width:100%;
    }
    .table tr{
        margin: bottom 15px;
    }
    .table tbody tr td{
        text-align:rigth;
        padding: left 50%;
        position: relative;
    }
    .table td:before{
        content:attr(data-label);
        position:absolute;
        left:0;
        width:50%;
        padding-left: 15px;
        font-weight:600;
        font-size:14px;
        text-align:left;

    }
    
}

td .toggle-details {
    padding: 0;
    margin-left: 5px;
    font-size: 14px;
    color: #007bff;
    text-decoration: none;
}

td .toggle-details:hover {
    color: #0056b3;
    text-decoration: none;
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
                        }
                        if (!empty($intern)) {
                            $intern_filter = " AND attendance.userId='$intern' ";
                        }
                        
                        $mode = $_REQUEST['type'];
                        if($mode==""){
                            $mode = $_SESSION['int_type'];
                        }
                        //  if (!empty($mode)) {
                        //     $mode = "type = '$mode'";
                        // }
                      
?>
                        <div class="col-lg-12 grid-margin stretch-card">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title" >Active Interns Report</h4>
                                    <div class="d-flex justify-content-end">
                                            <a href="adminDashboard.php"> <button style="margin-bottom:7px" class="btn btn-primary" name="back" id="back">Back</button>
                                            </a>
                                        </div>
                                    
                                    <form action="active-interns.php" method="POST" id="myForm">
    <div class="row">
        <div class="col-md-4">
            <label>Mode of Internship</label>
            <select class="js-example-basic-single w-100 remov" style="width:100%" id="type" name="type"data-placeholder="Select Mode of Internship">
                <option value="" selected="" disabled="">-- Select Mode --</option>

                <?php 
                $query1 = mysqli_query($con,"SELECT DISTINCT(type) FROM `signup` WHERE designation !='admin'");
                while($fetch = mysqli_fetch_assoc($query1)){
                    ?>
                    <option <?php if($mode==$fetch['type']){ echo "selected"; } ?> value="<?php echo $fetch['type']; ?>">
                        <?php echo $fetch['type']; ?>
                    </option>
                <?php
                }
                ?>
            </select>        
        </div>
        <div class="col-md-6" style="padding-top:30px;">
            <button type="submit" name="show" id="show" class="btn text-white" style="background-color:#0066CC; border-color:#0066CC;">
              Show
            </button>
            <a class="btn btn-outline-primary" style="font-weight:500;" href="active-interns.php">Reset</a> 
        </div>
    </div>
</form> 
 
                                    <div class="table-responsive mt-4">

                                        <table class="table" id="employee_data" style="width:100%">
                                            <thead>
                                            <tr>
                                            <!-- <th style="text-align: center;">Details</th>  -->
                                                <th style="text-align: center;">Sr.No.</th>
                                                <th style="text-align: center;">Intern Name</th>
                                                <th style="text-align: center;">Email</th>
                                                <!-- <th style="text-align: center;">Mobile Number</th> -->
                                                <th style="text-align: center;">Mode of Internship</th>
                                                <th style="text-align: center;">Office</th>
                                                <th style="text-align: center;">Created On</th>

                                                
                                                
                                                <th style="text-align: center;">Action</th>
                                                
                                                
                                            </tr>

                                            </thead>

                                            <tbody>
                                                <?php
                                                $id = 1;
                                                
                                        if(!empty($mode) && $mode != 'Reset'){
                                        
                                         $query = mysqli_query($con, "SELECT * FROM  signup WHERE type = '$mode' AND designation !='admin' and status = 'Active' ORDER BY CASE WHEN status = 'offline' THEN 1 WHEN status = 'online' THEN 2 ELSE 3 END, srNo DESC;; ") or die($con->error);
                                            
                                        }else{
                                            
                                             $query = mysqli_query($con, "SELECT * FROM signup WHERE designation !='admin' and status = 'Active' ORDER BY CASE WHEN status = 'offline' THEN 1 WHEN status = 'online' THEN 2 ELSE 3 END, srNo DESC;; ") or die($con->error);
                                        
                                            
                                        }
                                        $id = 1; // Initialize serial number
                                                while ($res = mysqli_fetch_assoc($query)) {
                                                    ?>
                                                    <tr>
                                                                                                
                                                    <td class="sr-no" style="text-align:center;">
                                                   
    <button class="btn btn-sm btn-link toggle-details"
        data-extra='<?= htmlspecialchars(json_encode([
            
            "Date of Birth" => date('d-m-Y', strtotime($res["dob"])),
            "Designation" => ucwords(preg_replace('/([a-z])([A-Z])/', '$1 $2', $res["designation"])),


            
            "Mobile Number" => $res["mobileNo"],
            "Username" => $res["username"],
            "Authority" => $res["authority"],
            "SL" => $res["SL"],
            "PL" => $res["PL"],
            "CL" => $res["CL"],
            "RO" => $res["RO"],
            "Working Hours" => $res["totalWorkingHours"],
            "Punch In" => $res["expectedPunchIn"],
            "Punch Out" => $res["expectedPunchOut"]
        ]), ENT_QUOTES) ?>'>
        ▼
    </button>
</td>

                                                        <td>
                                                            <?php echo $res['fullName'] ?>
                                                        </td>
                                                        <td>
                                                            <?php echo $res['email'] ?>
                                                        </td>
                                                        <!-- <td style="text-align: right;">
                                                            <?php echo $res['mobileNo'] ?>
                                                        </td> -->

                                                        <td>
                                                            <?php echo $res['type'] ?>
                                                        </td>
                                                        <td>
                                                            <?php echo $res['office'] ?>
                                                        </td>
                                                        
                                                        
                                                        <td>
                                                            <?php echo date('d-m-Y', strtotime($res['createdDate'])) ?>
                                                        </td>
                                                       
                                                        

                                                        <td style="text-align: center;">
    <!-- Edit button -->
    <a href="editProfile_Admin.php?userId=<?= $res['userId'] ?>" 
       class="btn btn-primary btn-sm">
       <i class="fa fa-edit fa-xs"></i> 
    </a>

    <!-- Delete button -->
    <button type="button" class="btn btn-danger btn-sm delete-btn" 
        data-userid="<?= $res['userId'] ?>" 
        data-fullname="<?= htmlspecialchars($res['fullName'], ENT_QUOTES) ?>">
        <i class="fa fa-trash fa-xs"></i>
    </button>
</td>

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

    <!-- plugins:js -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="../vendors/js/vendor.bundle.base.js"></script>
    <!-- endinject -->
    <!-- Plugin js for this page -->
    <script src="../vendors/chart.js/Chart.min.js"></script>
    
    <script src="../js/dataTables.select.min.js"></script>

    <!-- End plugin js for this page -->
    <!-- inject:js -->
    <script src="../js/off-canvas.js"></script>
    <script src="../js/hoverable-collapse.js"></script>
    <script src="../js/template.js"></script>
    <script src="../js/settings.js"></script>
    <script src="../js/todolist.js"></script>
    <script src="../vendors/select2/select2.min.js"></script>
    <script src="../js/select2.js"></script>



<!-- DataTables CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap4.min.css">

<!-- DataTables JS -->
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap4.min.js"></script>

    <script>

           // Handle Delete button
           $(document).on('click', '.delete-btn', function () {
     const userId = $(this).data('userid');
   const fullName = $(this).data('fullname');
     if (!userId) {
         Swal.fire('Error','Missing user ID','error');
         return;
     }

     Swal.fire({
         title: 'Are you sure?',
         text: `Delete intern: ${fullName}`,
         icon: 'warning',
         showCancelButton: true,
         confirmButtonColor: '#d33',
         cancelButtonColor: '#3085d6',
         confirmButtonText: 'Yes, delete'
     }).then((result) => {
         if (!result.isConfirmed) return;

         $.ajax({
             url: 'intern_actionn.php',
             method: 'POST',
             dataType: 'json',
             data: { action: 'delete', userId: userId },
             success: function (res) {
                 if (res.ok) {
                     Swal.fire('Deleted!', res.msg, 'success');

                     // Remove the row dynamically without reload
                     $(`.delete-btn[data-userid="${userId}"]`).closest('tr').fadeOut(500, function () {
                         $(this).remove();
                     });
                 } else {
                     Swal.fire('Error', res.msg || 'Failed to delete', 'error');
                 }
             },
             error: function (xhr) {
                 Swal.fire('Server Error', xhr.responseText || 'Try again', 'error');
             }
         });
     });
 });

 $(document).ready(function() {
     // 1. Initialize DataTable first
    //  var table = $('#employee_data').DataTable({
    //      "pageLength": 10
    //  });

     // 2. Handle toggle-details clicks
     $('#employee_data').on('click', '.toggle-details', function () {
         var tr = $(this).closest('tr');
         var row = table.row(tr);
         var btn = $(this);

         var extraData = $(this).attr('data-extra');
         try {
             extraData = JSON.parse(extraData);
         } catch(e) {
             console.error('Invalid JSON in data-extra', e);
            return;
         }

         if (row.child.isShown()) {
             row.child.hide();
             tr.removeClass('shown');
             btn.html('▼');
         } else {
             var html = `
                 <div class="details-row p-3">
                     <div class="card shadow-sm border">
                         <div class="card-body">
             `;

             // Display each key-value on a new line
             for (const [key, value] of Object.entries(extraData)) {
                html += `
                    <div class="detail-line mb-2">
                        <strong>${key}:</strong> <span>${value ? value : '-'}</span>
                    </div>
                 `;
             }

             html += `
                         </div>
                     </div>
                 </div>
            `;

             row.child(html).show();
            tr.addClass('shown');
            btn.html('▲');
        }
     });

     // 3. (Optional) Delete button handler or other logic can go here
 });


 if ( $.fn.DataTable.isDataTable('#employee_data') ) {
    $('#employee_data').DataTable().destroy();
}
var table = $('#employee_data').DataTable({
        "pageLength": 10,
        "order": [], // disable initial ordering
        "columnDefs": [
            {
                "targets": 0, // Sr.No column
                "orderable": false,
                "searchable": false
            }
        ],
        "rowCallback": function(row, data, index){
            // Add Sr.No before toggle button
            $('td:eq(0)', row).html((index + 1) + ' <button class="btn btn-sm btn-link toggle-details" data-extra=\''+$(row).find('.toggle-details').attr('data-extra')+'\'>▼</button>');
        }
    });





</script>



    <!-- endinject -->
    <!-- Custom js for this page-->
   <script src="../js/dashboard.js?v=1.0.1"></script>
    <script src="../js/Chart.roundedBarCharts.js"></script>
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
    
</body>

</html>