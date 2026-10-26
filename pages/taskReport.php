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
                        
                        <div class="col-12 grid-margin stretch-card">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title" id="pageTitle">Task Report</h4>
                                    
                                    <form class="forms-sample" action="taskReport.php" method="post">
                                        <div class="row" style="align-items: center;">
                                        
                                            <div class="col-md-4">
                                              <div class="form-group">
                                            <label for="exampleInputdate1">From Date</label>
                                            <input type="date" class="form-control" id="exampleInputdate1" name="fromdate">
                                            </div>
                                            </div>
                                            
                                            <div class="col-md-4">
                                              <div class="form-group">
                                            <label for="exampleInputdate1">To Date</label>
                                            <input type="date" class="form-control" id="exampleInputdate1" name="todate">
                                            </div>
                                            </div>

                                         <div class="col-md-4">
                                        <button type="submit" id="submitBtn" class="btn btn-primary mr-2" name="start">Submit</button>
                                                                                    
                                        </div>                                            
                                        </div>

                                    </form>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-lg-12 grid-margin stretch-card">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title">My Task</h4>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Project</th>
                                                    <th>Task</th>
                                                    <th>Status</th>
                                                    <!--<th>Time Intervals</th>-->
                                                    <th>Total Man Hours</th>
                                                    <th>Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                $sum = strtotime('00:00:00');
                                                $query = mysqli_query($con, "SELECT * FROM taskEntry WHERE userId = '{$_SESSION['userId']}' ORDER BY dateTime DESC");
                                                while($result = mysqli_fetch_assoc($query)){
                                                    
                                                    $q = mysqli_query($con, "SELECT * FROM taskStatus WHERE taskId = '{$result['taskId']}' ORDER BY id ASC");
                                                    while($r = mysqli_fetch_assoc($q)){
                                                        $timeinsec = strtotime($r['manHours']) - $sum;
                                                        $totalTime = $totalTime + $timeinsec;
                                                        if($r['status'] == "resumed"){
                                                            $status = "Running";
                                                            define("status2", "Running");
                                                        } else {
                                                            $status = $r['status']."ed";
                                                        }
                                                        $action = $r['status'];
                                                        $id = $r['id'];
                                                    }
                                                    $h = intval($totalTime / 3600);
                                                    $totalTime = $totalTime - ($h * 3600);
                                                    $m = intval($totalTime / 60);
                                                    $s = $totalTime - ($m * 60);
                                                    $manHours = $h.":".$m.":".$s;
                                                    
                                                    $q2 = mysqli_query($con, "SELECT * FROM projectMaster WHERE projectId = '{$result['projectId']}'");
                                                    $r2 = mysqli_fetch_assoc($q2);
                                                ?>
                                                <tr>
                                                    <td>
                                                        <?php echo $r2['projectName'] ?>
                                                    </td>
                                                    <td>
                                                        <?php echo $result['task'] ?>
                                                    </td>
                                                    <td>
                                                        <?php echo $status ?>
                                                    </td>
                                                    <td>
                                                        <?php echo $manHours ?>
                                                    </td>
                                                    <td>
                                                        <?php 
                                                        if($action == "resumed"){
                                                        ?>
                                                        <a href="taskEntry_db?pause=<?php echo $id ?>" ><button type="button" onclick="fun('pause')" class="btn btn-primary">Pause</button></a>
                                                        <a href="taskEntry_db?end=<?php echo $result['taskId'] ?>" ><button type="button" class="btn btn-primary">End</button></a>
                                                        <?php
                                                        } else if($action == "pause") {
                                                        ?>
                                                        <a href="taskEntry_db?resume=<?php echo $result['taskId'] ?>" ><button type="button" class="btn btn-primary">Resume</button></a>
                                                        <a href="taskEntry_db?end=<?php echo $result['taskId'] ?>" ><button type="button" class="btn btn-primary">End</button></a>
                                                        <?php
                                                        } else if($action == "end"){
                                                        ?>
                                                        <a href="taskReport?taskId=<?php echo $result['taskId']; ?>">More Info</a>
                                                        <?php
                                                        }
                                                        ?>
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
    </script>
    
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