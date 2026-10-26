<?php 
session_start();
include('../include/db_config.php');
if(isset($_POST['projectId']) && !empty($_POST['projectId'])){
    $projectId = $_POST['projectId'];
    $project = " AND projectId = '".$projectId."'";
?>
    <option value="" selected="" disabled="">-- Select Task --</option>
    <option value="task">Add New Task</option>
    <?php
    $q = mysqli_query($con, "SELECT DISTINCT(taskEntry.taskId), taskEntry.projectId, taskEntry.task FROM taskEntry LEFT JOIN taskStatus ON taskEntry.taskId = taskStatus.taskId WHERE taskEntry.userId = '{$_SESSION['userId']}' $project ORDER BY taskEntry.dateTime DESC, taskEntry.id DESC;");
    while($r = mysqli_fetch_assoc($q)){
    ?>
    <option value="<?php echo $r['taskId'] ?>"><?php echo $r['task'] ?></option>
    <?php
    }
    ?>
<?php
}

if(isset($_GET['dataTable'])){


    ## Read value
    $draw = $_POST['draw'];
    $row = $_POST['start'];
    $rowperpage = $_POST['length']; // Rows display per page
    $columnIndex = $_POST['order'][0]['column']; // Column index
    $columnName = $_POST['columns'][$columnIndex]['data']; // Column name
    $columnSortOrder = $_POST['order'][0]['dir']; // asc or desc
    $searchValue = mysqli_real_escape_string($con,$_POST['search']['value']); // Search value
    ## Search 
    $searchQuery = " ";
    if($searchValue != ''){
    	$searchQuery = " AND (projectName LIKE '%".$searchValue."%' OR 
            task LIKE '%".$searchValue."%' OR
            expectedDeadline LIKE'%".$searchValue."%' OR location LIKE'%".$searchValue."%') ";
    }
    
    ## Total number of records without filtering
    $sel = mysqli_query($con,"SELECT COUNT(*) AS allcount FROM taskEntry WHERE userId = '{$_SESSION['userId']}'");
    $records = mysqli_fetch_assoc($sel);
    $totalRecords = $records['allcount'];
    
    ## Total number of records with filtering
    $sel = mysqli_query($con,"SELECT COUNT(*) AS allcount FROM taskEntry WHERE 1 AND userId = '{$_SESSION['userId']}' ".$searchQuery.$taluka);
    $records = mysqli_fetch_assoc($sel);
    $totalRecordwithFilter = $records['allcount'];
    
    ## Fetch records
    $empQuery = "SELECT projectMaster.projectId, projectMaster.projectName, taskEntry.taskId, taskEntry.task, taskEntry.expectedDeadline, taskEntry.allocatedBy, taskEntry.location FROM taskEntry LEFT JOIN projectMaster ON taskEntry.projectId = projectMaster.projectId WHERE 1 AND userId = '{$_SESSION['userId']}' ".$searchQuery." ORDER BY ".$columnName." ".$columnSortOrder." LIMIT ".$row.",".$rowperpage;
    $empRecords = mysqli_query($con, $empQuery);
    $data = array();
    $sum = strtotime('00:00:00');
    
    while ($row = mysqli_fetch_assoc($empRecords)) {
        $taskUpdate = "";
        $status = "";
        $manHours = "";
        $action = "";
        if ($row['allocatedBy'] == ""){
       
               $actionBtn = '
    
                <button class="btn btn-danger btn-rounded btn-icon" style="width:45px; height:45px;" type="button" onclick="deletefun(\'taskEntry_db.php\', \''.$row['taskId'].'\')"> 
                    <i class="mdi mdi-delete" style="position: relative; top: 3px;font-size:20px;"></i>
                </button> ';
                $task = $row['task'].' (<span style="font-size:10px;">self</span>)';
        } else{
            $task = $row['task'].' (<span style="font-size:10px;">allocated</span>)';
            $actionBtn = '';
           
        }
        
        $q = mysqli_query($con, "SELECT * FROM taskStatus WHERE taskId = '{$row['taskId']}' ORDER BY id ASC");
        while($r = mysqli_fetch_assoc($q)){
            $timeinsec = strtotime($r['manHours']) - $sum;
            $totalTime = $totalTime + $timeinsec;
            $status= "";
            if($r['status'] == "resumed"){
                $status ='<label class="badge badge-primary">In Progress</label>';
                
            } else if($r['status'] == "pause") {
                $status ='<label class="badge badge-warning">Pause</label>';
                
            } else if($r['status'] == "end") {
                $status ='<label class="badge badge-success">Completed</label>';
                
            }
            $action = $r['status'];
            $id = $r['id'];
        }
        
        if(empty($status)){
            $status ='<label class="badge badge-danger">Not Started</label>';
        }
        
        if($action == "resumed"){
            $taskUpdate = '
            <a href="taskEntry_db?pause='.$id .'" ><button type="button" onclick="fun(\'pause\')" class="btn btn-primary btn-rounded btn-icon"><i class="mdi mdi-pause-circle-outline" style="line-height: 1.7;font-size:25px;"></i></button></a>
            <button type="button" onclick="endfun(\'taskEntry_db.php\', \''.$row['taskId'].'\')" class="btn btn-success btn-rounded btn-icon "><i class="mdi mdi-check-all" style="line-height: 1.7;font-size:20px;"></i></button>';
        } else if($action == "pause") {
            $taskUpdate ='
            <a href="taskEntry_db?resume='.$row['taskId'] .'" ><button type="button" class="btn btn-primary btn-rounded btn-icon"><i class="mdi mdi-arrow-right-drop-circle-outline" style="line-height: 1.7;font-size:25px;"></i></button></a>
            <button type="button" onclick="endfun(\'taskEntry_db.php\', \''.$row['taskId'].'\')" class="btn btn-success btn-rounded btn-icon "><i class="mdi mdi-check-all" style="line-height: 1.7;font-size:20px;"></i></button>';
        } else if($action == "end"){
            // $taskUpdate = '<a href="taskReport?taskId='.$row['taskId'] .'">More Info</a>';
            $taskUpdate = '-';
        } else {
            $taskUpdate = '<a href="taskEntry_db?resume='.$row['taskId'] .'" ><button type="button" class="btn btn-primary btn-rounded btn-icon"><i class="mdi mdi-arrow-right-drop-circle-outline" style="line-height: 1.7;font-size:25px;"></i></button></a>';
        }
        $h = intval($totalTime / 3600);
        $totalTime = $totalTime - ($h * 3600);
        $m = intval($totalTime / 60);
        $s = $totalTime - ($m * 60);
        $manHours = $h.":".$m.":".$s;    
        
        $projectName = "<div style='text-align:center'>-</div>";
        if(!empty($row['projectName'])){
            $projectName = $row['projectName'];
        }
        
$expectedDeadline = "<div style='text-align:center'>-</div>";

if(!empty($row['expectedDeadline']) && $row['expectedDeadline'] != '0000-00-00 00:00:00'){
    $timestamp = strtotime($row['expectedDeadline']);
    if($timestamp !== false){
        $expectedDeadline = date('d-m-Y H:i:s', $timestamp);
    }
}

                
        $data[] = array(
                "TaskUpdate" => $taskUpdate ,
                "projectName" => $projectName,
                "task" => $task,
                "expectedDeadline" => $expectedDeadline,
                "manHr" => $manHours,
                "location" => $row['location'],
                "status" => $status,
                "action" => $actionBtn
        	);
    }
    
    ## Response
    $response = array(
        "draw" => intval($draw),
        "iTotalRecords" => $totalRecords,
        "iTotalDisplayRecords" => $totalRecordwithFilter,
        "aaData" => $data
    );
    
    echo json_encode($response);
}
?>
