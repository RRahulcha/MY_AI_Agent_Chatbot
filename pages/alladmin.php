<!DOCTYPE html>
<html lang="en">
<head>
    <title>Task Allocation Report</title>
    <?php
    include('../partials/_links.php')
    ?>
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
            include('../partials/_settings-panel.php');
            ?>

            <div class="main-panel">
                <div class="content-wrapper">
                    <div class="row">
                        

                        <div class="col-lg-12 grid-margin stretch-card">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title">Task Allocation Report</h4>
                                    <div class="table-responsive">
                                        <table class="table table-hover" id="projectMasterTable">
                                            <thead>
                                                <tr>
                                                    <th>Sr.No</th>
                                                    <th>Name</th>
                                                    <th>Task</th>
                                                    <th>Project</th>
                                                    <th>From</th>
                                                    <th>Required Time</th>
                                                    <th>Allocated By</th>
                                                    <th>Status</th>
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


<script src="../vendors/typeahead.js/typeahead.bundle.min.js"></script>
<script src="../vendors/select2/select2.min.js"></script>


<script src="../js/off-canvas.js"></script>
<script src="../js/hoverable-collapse.js"></script>
<script src="../js/template.js"></script>
<script src="../js/settings.js"></script>
<script src="../js/todolist.js"></script>


<script src="../js/file-upload.js"></script>
<script src="../js/typeahead.js"></script>
<script src="../js/select2.js"></script>

    
    <!-- This page script -->
    <script src="../js/projectMaster.js"></script>
    <!-- This page script end -->
    
    <!-- Data Table Script Link -->
    <script src="../vendors/datatables.net/jquery.dataTables.js"></script>
    <script src="../vendors/datatables.net-bs4/dataTables.bootstrap4.js"></script>
    <!-- Data Table Script Link End -->
    <script>
        window.addEventListener('onbeforeunload', function (e) {
            status = document.getElementById("body").value;
            if (status == "running") {
                e.preventDefault();
                e.returnValue = '';
            }
        })
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