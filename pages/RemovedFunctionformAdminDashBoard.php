
<!-- Code to display Top active Memebers -->
<!-- *************************** Top active Memebers and  Intern Tasks (Month) *************************** -->

<div class="row">
                        
                        <div class="col-md-4 grid-margin stretch-card">
    <div class="card">
        <div class="card-body">
            <p class="card-title">Top Active members <span style="font-size: 10px;" class="text-muted">(order by man hr)</span></p>
            <ul class="icon-data-list top-active-members" id="membersList">
                <?php
                $startDate = date('Y-m-d', strtotime('first day of this month'));
                $endDate = date('Y-m-d', strtotime('last day of this month'));

                // Pagination logic
                $limit = 10; // Number of members per page
                $page = isset($_GET['page']) ? $_GET['page'] : 1;
                $offset = ($page - 1) * $limit;

                // Get the total number of records
                $totalResult = mysqli_query($con, "SELECT COUNT(DISTINCT signup.userId) AS total FROM attendance RIGHT JOIN signup on attendance.userId=signup.userId WHERE signup.type='{$_SESSION['int_type']}' AND date BETWEEN '$startDate' AND '$endDate' AND manHours IS NOT NULL");
                $totalRow = mysqli_fetch_assoc($totalResult);
                $totalMembers = $totalRow['total'];
                $totalPages = ceil($totalMembers / $limit);

                // Fetch the data for the current page
                $q = mysqli_query($con, "SELECT SEC_TO_TIME(SUM(TIME_TO_SEC(manHours))) as 'manHours' ,signup.fullName, signup.profilePhoto FROM attendance RIGHT JOIN signup on attendance.userId=signup.userId WHERE signup.type='{$_SESSION['int_type']}' AND date BETWEEN '$startDate' AND '$endDate' AND manHours IS NOT NULL GROUP BY signup.userId ORDER BY SEC_TO_TIME(SUM(TIME_TO_SEC(manHours))) DESC LIMIT $limit OFFSET $offset;");
                
                while($r = mysqli_fetch_assoc($q)){
                    $userId = $r['userId'];
                    $manHours = $r['manHours'];
                    $fullName = $r['fullName'];
                    $profilePhoto = !empty($r['profilePhoto']) && file_exists('../profile-photos/'.$r['profilePhoto']) ? $r['profilePhoto'] : "dummy_profile_img.png";
                ?>
                <li>
                    <div class="d-flex">
                        <img src="../profile-photos/<?php echo $profilePhoto; ?>" alt="user">
                        <div>
                            <p class="text-info mb-1" style="line-height: 0.3rem;"><?php echo $fullName ?></p>
                            <small>Man Hours : <?php echo $manHours; ?> hr</small>
                        </div>
                    </div>
                </li>
                <?php } ?>
            </ul>
            
            <!-- Pagination Controls -->
            <nav>
                <ul class="pagination justify-content-center">
                    <?php for($i = 1; $i <= $totalPages; $i++): ?>
                        <li class="page-item <?php if($i == $page) echo 'active'; ?>">
                            <a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        </div>
    </div>
</div>
    
                            
                        <div class="col-md-8 grid-margin stretch-card pr-0">
                            <div class="card">
                                <div class="card-body">
                                    <p class="card-title mb-0">Intern Tasks (Month)</p>
                                    <div class="table-responsive">
                                        <table class="table table-striped table-borderless" id="example">
                                            <thead>
                                                <tr>
                                                    <th>Name <small>(Mode)</small></th>
                                                    <th>Tasks</th>
                                                    <th>Completed</th>
                                                    <th>Pending</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                $q = mysqli_query($con, "SELECT COUNT(taskId) as 'pendingTask',signup.userId,signup.fullName FROM taskEntry RIGHT JOIN signup on taskEntry.userId=signup.userId WHERE signup.type='{$_SESSION['int_type']}' GROUP BY signup.userId ORDER BY COUNT(taskId) DESC;");
                                                while($r = mysqli_fetch_assoc($q)){
                                                    $userId = $r['userId'];
                                                    $pendingTask = $r['pendingTask'];
                                                    
            								        $q1 = mysqli_query($con, "SELECT fullName, profilePhoto FROM signup WHERE userId = '$userId'");
            								        $r1 = mysqli_fetch_assoc($q1);
            								        $fullName = $r1['fullName'];
                                                    if(!empty($r1['profilePhoto'])){
            								            $profilePhoto = $r1['profilePhoto'];
            								        } else {
            								            $profilePhoto = "dummy_profile_img.png";
            								        }
            								        
            								        $q1 = mysqli_query($con, "SELECT count(taskId) as 'totalTask' FROM taskEntry WHERE userId = '$userId'");
            								        $r1 = mysqli_fetch_assoc($q1);
            								        $totalTask = $r1['totalTask'];
            								        
            								    
            								        $q2 = mysqli_query($con, "SELECT count(taskId) as 'completed' FROM taskEntry WHERE userId = '$userId' AND status = 'end'");
            								        $r2 = mysqli_fetch_assoc($q2);
            								        $completed = $r2['completed'];
                                                    
                                                     $q3 = mysqli_query($con, "SELECT count(taskId) as 'start' FROM taskEntry WHERE userId = '$userId' AND status = 'start'");
            								        $r3 = mysqli_fetch_assoc($q3);
            								        $pendingTask = $r3['start'];
                                                    
                                                ?>
                                                <tr>
                                                    <td><?php echo $fullName ?> <small></small></td>
                                                    <td class="font-weight-bold"><?php echo $totalTask ?></td>
                                                    <td><?php echo $completed ?></td>
                                                    <td><?php echo $pendingTask ?></td>
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













                    <!-- Punch IN and Punch Out -->
                                                            <?php
                    //
                                                                //Punch In
                                                                //
                                                                $q1 = mysqli_query($con, "SELECT punchInTime FROM attendance WHERE date = '$today' AND userId = '$userId' ORDER BY id ASC LIMIT 1") or  die($con->error);
                                                                $r = mysqli_fetch_assoc($q1);
                                                                
                                                                if(!empty($r['punchInTime']) && $r['punchInTime'] != "00:00:00" ){
                                                                    $punchInTime = date('h:i:s A', strtotime($r['punchInTime']));
                                                                } else {
                                                                    $punchInTime = "-";
                                                                }
                                                                ?>
                                                                <!--<tr> -->
                                                                <!--    <th>Punch In time <span style="font-size: 10px;" class="text-muted">(Today)</span></th>-->
                                                                <!--    <td>:</td>-->
                                                                <!--    <td><?php echo $punchInTime ?></td>-->
                                                                <!--</tr>-->
                                                                
                                                                
                                                                <?php 
                                                                //
                                                                //Punch Out
                                                                //
                                                                $q = mysqli_query($con, "SELECT punchOutTime FROM attendance WHERE date = '$today' AND userId = '$userId' ORDER BY id DESC LIMIT 1") or  die($con->error);
                                                                $r = mysqli_fetch_assoc($q);
                                                                
                                                                if(!empty($r['punchOutTime']) && $r['punchOutTime'] != "00:00:00" ){
                                                                    $punchOutTime = date('h:i:s A', strtotime($r['punchOutTime']));
                                                                } else {
                                                                    $punchOutTime = "-";
                                                                }
                                                                ?>
                                                                <!--<tr>-->
                                                                <!--    <th>Punch Out time <span style="font-size: 10px;" class="text-muted">(Today)</span></th>-->
                                                                <!--    <td>:</td>-->
                                                                <!--    <td><?php echo $punchOutTime ?></td>-->
                                                                <!--</tr>-->








                                                                <!-- Table for Today ,Week and Month -->

                                                                <table class="table table-borderless report-table">
                                                                        <?php 
                                                                        //
                                                                        //Today
                                                                        //
                                                                        $today = date('Y-m-d');
                                                                        $query_f_t = mysqli_query($con,"SELECT * FROM attendance WHERE date = '$today' AND userId = '$userId'") or die("ERRORED OCCURED");
                                                                        $resulting_today = mysqli_fetch_assoc($query_f_t);
                                                                        
                                                                        
                                                                        if($resulting_today["punchOutTime"] != "00:00:00"){
                                                                            $punchInTime = date_create($resulting_today['punchInTime']);
                                                                            $punchOutTime = date_create($resulting_today['punchOutTime']);
                                                                            $interval = date_diff($punchInTime, $punchOutTime);
                                                                            $manHours = $interval->h.".".$interval->i;
                                                                            $todaysManHours = $manHours;
                                                                            
                                                                        }else{
                                                                            $punchInTime = date_create($resulting_today['punchInTime']);
                                                                            $punchOutTime = date_create(date('H:i:s'));
                                                                            $interval = date_diff($punchInTime, $punchOutTime);
                                                                            $manHours = $interval->h.".".$interval->i;
                                                                            $todaysManHours = $manHours;
                                                                            
                                                                        }
                                                                        
                                                                        $q = mysqli_query($con, "SELECT totalWorkingHours FROM signup WHERE userId = '$userId'");
                                                                        $r = mysqli_fetch_assoc($q);
                                                                        $actualWorkingHours = date('G.i', strtotime($r['totalWorkingHours']));
                                                                        
                                                                        $todaysManHoursPer = ($todaysManHours / $actualWorkingHours) * 100;
                                                                        
                                                                        ?>
                                                                        <tr>
                                                                            <td class="text-muted">Today</td>
                                                                            <td class="w-100 px-0">
                                                                                <div class="progress progress-md mx-4">
                                                                                    <div class="progress-bar bg-primary" role="progressbar" style="width: <?php echo $todaysManHoursPer ?>%" aria-valuenow="<?php echo $todaysManHoursPer     ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                                                                </div>
                                                                            </td>
                                                                            <td>
                                                                                <p class="font-weight-bold mb-0 h5" ><?php echo $todaysManHours; ?>/<snap style="font-size:10px;color:#83878b;"><?php echo $actualWorkingHours; ?>hr</snap></p>
                                                                            </td>
                                                                        </tr>
                                                                        
                                                                        <?php 
                                                                        //
                                                                        //Week
                                                                        //
                                                                        
                                                                        $total_week = 7*$actualWorkingHours;
                                										$weekManHours = 0;
                                                                        $first_date = date('Y-m-d', strtotime("this week"));
                                                                        $now_date = date("Y-m-d", strtotime("yesterday"));
                                                                        $query_f_w = mysqli_query($con,"SELECT * FROM attendance WHERE date BETWEEN '$first_date' AND '$now_date' AND userId = '$userId'") or die("ERRORED OCCURED");
                                                                        while($resulting_weeks = mysqli_fetch_assoc($query_f_w)){
                                                                            
                                                                            $punchInTime = date_create($resulting_weeks["punchInTime"]);
                                                                            $punchOutTime = date_create($resulting_weeks["punchOutTime"]);
                                                                            $interval = date_diff($punchpunchInTime, $punchOutTime);
                                                                            $manHours = $interval->h.".".$interval->i;
                                                                            $weekManHours = $weekManHours + $manHours;
                                                                        }
                                                                        $weekManHoursPer = ($weekManHours*100)/$total_week;
                                                                        ?>
                                                                        <tr>
                                                                            <td class="text-muted">This Week</td>
                                                                            <td class="w-100 px-0">
                                                                                <div class="progress progress-md mx-4">
                                                                                    <div class="progress-bar bg-warning" role="progressbar" style="width: <?php echo $weekManHoursPer ?>%" aria-valuenow="<?php echo $actualWorkingHours ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                                                                </div>
                                                                            </td>
                                                                            <td>
                                                                                <p class="font-weight-bold mb-0 h5" ><?php echo $weekManHours; ?>/<snap style="font-size:10px;color:#83878b;"><?php echo $total_week; ?>hr</snap></p>
                                                                            </td>
                                                                        </tr>
                                                                        
                                                                        <?php 
                                                                        //
                                                                        //Month
                                                                        //
                                                                        $total_month = cal_days_in_month(CAL_GREGORIAN, date('m'), date('Y'))*$actualWorkingHours;
                                										$monthManHours = 0;
                                                                        $first_date = date('Y-m-01');
                                                                        $now_date = date("Y-m-d", strtotime("yesterday"));
                                                                        $query_f_m = mysqli_query($con,"SELECT * FROM attendance WHERE date BETWEEN '$first_date' AND '$now_date' AND userId = '$userId'") or die("ERRORED OCCURED");
                                                                        while($resulting_months = mysqli_fetch_assoc($query_f_m)){
                                                                            
                                                                            $punchpunchInTime = date_create($resulting_months["punchInTime"]);
                                                                            $punchOutTime = date_create($resulting_months["punchOutTime"]);
                                                                            $interval = date_diff($punchpunchInTime, $punchOutTime);
                                                                            $manHours = $interval->h.".".$interval->i;
                                                                            $monthManHours = $monthManHours + $manHours;
                                                                            // $hours_difference_month = $hours_difference_month + round(differenceInHours($resulting_months["punchInTime"],$resulting_months["punchOutTime"]),0);
                                                                        }
                                                                        $monthManHoursPer = ($monthManHours*100)/$total_month;
                                                                        ?>
                                                                        <tr>
                                                                            <td class="text-muted">This Month</td>
                                                                            <td class="w-100 px-0">
                                                                                <div class="progress progress-md mx-4">
                                                                                    <div class="progress-bar bg-danger" role="progressbar" style="width: <?php echo $monthManHoursPer ?>%" aria-valuenow="<?php echo $monthManHoursPer ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                                                                </div>
                                                                            </td>
                                                                            <td>
                                                                                <p class="font-weight-bold mb-0 h5" ><?php echo $monthManHours; ?>/<snap style="font-size:10px;color:#83878b;"><?php echo $total_month; ?>hr</snap></p>
                                                                            </td>
                                                                        </tr>
                                                                        <!--<tr>
                                                                            <td class="text-muted">Overtime</td>
                                                                            <td class="w-100 px-0">
                                                                                <div class="progress progress-md mx-4">
                                                                                    <div class="progress-bar bg-info"
                                                                                        role="progressbar"
                                                                                        style="width: 60%"
                                                                                        aria-valuenow="60"
                                                                                        aria-valuemin="0"
                                                                                        aria-valuemax="100"></div>
                                                                                </div>
                                                                            </td>
                                                                            <td>
                                                                                <h5 class="font-weight-bold mb-0">664
                                                                                </h5>
                                                                            </td>
                                                                        </tr>-->
                                                                    </table>


                        <!-- Extra -->

                         <!--<tr>
                                                                            <td class="text-muted">Overtime</td>
                                                                            <td class="w-100 px-0">
                                                                                <div class="progress progress-md mx-4">
                                                                                    <div class="progress-bar bg-info"
                                                                                        role="progressbar"
                                                                                        style="width: 60%"
                                                                                        aria-valuenow="60"
                                                                                        aria-valuemin="0"
                                                                                        aria-valuemax="100"></div>
                                                                                </div>
                                                                            </td>
                                                                            <td>
                                                                                <h5 class="font-weight-bold mb-0">664
                                                                                </h5>
                                                                            </td>
                                                                        </tr>-->