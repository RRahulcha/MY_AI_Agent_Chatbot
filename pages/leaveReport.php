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
    <?php
    include('../partials/_links.php')
    ?>
    <style>
        .select2-container .select2-selection--single {
            box-sizing: border-box;
            cursor: pointer;
            padding: 1%;
            display: block;
            height: 100%;
            user-select: none;
            -webkit-user-select: none;
        }
        .select2-container--default .select2-selection--multiple .select2-selection__choice{
            font-size: 1rem;
            padding: 0.25rem;
        }
        
        .swal2-modal .swal2-icon, .swal2-modal .swal2-success-ring
        {
            margin-bottom: 0px;
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
                                    <h4 class="card-title">Projects Allocation</h4>
                                    <form class="forms-sample" action="projectAllocate_db" method="POST">
                                        <?php
                                        if(isset($_GET['edit'])){
                                            $id = $_GET['edit'];
                                            $employee = array();
                                            $q = mysqli_query($con, "SELECT projectId, userId FROM allocatedProject WHERE projectId = '$id'");
                                            while($r = mysqli_fetch_assoc($q)){
                                                $projectId = $r['projectId'];
                                                $employees[] =  $r['userId'];
                                            }
                                            $projectSql = "SELECT projectId, projectName FROM projectMaster WHERE status = 1";
                                        } else {
                                            $projectSql = "SELECT DISTINCT (projectMaster.projectId), projectMaster.projectName FROM projectMaster  JOIN allocatedProject ON projectMaster.projectId != allocatedProject.projectId WHERE projectMaster.status = 1;";
                                        }
                                        ?>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>Project</label>
                                                    <select class="js-example-basic-single w-100" required name="project">
                                                        <option selected="" disabled="" value="">-- Select Project --</option>
                                                        <?php 
                                                        $q = mysqli_query($con, $projectSql);
                                                        while($r = mysqli_fetch_assoc($q)){
                                                            if($projectId == $r['projectId']){
                                                                $selected = "selected";
                                                            } else {
                                                                $selected = "";
                                                            }
                                                        ?>
                                                        <option value="<?php echo $r['projectId'] ?>" <?php echo $selected; ?>><?php echo $r['projectName'] ?></option>
                                                        <?php
                                                        }
                                                        ?>
                                                    </select>
                                                </div>    
                                            </div>
                                            
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>Employee</label>
                                                    <select class="js-example-basic-multiple w-100" required multiple="multiple" name="employee[]">
                                                        <?php
                                                        $q = mysqli_query($con, "SELECT fullName, userId FROM signup WHERE designation != 'admin' ");
                                                        while($r = mysqli_fetch_assoc($q)){
                                                            if(in_array($r['userId'], $employees)){
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
                                        <?php
                                        if(isset($_GET['edit'])){
                                        ?>
                                        <button type="submit" class="btn btn-primary mr-2" name="update" value="<?php echo $_GET['edit'] ?>">Update</button>
                                        <a href="projectAllocation"><button class="btn btn-light" type="button">Cancel</button></a>
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
                        </div>
                        
                        
                        <div class="col-lg-12 grid-margin stretch-card">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title">Allocated Projects</h4>
                                    <div class="table-responsive">
                                        <table class="table table-hover" id="allocatedProjectTable">
                                            <thead>
                                                <tr>
                                                    <th>Project</th>
                                                    <th>Employees</th>
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
    <script src="../js/hoverable-collapse.js"></script>
    <script src="../js/template.js"></script>
    <script src="../js/settings.js"></script>
    
    <script src="../vendors/select2/select2.min.js"></script>
    <script src="../js/select2.js"></script>
    
    <!-- This page script -->
    <script src="../js/projectMaster.js"></script>
    <!-- This page script end -->
    
    <!-- Data Table Script Link -->
    <script src="../vendors/datatables.net/jquery.dataTables.js"></script>
    <script src="../vendors/datatables.net-bs4/dataTables.bootstrap4.js"></script>
    <!-- Data Table Script Link End -->
    
    <script>
        /*var status = "<?php echo status2 ?>";
        function fun(status){
            var status = status;
        }*/
        window.addEventListener('onbeforeunload', function (e) {
            status = document.getElementById("body").value;
            if(status == "running"){
                e.preventDefault();
				e.returnValue = '';
            }
        })
        
        
        $(document).ready(() => {
            let dataTable = $('#allocatedProjectTable').DataTable({
                // 'order': [[0, 'desc']],
                'processing': true,
                'serverSide': true,
                'serverMethod': 'post',
                'ajax': {
                    'url':'../ajax/projectAllocation?dataTable='
                },
                'responsive': true,
                'columns': [
                    {data:'projectName'},
                    {data:'fullName'},
                    {data:'action'},
                ],
                'columnDefs': [
                    { "orderable": false, "targets": [2] }
                ]
            });
            
            dataTable.draw();
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