<?php
session_start();
require_once('../include/db_config.php');

$queryForCheck = mysqli_query($con, "SELECT type FROM signup WHERE userId = '{$_SESSION['userId']}'");
$data = mysqli_fetch_assoc($queryForCheck);
$type = $data['type'];

$punch = isset($_GET['punch']) ? $_GET['punch'] : '';

// ✅ Updated logic:
// फक्त "Online" (Senior Employee) युजरना attendance.php वर redirect करा.
// "Junior" युजरना इथेच राहू द्या — म्हणजे QR स्कॅन उघडेल.
if ($type == "Online") {
    echo "<script>window.location.href = 'captureSelfie.php?punch={$punch}';</script>";
    exit;
}

$today = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php
    include('../partials/_links.php')
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

    #qr-shaded-region,
    video {
        border-radius: 20px;
    }

    .tit {
        font-size: 11px;
        margin-bottom: 0.5rem;
        line-height: 1rem;
    }
</style>

<body>
    <div class="container-scroller">
        <?php include('../partials/_navbar.php'); ?>
        <div class="container-fluid page-body-wrapper">
            <?php include('../partials/_sidebar.php'); ?>

            <div class="main-panel">
                <div class="content-wrapper">
                    <div class="row">
                        <div class="col-md-12 grid-margin stretch-card">
                            <div class="card">
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-12">
                                            <div style="width: 100%;" id="reader"></div>
                                            <input type="hidden" id="punch" value="<?php echo $punch; ?>">
                                            <input type="hidden" id="updatedToSenior"
                                                value="<?php echo isset($_GET['updatedToSenior']) ? $_GET['updatedToSenior'] : ''; ?>">
                                        </div>
                                    </div>
                                    <h4 class="card-title heading mt-3 text-center">Scan the QR Code</h4>
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
    <script src="../js/dashboard.js?v=<?php echo time(); ?>"></script>
    <script src="../js/Chart.roundedBarCharts.js"></script>
    <!-- End custom js for this page-->

    <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
    <script src="../js/htmlQrScanner_copy.js?v=2.3"></script>

</body>

</html>