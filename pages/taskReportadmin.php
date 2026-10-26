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
                        <div class="col-lg-12 grid-margin stretch-card">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title">My Task</h4>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Full Name</th>
                                                    <th>Task</th>
                                                    <th>Project</th>
                                                    <th>Status</th>
                                                    <th>Expected Deadline</th>
                                                    <th>Man Hours</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                $sum = strtotime('00:00:00');
                                                $query = mysqli_query($con, "SELECT DISTINCT(`userId`), `id`, `taskId`, `task`, `projectId`, `expectedDeadline`, `startTime`, `endTime`, `manHours`,  `dateTime` FROM taskEntry GROUP BY userId ORDER BY dateTime DESC");
                                                while($result = mysqli_fetch_assoc($query)){
                                                    $q = mysqli_query($con, "SELECT * FROM taskStatus WHERE taskId = '{$result['taskId']}' ORDER BY id ASC");
                                                    while($r = mysqli_fetch_assoc($q)){
                                                        $timeinsec = strtotime($r['manHours']) - $sum;
                                                        $totalTime = $totalTime + $timeinsec;
                                                        if($r['status'] == "resumed"){
                                                            $status = "Running";
                                                        } else {
                                                            $status = $r['status']."ed";
                                                        }
                                                    }
                                                    $h = intval($totalTime / 3600);
                                                    $totalTime = $totalTime - ($h * 3600);
                                                    $m = intval($totalTime / 60);
                                                    $s = $totalTime - ($m * 60);
                                                    $manHours = $h.":".$m.":".$s;
                                                    
                                                    $q2 = mysqli_query($con, "SELECT * FROM signup WHERE userId = '{$result['userId']}'");
                                                    $r2 = mysqli_fetch_assoc($q2);
                                                    $q3 = mysqli_query($con, "SELECT * FROM projectMaster WHERE projectId = '{$result['projectId']}'");
                                                    $r3 = mysqli_fetch_assoc($q3);
                                                ?>
                                                <tr>
                                                    <td>
                                                        <?php echo $r2['fullName'] ?>
                                                    </td>
                                                    <td>
                                                        <?php echo $result['task'] ?>
                                                    </td>
                                                    <td>
                                                        <?php echo $r3['projectName'] ?>
                                                    </td>
                                                    <td>
                                                        <?php echo $status ?>
                                                    </td>
                                                    <td>
                                                        <?php echo $result['expectedDeadline'] ?>
                                                    </td>
                                                    <td>
                                                        <?php echo $manHours ?>
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

    <script src="../vendors/js/vendor.bundle.base.js"></script>
    <script src="../js/off-canvas.js"></script>
    <script src="../js/hoverable-collapse.js"></script>
    <script src="../js/template.js"></script>
    <script src="../js/settings.js"></script>
    <script src="../js/todolist.js"></script>
    
    <?php
    include('../include/sweetAlert.php');
    if(isset($_SESSION['status']) && !empty($_SESSION['status'])){
        if($_SESSION['status'] == true){
            toastalertsuccess('top-end','', $_SESSION['msg']);
        } else if($_SESSION['status'] == false){
            toastalerterror('top-end','', $_SESSION['msg']);
        }
        unset($_SESSION['status']);
        unset($_SESSION['msg']);
    }
    ?>
</body>

</html>