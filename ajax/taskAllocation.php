<?php
session_start();
require_once('../include/db_config.php');
if(isset($_GET['project']) && !empty($_GET['project'])){
    $project = $_GET['project'];
    
?>
<optgroup label="Allocted Employees">
    <?php
	
	if(!empty($_SESSION['int_type'])){
	$int_con = " AND signup.type='{$_SESSION['int_type']}'";
	}
	
    $q = mysqli_query($con, "SELECT fullName, allocatedProject.userId FROM allocatedProject JOIN signup ON allocatedProject.userId = signup.userId WHERE projectId = '$project' AND designation != 'admin' $int_con;");
    while($r = mysqli_fetch_assoc($q)){
        $allocatedEmployees .= "AND userId != '{$r['userId']}'";
    ?>
    <option value="<?php echo $r['userId'] ?>"><?php echo $r['fullName'] ?></option>
    <?php
    }
    ?>
</optgroup>
<optgroup label="Un allocated employees">
    <?php
	if(!empty($_SESSION['int_type'])){
	$int_con = " AND signup.type='{$_SESSION['int_type']}'";
	}
	
    $q = mysqli_query($con, "SELECT fullName, userId FROM signup WHERE (designation IS NULL OR LOWER(designation) NOT IN ('admin', 'hr', 'superadmin', 'developer', 'md')) AND (userRole IS NULL OR LOWER(userRole) NOT IN ('admin', 'hr', 'superadmin', 'md')) AND (TRIM(LOWER(status)) = 'active' OR status = '1' OR status = 1) AND (regstatus = 'Approved' OR regstatus IS NULL OR regstatus = '') $int_con ORDER BY fullName ASC");
    while($r = mysqli_fetch_assoc($q)){
    ?>
    <option value="<?php $r['userId'] ?>"><?php echo $r['fullName'] ?></option>
    <?php
    }
    ?>
</optgroup>
<?php
die();   
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
    	$searchQuery = " AND (taskEntry.task LIKE '%".$searchValue."%' OR 
            signup.fullName LIKE '%".$searchValue."%' OR
            taskEntry.priority LIKE '%".$searchValue."%' OR
            projectMaster.projectName LIKE '%".$searchValue."%') ";
    }
    
    if($columnName == "srNo"){
        $columnName = "id";
        $columnSortOrder = "DESC";
    }
    
    $q = mysqli_query($con, "SELECT designation FROM signup WHERE userId = '{$_SESSION['userId']}'");
    $r = mysqli_fetch_assoc($q);
    if($r['designation'] != "admin"){
        $allocatedBy = " AND taskEntry.allocatedBy = '{$_SESSION['userId']}'";
    }
    
    ## Total number of records without filtering
    $sel = mysqli_query($con,"SELECT COUNT(taskEntry.id) as 'allcount' FROM `taskEntry` WHERE 1 $allocatedBy");
    $records = mysqli_fetch_assoc($sel);
    $totalRecords = $records['allcount'];
    
    ## Total number of records with filtering
    $sel = mysqli_query($con,"SELECT COUNT(taskEntry.id) as 'allcount' FROM `taskEntry` LEFT JOIN signup ON taskEntry.userId = signup.userId WHERE 1 $allocatedBy $searchQuery");
    $records = mysqli_fetch_assoc($sel);
    $totalRecordwithFilter = $records['allcount'];
    
    ## Fetch records
    // $empQuery = "SELECT taskEntry.id, taskEntry.taskId, taskEntry.task, taskEntry.userId, taskEntry.priority, signup.fullName FROM `taskEntry` LEFT JOIN signup ON taskEntry.userId = signup.userId WHERE 1 ".$searchQuery.$allocatedBy." ORDER BY ".$columnName." ".$columnSortOrder." LIMIT ".$row.",".$rowperpage;
    $empQuery = "SELECT 
        taskEntry.id,
        taskEntry.taskId,
        taskEntry.task,
        taskEntry.userId,
        taskEntry.priority,
        signup.fullName,
        projectMaster.projectName
    FROM taskEntry
    LEFT JOIN signup ON taskEntry.userId = signup.userId
    LEFT JOIN projectMaster ON taskEntry.projectId = projectMaster.projectId
    WHERE 1 $searchQuery $allocatedBy
    ORDER BY $columnName $columnSortOrder
    LIMIT $row,$rowperpage";

    $empRecords = mysqli_query($con, $empQuery);
    $data = array();
    $i = 1;
    
    while ($row = mysqli_fetch_assoc($empRecords)) {
        
        $q = mysqli_query($con, "SELECT status FROM taskStatus WHERE taskId = '{$row['taskId']}' ORDER BY id DESC");
        $r = mysqli_fetch_assoc($q);
        if(!empty($r['status'])){
            if($r['status'] == "pause"){
                $status = '<label class="badge badge-warning">Paused</label>';
            } else if($r['status'] == "resumed") {
                $status = '<label class="badge badge-primary">Resumed</label>';
            } else if($r['status'] == "end"){
                $status = '<label class="badge badge-success">End</label>';
            }
        } else {
            $status = '<label class="badge badge-danger">Not Started</label>';
        }
        
        $action = '';
        if(empty($r['status'])){
            $action .= ' <a href="taskAllocation?edit='.$row['id'].'">
                        <button class="btn btn-success btn-rounded btn-icon" style="width:45px; height:45px;" type="button">
                            <i class="mdi mdi-border-color" style="position: relative; top: 3px;font-size:20px;"></i>
                        </button>
                    </a>
                    
    
                    <button class="btn btn-danger btn-rounded btn-icon"  style="width:45px; height:45px;" type="button" onclick="deleteFun(\'taskAllocation_db.php\', \''.$row['id'].'\')"> 
                        <i class="mdi mdi-delete" style="position: relative; top: 2.5px; font-size:20px;"></i>
                    </button>';
        }
        
        if($r['status'] != 'end'){
            // $action .= '<button type="button" class="btn btn-primary btn-rounded btn-icon mx-1" >
            //                 <i class="mdi mdi-bell btn-icon-prepend"></i>
                          
            //             </button>';
        }
        
        $data[] = array(
                "srNo" => $i++,
                "ProjectName" => $row['projectName'],
                "task" => $row['task'],
                "fullName" => $row['fullName'],
                "priority" => $row['priority'],
                "status" => $status,
                "action" => $action
        	);
    }
    
    ## Response
    // $response = array(
    //     "draw" => intval($draw),
    //     "iTotalRecords" => $totalRecords,
    //     "iTotalDisplayRecords" => $totalRecordwithFilter,
    //     "aaData" => $data
    // );

    $response = array(
    "draw" => intval($draw),
    "recordsTotal" => $totalRecords,
    "recordsFiltered" => $totalRecordwithFilter,
    "data" => $data
);
    
    echo json_encode($response);
}
?>

