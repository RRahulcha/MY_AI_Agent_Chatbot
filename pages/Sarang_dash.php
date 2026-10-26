<?php
session_start();
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}

// ⭐ ADD THIS LINE - Check password status
include('../include/checkPasswordStatus.php');

if(empty($_SESSION['userId'])) {
    // header("location : login.php");
    // die();
	echo "<script>window.location.href = 'login.php';</script>";
    exit;
}
// role based access
else if($_SESSION['userRole']!=='senior_intern') {
    echo '<script>
        alert("You are not authorized to access this page.");
        window.location.href = "login.php";
        </script>';
    // header("location:not-authorized.php");
    die();
} 
else {
include('../include/db_config.php');
    
//require_once('../include/con.php');

$today = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">

<head>
	<?php
    include('../partials/_links.php')
    ?>

    <style>
        .list-wrapper {
            height: unset;
            max-height: unset;
            overflow: unset;
        }
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
    	
    	
    	.swal2-modal .swal2-icon, .swal2-modal .swal2-success-ring{
    	    margin-bottom:unset;
    	}
    
    	.tit {
    
    		font-size: 11px;
    		margin-bottom: 0.5rem;
    		line-height: 1rem;
    	}
    
    	.statusactive {
    		position: absolute;
    		left: 0%;
    		width: 12px;
    		height: 12px;
    		border-radius: 100%;
    		background: green;
    		top: 1px;
    		border: 1px solid #ffffff
    	}
    
    	.sessions {
    		margin-top: 2rem;
    		border-radius: 12px;
    		position: relative;
    	}
    
    	.li {
    		padding-bottom: 1.5rem;
    		border-left: 1px solid #abaaed;
    		position: relative;
    		padding-left: 20px;
    		margin-left: 10px;
    
    		&:last-child {
    			border: 0px;
    			padding-bottom: 0;
    		}
    
    		&:before {
    			content: '';
    			width: 15px;
    			height: 15px;
    			background: white;
    			border: 1px solid #4e5ed3;
    			box-shadow: 3px 3px 0px #bab5f8;
    			box-shadow: 3px 3px 0px #bab5f8;
    			border-radius: 50%;
    			position: absolute;
    			left: -10px;
    			top: 0px;
    		}
    	}
    
    	.time {
    		color: #2a2839;
    		font-family: 'Poppins', sans-serif;
    		font-weight: 500;
    
    		@include mobile-and-up {
    			font-size: .9rem;
    		}
    
    		@include mobile-only {
    			margin-bottom: .3rem;
    			font-size: 0.85rem;
    		}
    
    	}
    </style>
    <style>
		
        .my-card {
            overflow-y: scroll;
            height: 250px;
        }
        .table {
            border: none;
        }
        .table th, .table td {
            border: none; /* Removes borders from the table */
            font-size: 12px; /* Shrinks text size */
        }
        .table-container {
            width: 100%;
            overflow-x: auto; /* Enables horizontal scrolling if needed */
        }
		/* Shrink table further by reducing padding and font size */
		.table th, .table td {
			padding: 4px 8px; /* Smaller padding */
			text-align: left; /* Align text to the left */
			white-space: nowrap; /* Prevent text wrapping */
			overflow: hidden;
			text-overflow: ellipsis; /* Truncate long text */
		}
			/* Priority text styling */
		.priority-p1 {
			font-weight: bold;
			color: #ff0000; /* Red for highest priority */
		}
		.priority-p2 {
			font-weight: bold;
			color: #ff8000; /* Orange for medium-high priority */
		}
		.priority-p3 {
			font-weight: bold;
			color: #ffd700; /* Yellow for medium priority */
		}
		.priority-p4 {
			font-weight: bold;
			color: #008000; /* Green for lowest priority */
		}

		/* Highlight near deadlines */
		.near-deadline {
			font-weight: bold;
			color: #ff0000; /* Red for urgent deadlines */
		}
		/* Text wrapping adjustments */
		.table td {
			word-wrap: break-word;
			white-space: normal;
		}
    </style>
	<style>
        .overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1040;
            pointer-events: all;
        }
        .modal {
            display: none;
            position: fixed;
            z-index: 100;
            left: 50%;
            top: 50%;
            transform: translate(-50%, -50%); /* Center modal */
            width: 90%;
            height: fit-content;
            max-width: 400px;
            background-color: #fff;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            text-align: center;
        }

        .modal-content {
            padding: 0;
            border: none;
        }
		h2 {
				font-size: 24px;
				font-weight: bold;
				margin-bottom: 20px;
				color: #333;
			}

			h3 {
				font-size: 18px;
				font-weight: bold;
				color: #333;
				margin-bottom: 15px;
			}

			table {
				width: 100%;
				border-collapse: collapse;
				margin-bottom: 20px;
			}

			table th, table td {
				padding: 8px;
				text-align: left;
				border-bottom: 1px solid #ddd;
			}

			table td {
				color: #555;
			}

			button {
				background-color: #43c600;;
				color: white;
				border: none;
				padding: 10px 20px;
				font-size: 16px;
				border-radius: 5px;
				cursor: pointer;
				transition: background-color 0.3s ease;
				width:fit-content;
				margin: 30px auto 0px;
			}

			button:hover {
				background-color:rgb(60, 179, 1);
			}


        .close {
            color: #aaa;
            font-size: 28px;
            font-weight: bold;
            position: absolute;
			top: -21px;
			right: -13px;
			cursor: pointer;
        }

        .close:hover,
        .close:focus {
            color: black;
            text-decoration: none;
            cursor: pointer;
        }
		
		.near-deadline {
				color: #ff6f61;
				font-weight: bold;
			}

			/* Style for priority classes */
			.priority-high {
				color: #d9534f;
			}

			.priority-medium {
				color: #f0ad4e;
			}

			.priority-low {
				color: #5bc0de;
			}

    </style>
</head>

<body>
<div id="overlay" class="overlay"></div>
	<div class="container-scroller">
		<?php
        include('../partials/_navbar.php')
        ?>
		<div class="container-fluid page-body-wrapper">

			<?php
            include('../partials/_sidebar.php');
            
            $q = mysqli_query($con,"SELECT * FROM signup WHERE userId = '{$_SESSION['userId']}'") or die("ERRORED OCCURED");
            $r = mysqli_fetch_assoc($q);
            $expectedPunchOut = $r['expectedPunchOut'];
            
            $expectedPunchIn = date_create($r["expectedPunchIn"]);
            $expectedPunchOut = date_create($r["expectedPunchOut"]);
            $interval = date_diff($expectedPunchIn, $expectedPunchOut);
            $totalWorkingHours = $interval->h.".".$interval->i;
            
            $query = mysqli_query($con,"SELECT * FROM attendance WHERE punchOutTime = '00:00:00' AND date != '$today_date' AND userId = '{$_SESSION['userId']}'") or die("ERRORED OCCURED");
            while($result = mysqli_fetch_assoc($query)){
                // $q = mysqli_query($con, "UPDATE attendance SET punchOutTime = '$expectedPunchOut' AND userId = '{$_SESSION['userId']}'; ");
                
            }
            
            //For Today
			
            $query_f_t = mysqli_query($con,"SELECT * FROM attendance WHERE date = '$today_date' AND userId = '{$_SESSION['userId']}'") or die("ERRORED OCCURED");
            $resulting_today = mysqli_fetch_assoc($query_f_t);
            
            function differenceInHours($startdate,$enddate){
            	$starttimestamp = strtotime($startdate);
            	$endtimestamp = strtotime($enddate);
            	$difference = abs($endtimestamp - $starttimestamp)/3600;
            	return $difference;
            }
            
            if($resulting_today["punchInTime"]!=""){
                $punchpunchInTime = date_create($resulting_today["punchInTime"]);
                $punchOutTime = date_create(date('H:i:s'));
                $interval = date_diff($punchpunchInTime, $punchOutTime);
                $manHours = $interval->h.".".$interval->i;
                $hours_difference = $manHours;
            }else{
                $hours_difference = round(differenceInHours(date("h:i:s"),date("h:i:s")),0);
            }	
        	$over_time = 0;
        	if($hours_difference>8){
        	    $over_time = round($hours_difference-8,0);
        	}
                
                
            ?>
            <script>
                var hoursdiffrence = <?php echo $hours_difference; ?>;
                
            </script>
            
            <?php
            
            $query = mysqli_query($con, "SELECT punchInTime, punchOutTime, manHours FROM attendance WHERE userId = '{$_SESSION['userId']}' AND date = '$today' ORDER BY dateTime DESC LIMIT 1;");
            $result = mysqli_fetch_assoc($query);
            $count = mysqli_num_rows($query);
            ?>
			<div class="main-panel">
				<div class="content-wrapper">

					<div class="row">
						<div class="col-md-4 grid-margin stretch-card">
							<div class="card">
								<div class="card-body">
									<h4 class="card-title heading">Timesheet<span style="font-size: 10px;color:black;"> of <?php echo date('d M Y', strtotime($today_date)); ?></span> </h4>
									<div class="row">
										<div class="col-6">
											
											
												<?php 
											if($count > 0){
											?>
											<h6 class="card-title m-0" style="font-size: 13px;">Punch In at</h6>
											<p class="card-description" style="font-size: 10px;"><?php echo date('D') ?>, <?php echo date ('h:i:s A', strtotime($result['punchInTime'])); ?> </p>
											<?php 
											}else{
											    ?>
											    <h6 class="card-title m-0" style="font-size: 13px;">Not Punch In Yet</h6>
											    <?php
											}
											?>
											<?php 
											if($result['punchOutTime'] != "00:00:00"){
											?>
											<h6 class="card-title m-0" style="font-size: 13px;">Punch Out at</h6>
											<p class="card-description" style="font-size: 10px;"><?php echo date('D') ?>, <?php echo date ('h:i:s A', strtotime($result['punchOutTime'])); ?> </p>
											<?php 
											}else{
											    ?>
											    <h6 class="card-title m-0" style="font-size: 13px;">Not Punch Out Yet</h6>
											    <?php
											}
											?>
										</div>
										<div class="col-6" style="text-align: end;">
											<?php
                                            
                                            if($count == 0 || $result['manHours'] != "00:00:00" && !empty($result['manHours'])){
                                            ?>
											<a href="qrCodeScanner?punch=In">
												<button type="button" class="btn p-2 btn-inverse-success btn-rounded btn-fw">Punch In</button>
											</a>
											<?php
                                            } else if($result['manHours'] == "00:00:00" && $result['punchOutTime'] == "00:00:00" ) {
                                            ?>
																						
											  
											<!--<a href="qrCodeScanner?punch=Out">
												<button type="button" class="btn p-2 btn-inverse-success btn-rounded btn-fw">Punch Out</button>
											</a>-->
											
											<!--Punch Out with Alert-->
											<button type="button" onclick="remainderAlter()" class="btn p-2 btn-inverse-success btn-rounded btn-fw">Punch Out</button>
											<?php
                                            }
                                            ?>
										</div>
									</div>
									<canvas id="south-america-chart"></canvas>
									<hr>
									<!--<div id="south-america-legend"></div>-->
									<div class="row" style="text-align: center;">
										<div class="col-6">
											<h6 class="card-title m-0" style="font-size: 13px;">LUNCH BREAK</h6>
											<p class="card-description m-0" style="font-size: 10px;">60 min</p>
										</div>
										<div class="col-6">
											<h6 class="card-title m-0" style="font-size: 13px;">OVERTIME</h6>
											<p class="card-description m-0" style="font-size: 10px;"><?php echo $over_time; ?> hr</p>
										</div>
									</div>
								</div>
							</div>
						</div>

						<div class="col-md-4 stretch-card grid-margin">

							<div class="card">
								<div class="card-body">
									<p class="card-title heading">Statistics</p>
									<div class="charts-data">
										<div class="mt-1 border">
											<p class="mb-0 tit">Today</p>
											<div class="d-flex justify-content-between align-items-center">
												<div class="progress progress-md flex-grow-1 mr-4">
												    <?php
												        $perc = ($hours_difference*100)/8;
												    ?>
													<div class="progress-bar bg-success" role="progressbar" style="width: <?php echo $perc; ?>%" aria-valuenow="<?php echo $perc; ?>" aria-valuemin="0" aria-valuemax="100"></div>
												</div>
												<p class="mb-0" style="font-size:11px;"><?php echo $hours_difference; ?>/<snap style="font-size:10px;color:#83878b;">8hr</snap>
												</p>
											</div>
										</div>
										<?php
    										//For Week
    										$total_week = 7*8;
    										$hours_difference_week = 0;
                                            $first_date = date('Y-m-d', strtotime("this week"));
                                            $now_date = date("Y-m-d", strtotime("yesterday"));
                                            $query_f_w = mysqli_query($con,"SELECT * FROM attendance WHERE date BETWEEN '$first_date' AND '$now_date' AND userId = '{$_SESSION['userId']}'") or die("ERRORED OCCURED");
                                            while($resulting_weeks = mysqli_fetch_assoc($query_f_w)){
                                                
                                                $punchpunchInTime = date_create($resulting_weeks["punchInTime"]);
                                                $punchOutTime = date_create($resulting_weeks["punchOutTime"]);
                                                $interval = date_diff($punchpunchInTime, $punchOutTime);
                                                $manHours = $interval->h.".".$interval->i;
                                                $hours_difference_week = $hours_difference_week + $manHours;
                                            }
                                            $perc_week = ($hours_difference_week*100)/$total_week;
                                            
										?>
										<div class="mt-1 border">
											<p class="mb-0 tit">This Week</p>
											<div class="d-flex justify-content-between align-items-center">
												<div class="progress progress-md flex-grow-1 mr-4">
													<div class="progress-bar bg-danger" role="progressbar" style="width: <?php echo $perc_week; ?>%" aria-valuenow="<?php echo $perc_week; ?>" aria-valuemin="0" aria-valuemax="100"></div>
												</div>
												<p class="mb-0" style="font-size:11px;"><?php echo $hours_difference_week ?>/<snap style="font-size:10px;color:#83878b;"><?php echo $total_week ?>hr</snap>
												</p>
											</div>
										</div>
										<?php
    										//For Month
    										$total_month = cal_days_in_month(CAL_GREGORIAN, date('m'), date('Y'))*8;
    										$hours_difference_month = 0;
                                            $first_date = date('Y-m-01');
                                            $now_date = date("Y-m-d", strtotime("yesterday"));
											
                                            $query_f_m = mysqli_query($con,"SELECT * FROM attendance WHERE date BETWEEN '$first_date' AND '$now_date' AND userId = '{$_SESSION['userId']}'") or die("ERRORED OCCURED");
                                            while($resulting_months = mysqli_fetch_assoc($query_f_m)){
                                                
                                                $punchpunchInTime = date_create($resulting_months["punchInTime"]);
                                                $punchOutTime = date_create($resulting_months["punchOutTime"]);
                                                $interval = date_diff($punchpunchInTime, $punchOutTime);
                                                $manHours = $interval->h.".".$interval->i;
                                                $hours_difference_month = $hours_difference_month + $manHours;
                                                // $hours_difference_month = $hours_difference_month + round(differenceInHours($resulting_months["punchInTime"],$resulting_months["punchOutTime"]),0);
                                            }
                                            $perc_month = ($hours_difference_month*100)/$total_month;
                                            
										?>
										<div class="mt-1 border">
											<p class="mb-0 tit">This Month</p>
											<div class="d-flex justify-content-between align-items-center">
												<div class="progress progress-md flex-grow-1 mr-4">
													<div class="progress-bar bg-warning" role="progressbar" style="width: <?php echo $perc_month ?>%" aria-valuenow="<?php echo $perc_month ?>" aria-valuemin="0" aria-valuemax="100"></div>
												</div>
												<p class="mb-0" style="font-size:11px;"><?php echo $hours_difference_month; ?>/<snap style="font-size:10px;color:#83878b;"><?php echo $total_month; ?>hr</snap>
												</p>
											</div>
										</div>
										<?php
    										//For Overtime
    										//$total_overtiming = cal_days_in_month(CAL_GREGORIAN, date('m'), date('Y'))*8;
    										$hours_overtiming = 0;
                                            $first_date = date('Y-m-01');
                                            $now_date = date("Y-m-d", strtotime("yesterday"));
                                            $query_f_overtime = mysqli_query($con,"SELECT * FROM attendance WHERE date BETWEEN '$first_date' AND '$now_date' AND userId = '{$_SESSION['userId']}'") or die("ERRORED OCCURED");
											
                                            while($resulting_overtime = mysqli_fetch_assoc($query_f_overtime)){
                                                $punchpunchInTime = date_create($resulting_overtime["punchInTime"]);
                                                $punchOutTime = date_create($resulting_overtime["punchOutTime"]);
                                                $interval = date_diff($punchpunchInTime, $punchOutTime);
                                                $manHours = $interval->h.".".$interval->i;

                                                if($manHours > 8){
                                                    $new_over = $manHours - $totalWorkingHours;
                                                    if($new_over>0){
                                                        $hours_overtiming = $hours_overtiming + $new_over;
                                                    }
                                                }
                                            }
										?>
										<div class="mt-1 border">
											<p class="mb-0 tit">Monthly Overtime</p>
											<div class="d-flex justify-content-between align-items-center">
												<div class="progress progress-md flex-grow-1 mr-4">
													<div class="progress-bar bg-info" role="progressbar" style="width: <?php echo $hours_overtiming; ?>%" aria-valuenow="<?php echo $hours_overtiming; ?>" aria-valuemin="0" aria-valuemax="100"></div>
												</div>
												<p class="mb-0" style="font-size:11px;"><?php echo $hours_overtiming; ?>hr
												</p>
											</div>
										</div>
									</div>
								</div>
							</div>

						</div>

						<div class="col-md-4 stretch-card grid-margin">

							<div class="card">
								<div class="card-body my-card" style="overflow-y: scroll;height: 250px;">
									<p class="card-title heading">Projects</p>
									    <?php 
                                            $query = "
											SELECT projectName, deadLine, priority 
											FROM projectMaster 
											ORDER BY 
												CASE priority
													WHEN 'p1' THEN 1
													WHEN 'p2' THEN 2
													WHEN 'p3' THEN 3
													WHEN 'p4' THEN 4
													ELSE 5
												END,
												deadLine ASC
										";
                                            $result = $conn->query($query);
									    ?>
                                    <table class="table table-sm table-striped table-borderless" style="margin: 0; table-layout: fixed; width: 100%;">
										<thead style="background-color: #f8f9fa; top: 40px; z-index: 1;">
											<tr>
												<th style="width: 40%; text-align: left;">Project Name</th>
												<th style="width: 30%; text-align: left;">Deadline</th>
												<th style="width: 30%; text-align: left;">Priority</th>
											</tr>
										</thead>
										<tbody>
											<?php
											$today = date("Y-m-d");
											
											if ($result->num_rows > 0): 
												while ($row = $result->fetch_assoc()): 
													// Determine deadline highlighting
													$deadlineClass = (strtotime($row['deadLine']) - strtotime($today)) <= 7 * 24 * 60 * 60 ? "near-deadline" : "";
													// Determine priority class
													
													$priorityClass = "priority-" . strtolower($row['priority']);
											?>
												<tr>
													<td style="word-wrap: break-word; white-space: normal;">
														<?php echo nl2br(htmlspecialchars($row['projectName'])); ?>
													</td>
													<td class="<?php echo $deadlineClass; ?>" style="word-wrap: break-word; white-space: normal;">
														<?php echo nl2br(htmlspecialchars($row['deadLine'])); ?>	

													</td>
													<td class="<?php echo $priorityClass; ?>" style="word-wrap: break-word; white-space: normal;">
														<?php echo htmlspecialchars(strtoupper($row['priority'])); ?>
													</td>
												</tr>
											<?php endwhile; else: ?>
												<tr>
													<td colspan="3" class="text-center">No projects found</td>
												</tr>
											<?php endif; ?>
										</tbody>
									</table>
								</div>
							</div>

						</div>
					</div>
					<div class="row">
						<div class="col-md-7 grid-margin stretch-card">
							<div class="card">
								<div class="card-body">
									<p class="card-title mb-0 heading">Top 10 Pending Tasks <span style="font-size: 10px;color:black;">(Sorted by priority <b style="color: red;">High</b> to Low)</span></p>
									<div class="table-responsive">
										<table class="table table-striped table-borderless">
											<thead>
												<tr>
													<th>Task</th>
													<!--<th>Intern Name</th>-->
													<!--<th>Status</th>-->
													<!--<th>Allocated By</th>-->
													<th>Deadline</th>
													<th>Priority</th>
												</tr>
											</thead>
											<tbody>
											<?php
                                             
											$tasks = mysqli_query($con,"SELECT * FROM taskEntry WHERE (allocatedBy = '{$_SESSION['userId']}' OR userId='{$_SESSION['userId']}') AND status != 'end' ORDER BY FIELD(priority, 'high', 'medium', 'low')") or die("Can't fetch the data");
											while($all_tasks = mysqli_fetch_assoc($tasks)){
                                                 $userName = mysqli_query($con,"SELECT fullName FROM signup WHERE userId = '{$all_tasks['userId']}'") or die("Can't Fetch Name");
                                                            $fetch_name = mysqli_fetch_assoc($userName);
											?>
												<tr>
                                                    <td class="py-1">
                                                        <?php echo $all_tasks['task']; ?>
                                                    </td>
                                                    <!--
                                                    <td>
                                                        <a href="taskTracking.php">
                                                            <?php
                                                            if($all_tasks['status']=="pause"){
                                                                ?>
                                                                <label class="badge badge-danger">Pending</label>
                                                                <?php
                                                            }elseif($all_tasks['status']=="start"){
                                                                ?>
                                                                <label class="badge badge-success">Not Started</label>
                                                                <?php                                                                
                                                            }
                                                            ?>
                                                        </a>
                                                    </td>
                                                    -->
                                                    <td>
                                                        <?php echo date('d M Y, h:i a', strtotime($all_tasks['expectedDeadline'])); ?>
                                                    </td>
                                                    <td>
                                                        <?php echo $all_tasks['priority'];?>
                                                    </td>
                                                </tr>
                                            <?php } ?>    
											</tbody>
										</table>
									</div>
								</div>
							</div>
						</div>
						<div class="col-md-5 grid-margin stretch-card">
							<div class="card">
								<div class="card-body">
									<h4 class="card-title heading text-center">To Do List</h4>
									<hr>
									<div class="list-wrapper pt-2">
										<ul class="d-flex flex-column-reverse todo-list todo-list-custom" id="todoList_a">
<!--										li itmes-->
											
										</ul>
									</div>
									<div class="add-items d-flex mb-0 mt-2">
										<input type="text" class="form-control todo-list-input" placeholder="Add new task" id="task_names" required>
										<button onclick="add_task();" type="submit" class="add btn btn-icon text-primary todo-list-add-btn bg-transparent" fdprocessedid="xsoscm"><i class="icon-circle-plus"></i></button>
									</div>
								</div>
							</div>	
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>

	<?php
		// Query for pending tasks (status != 'end')
		$pendingTasksQuery = "
			SELECT COUNT(*) as pendingTasks
			FROM taskEntry 
			WHERE (allocatedBy = '{$_SESSION['userId']}' OR userId='{$_SESSION['userId']}')
			AND status != 'end'
		";
		$pendingTasksResult = mysqli_query($con, $pendingTasksQuery);
		$pendingTasksCount = mysqli_fetch_assoc($pendingTasksResult)['pendingTasks'];

		// Query for projects with deadlines <= 30 days
		$upcomingProjectsQuery = "
			SELECT projectName, deadLine, priority
			FROM projectMaster
			WHERE deadLine <= CURDATE() + INTERVAL 30 DAY
			ORDER BY priority ASC, deadline ASC
		";
		$upcomingProjectsResult = mysqli_query($con, $upcomingProjectsQuery);
		?>


		<!-- Modal HTML -->
		<div id="myModal" class="modal" style="display: none;">
			<div class="modal-content">
				<span class="close">&times;</span>
				<h2>Important Information</h2>
				
				<!-- Show the total number of pending tasks -->
				<div>
					<p>Total Pending Tasks: <strong><?php echo $pendingTasksCount; ?></strong></p>
					<a href="taskEntry.php" class="btn text-primary">View Details</a>
				</div>
				
				<hr>

				<!-- Show projects with deadlines <= 30 days -->
				<h3>All Projects Near Deadline</h3>
				<table class="table">
					<thead>
						<tr>
							<th>Project Name</th>
							<th>Deadline</th>
							<th>Priority</th>
						</tr>
					</thead>
					<tbody>
						<?php
						if (mysqli_num_rows($upcomingProjectsResult) > 0) {
							while ($project = mysqli_fetch_assoc($upcomingProjectsResult)) {
								$deadlineClass = (strtotime($project['deadLine']) - strtotime($today)) <= 7 * 24 * 60 * 60 ? "near-deadline" : "";
								

								// Determine priority class
								$priorityClass = "priority-" . strtolower($project['priority']);
								echo "
									<tr>
										<td>{$project['projectName']}</td>
										<td class='{$deadlineClass}'>{$formattedDeadline}</td>	
										<td class='{$priorityClass}'>" . strtoupper( $project['priority'] ) . "</td>
									</tr>
								";
							}
						} else {
							echo "<tr><td colspan='3' class='text-center'>No upcoming projects within 30 days.</td></tr>";
						}
						?>
					</tbody>
				</table>

				<button onclick="closeModal()">OK</button>
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
	<script src="../js/todolist.js?V=1"></script>
	<!-- endinject -->
	<!-- Custom js for this page-->
	<script src="../js/dashboard.js?v=1.0.1"></script>
	<script src="../js/Chart.roundedBarCharts.js"></script>
	<!-- End custom js for this page-->
	
	<?php
    require_once('../include/sweetAlert.php');
    if(isset($_SESSION['msg'])){
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

     <script>
     
        function remainderAlter(){
            Swal.fire({
                title: "Have you updated voice message to Sarang Sir?",
                icon: "warning",
                showCancelButton: true,
                confirmButtonColor: "#3085d6",
                cancelButtonColor: "#d33",
                confirmButtonText: "Yes",
                cancelButtonText: "No"
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location = "qrCodeScanner?punch=Out&updatedToSenior=Yes";
                }
            });
        }
     
     
        function add_task(){
            var task = document.getElementById("task_names").value;
            if(task==""){
                    Toast.fire({
                        icon: 'error',
                        text: 'Please enter task'
                    });
            }else{
                $.ajax({  
                     type:"POST",  
                     url:"to_do_list_db.php",  
                     data:"create=&name="+task,  
                     success: function(data){  
                        //alert(data); 
    //                    document.getElementById("donttask").style.display='none'; 
                         fetch_task();
                     }  
                    
                 });  
            }
        }
    
        function fetch_task(){
//            alert("okokk");
//       alert()
        $.ajax({  
             type:"POST",  
             url:"to_do_list_db.php",  
             data:"fetch=fetch",  
             success: function(data){  
             document.getElementById("todoList_a").innerHTML=data; 
       }  
     });  
        }
        fetch_task();
    
        
    function change_task(id){
        if(id==""){
                Toast.fire({
                    icon: 'error',
                    text: 'Try by re-login'
                });
        }else{
        $.ajax({  
             type:"POST",  
             url:"to_do_list_db.php",  
             data:"change=&task_id="+id,  
             success: function(data){  
                        
       }  
     });  
        }
    }
//    
//    function delete_task(id){
//        if(id==""){
//            Toast.fire({
//                icon: 'error',
//                text: 'Try by re-login'
//            });
//        }else{
//            $.ajax({  
//                 type:"POST",  
//                 url:"to_do_list_db.php",  
//                 data:"delete=&task_id="+id,  
//                 success: function(data){  
//                    //alert(data);  
//                 }  
//             });  
//            }
//        }
//    
    </script>

	<script>
        // Check if the modal_shown cookie exists and is set to 'false'
        window.onload = function() {
            var modalShown = getCookie("modal_shown");

            if (modalShown !== "true") {
                // Show the modal
                document.getElementById("myModal").style.display = "block";
            }
        };

        // Function to get cookie by name
        function getCookie(name) {
            var nameEQ = name + "=";
            var ca = document.cookie.split(';');
            for(var i=0;i < ca.length;i++) {
                var c = ca[i];
                while (c.charAt(0)==' ') c = c.substring(1,c.length);
                if (c.indexOf(nameEQ) == 0) return c.substring(nameEQ.length,c.length);
            }
            return null;
        }

        // Function to close the modal and set the cookie
        function closeModal() {
            // Close the modal
            document.getElementById("myModal").style.display = "none";
            
            // Set the cookie to 'true' so the modal doesn't show again
            /* document.cookie = "modal_shown=true; path=/; max-age=" + (60 * 60 * 24 * 365); // 1 year expiry */
        }

        // Close modal if user clicks on the close button (X)
        document.querySelector('.close').onclick = function() {
            closeModal();
        };
    </script>

	<?php include('../include/passwordChangeAlert.php'); ?>
</body>
</html>
<?php
}

?>