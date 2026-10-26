<?php
session_start();
require_once('../include/db_config.php');
$today = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php
    include('../partials/_links.php');
    ?>
</head>
<style>
    .heading {
        color: #1977e1 !important;
    }

    .charts-data .progress {
        background: #eee;
    }

    .progress.progress-md {
        height: 5px;
    }

    .border {
        border: 1px solid #eee !important;
        padding: 10px;
        border-radius: 10px;
    }
    #qr-shaded-region, video, .capture-frame{
        border-radius: 20px !important;
    }
    .tit {

        font-size: 11px;
        margin-bottom: 0.5rem;
        line-height: 1rem;
    }
    
    .capture-frame{
        transform: scale(-1, 1) !important;
    }
</style>

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
                        <div class="col-md-12 grid-margin stretch-card">
                            <div class="card">
                                <div class="card-head">
                                </div>
                                <div class="card-body">
                                    <h4 class="card-title">Capture selfie 🤳 </h4>
                                    <form action="attendance" method="POST" onsubmit="diabledFun();">
                                        <div class="row">
                                            <div class="col-md-12">
                                                <div id="capture-frame" class="capture-frame"></div>
                                                <input type="hidden" name="capturedDate" id="captured-date">
                                            </div>
                                            <div class="col-md-12" id="captureBtnDiv">
                                                <button class="btn btn-primary mt-3" type="button" onclick="captureFun()">Capture Image</button>
                                            </div>
                                            
                                            <div class="col-md-12" id="recaptureBtnDiv" style="display:none">
                                                <button class="btn btn-primary mt-3" type="button" onclick="resetWebCam()">Re-capture Image</button>
                                                <input type="hidden" name="punch<?php echo $_GET['punch'] ?>">
                                                
                                                <input type="hidden" name="punchType" id="punchType"  value="<?php echo $_GET['punch'] ?>">
                                                
                                                <input type="hidden" id="updatedToSenior" value="<?php echo $_GET['updatedToSenior'] ?>">
                                                <button class="btn btn-success mt-3" type="submit">Submit</button>
                                            </div>
                                        </div>
                                    </form>
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
    <script src="../js/dashboard.js"></script>
    <script src="../js/Chart.roundedBarCharts.js"></script>
    <!-- End custom js for this page-->
    
    <script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/webcamjs/1.0.24/webcam.js"></script>
    <script src="../js/imageCapture.js?v=1"></script>

</body>

</html>