<?php
session_start();
if(!isset($_SESSION['userId']) && empty($_SESSION['userId'])){
    // header('location:login');
    // die();
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}
$momRole  = strtolower(str_replace([' ', '_'], '', $_SESSION['userRole'] ?? ''));
$momDesig = strtolower(str_replace([' ', '_'], '', $_SESSION['designation'] ?? ''));
$momAllowed = ['seniorintern', 'resourceallocator', 'resourcehead', 'resourcemanager', 'admin', 'superadmin', 'hr', 'teamlead'];
if(!in_array($momRole, $momAllowed) && !in_array($momDesig, $momAllowed)) {
    echo '<script>
        alert("You are not authorized to access this page.");
        window.location.href = "login.php";
        </script>';
    die();
}
include('../include/db_config.php');
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>MOM - Entry</title>
    <?php
    include('../partials/_links.php')
    ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
    <style>
        .btn-secondary {
            color: white;
        }

        #cancelBtn {
            display: none;
        }
    </style>
    <style>
        .select2-container .select2-selection--single {
            box-sizing: border-box;
            cursor: pointer;
            padding: 1%;
            display: block;
            height: 37px;
            user-select: none;
            -webkit-user-select: none;
        }

        .select2-container--default .select2-selection--multiple .select2-selection__choice {
            font-size: 1rem;
        }

        .task {
            display: none;
        }

        .required {
            color: red;
        }
    </style>
    <!--<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>-->
    <script>
        $(document).ready(function () {
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
                                    <h4 class="card-title" id="pageTitle">MOM Of Meeting</h4>

                                    <form class="forms-sample" action="mom_db.php" method="POST"
                                        enctype="multipart/form-data">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="row">
                                                    
                                                    <div class="col-md-4">
                                                        <div class="form-group">
                                                            <label for="fullName">Meeting Called By</label>
                                                            <input type="text" class="form-control" name="meeting_called_by" id="fullName">
                                                        </div>
                                                    </div>
                                                
                                                    <div class="col-md-4">
                                                        <div class="form-group">
                                                            <label for="fullName">Date</label>
                                                            <input type="date" class="form-control" name="date" id="fullName">
                                                        </div>
                                                    </div>
                                                    
                                                    <div class="col-md-4">
                                                        <div class="form-group">
                                                            <label for="fullName">Time</label>
                                                            <input type="time" class="form-control" name="time" id="fullName">
                                                        </div>
                                                    </div>
                                                    
                                                <div class="form-group col-md-4">
                                                    <label>Project</label>
                                                    <select class="js-example-basic-single w-100" required name="projectId">
                                                        <option selected="" disabled="" value="">-- Select Project --</option>
                                                        <?php
                                                            $q = mysqli_query($con, "SELECT projectName, projectId FROM projectMaster");
                                                            while($r = mysqli_fetch_assoc($q)){
                                                            ?>
                                                            <option value="<?php echo $r['projectId'] ?>"><?php echo $r['projectName'] ?></option>
                                                            <?php
                                                            }
                                                            ?>
                                                            <option value="Other">Other</option>
                                                    </select>
                                                </div>  
                                                
                                               <div class="form-group col-md-4">
                                                    <label>Meeting Type</label>
                                                    <select class="js-example-basic-single w-100" required name="meeting_type">
                                                        <option selected="" disabled="" value="">-- Select Type --</option>
                                                        <option>Casual</option>
                                                        <option>Formal</option>
                                                    </select>
                                                </div> 
                                                    
                                                    <div class="col-md-4">
                                                        
<div class="form-group">
    <label for="fullName">Meeting Mode</label>
    <div class="row">
<div class="col-sm-6 pr-0">
<div class="form-check">
<label class="form-check-label">
<input type="radio" class="form-check-input" name="mode" id="membershipRadios1" value="online" checked="">
Online
<i class="input-helper"></i></label>
</div>
</div>
<div class="col-sm-6 pr-0">
<div class="form-check">
<label class="form-check-label">
<input type="radio" class="form-check-input" name="mode" id="membershipRadios2" value="offline" checked="">
Offline
<i class="input-helper"></i></label>
</div>
</div>
</div>
</div>
</div>                                                    
                                                  <div class="col-md-12">
                                                        <div class="form-group">
                                                            <label for="fullName">Agenda</label>
                                                            <input type="text" class="form-control" name="agenda" id="fullName">
                                                        </div>
                                                    </div>
                                                    
                                                    <div class="col-md-6">
                                                     <div class="form-group">
                    <label>Attendies</label>
                    <select class="js-example-basic-multiple w-100" name="attendies[]" multiple="multiple" style="width:100%">
                      <?php 
                        $query = mysqli_query($con,"SELECT * FROM signup where userRole != 'admin'");
                        while($fetch = mysqli_fetch_assoc($query)){
                            ?>
                            
                            <option value="<?php echo $fetch['userId']; ?>"><?php echo $fetch['fullName']; ?></option>
                            
                            <?php
                        }
                        ?>
                    </select>
                  </div>
                  </div>                         
                                                    <div class="col-md-6">
                                                    
                                                     <div class="form-group">
                    <label>Apologies</label>
                    <select class="js-example-basic-multiple w-100" name = "apologies[]" multiple="multiple" style="width:100%">
                      <?php 
                        $query = mysqli_query($con,"SELECT * FROM signup where userRole != 'admin'");
                        while($fetch = mysqli_fetch_assoc($query)){
                            ?>
                            
                            <option value="<?php echo $fetch['userId']; ?>"><?php echo $fetch['fullName']; ?></option>
                            
                            <?php
                        }
                        ?>
                    </select>
                  </div>
                                                             </div>

                                                            <div class="col-md-12 input-field">
                                                                <div class="row" id="table_field">
                                                    <div class="col-10">
                                                        <div class="form-group">
                                                            <label for="fullName">Key Points</label>
                                                            <input type="text" class="form-control" name="key_points[]" id="key" value="">
                                                        </div>
                                                    </div>
                                                    <div class="col-2">
                                                        <buttton type="button" name="add" id="add" class="btn btn-success" style="float:right;margin-top:30px;"><i class="fa fa-plus"></i></button>
                                                    </div>
                                                    </div>
                                                    </div>
                            
                                                </div>
                                                <button type="submit" id="submitBtn" class="btn btn-primary mr-2"
                                                    name="submit">Submit</button>
                                            </div>
                                            <div class="col-md-6">
                                                <center>
                                                    <img src="image.png" style="width:90%; height:200;">
                                                </center>
                                            </div>

                                        </div>

                                        <a href="projectMaster" id="cancelBtn">
                                            <button type="button" class="btn btn-dark">Cancel</button>
                                        </a>
                                    </form>
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
    <script src="../js/off-canvas.js"></script>
    <script src="../js/hoverable-collapse.js"></script>
    <script src="../js/template.js"></script>
    <script src="../js/settings.js"></script>
    <script src="../js/select2.js"></script>
    <script src="../js/file-upload.js"></script>

    <!-- Date picker script -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <!-- Date picker script end -->

    <!-- This page script -->

    <!-- This page script end -->

    <!-- Data Table Script Link -->
    <script src="../vendors/datatables.net/jquery.dataTables.js"></script>
    <script src="../vendors/datatables.net-bs4/dataTables.bootstrap4.js"></script>
    <!-- Data Table Script Link End -->

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



        $("#projectId").on("change", function () {
            var projectId = $("#projectId").val();

            $.ajax({
                type: 'POST',
                url: '../ajax/taskEntry',
                data: { projectId: projectId },
                success: function (result) {
                    console.log(result);
                    $("#task").html(result);
                }
            });
        });

        $(".remov option").each(function () {
            $(this).siblings('[value="' + this.value + '"]').remove();
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
  <script type="text/javascript">
            $(document).ready(function () {
                var html='<div class="col-md-12"><div class="form-group"><input style="width:82%;" type="text" class="form-control" name="key_points[]" id="key"><button type="button" name="remove" id="remove" class="btn btn-danger" style="float:right;margin-top: -38px;"><i class="fa fa-minus"></i></button></div>';

                var max = 100;
                var x = 1;

                $("#add").click(function () {
                    if (x < max) {
                        $("#table_field").append(html);
                        x++;
                    }
                });


                $("#table_field").on('click', '#remove', function () {
                    $(this).closest('div').remove();
                    x--;
                });


            });


        </script>


</html>