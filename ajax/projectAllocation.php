<?php
require_once('../include/db_config.php');

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
    	$searchQuery = " AND (projectMaster.projectName LIKE '%".$searchValue."%' OR 
            signup.fullName LIKE '%".$searchValue."%' OR
            modificationDate LIKE'%".$searchValue."%'  ) ";
    }
    
    ## Total number of records without filtering
    $sel = mysqli_query($con,"SELECT COUNT(DISTINCT (allocatedProject.projectId)) as 'allcount' FROM allocatedProject JOIN projectMaster ON allocatedProject.projectId = projectMaster.projectId JOIN signup ON allocatedProject.userId = signup.userId");
    $records = mysqli_fetch_assoc($sel);
    $totalRecords = $records['allcount'];
    
    ## Total number of records with filtering
    $sel = mysqli_query($con,"SELECT COUNT(DISTINCT (allocatedProject.projectId)) as 'allcount' FROM allocatedProject JOIN projectMaster ON allocatedProject.projectId = projectMaster.projectId JOIN signup ON allocatedProject.userId = signup.userId WHERE 1 $searchQuery");
    $records = mysqli_fetch_assoc($sel);
    $totalRecordwithFilter = $records['allcount'];
    
    ## Fetch records
    $empQuery = "SELECT DISTINCT(allocatedProject.projectId), projectMaster.projectName FROM allocatedProject JOIN projectMaster ON allocatedProject.projectId = projectMaster.projectId JOIN signup ON allocatedProject.userId = signup.userId WHERE 1 ".$searchQuery." ORDER BY ".$columnName." ".$columnSortOrder." LIMIT ".$row.",".$rowperpage;
    $empRecords = mysqli_query($con, $empQuery);
    $data = array();
    
    while ($row = mysqli_fetch_assoc($empRecords)) {
        
        $employees = "<ol>";
        $q = mysqli_query($con, "SELECT allocatedProject.userId, signup.fullName FROM allocatedProject JOIN signup ON allocatedProject.userId = signup.userId WHERE allocatedProject.projectId = '{$row['projectId']}';");
        while($r = mysqli_fetch_assoc($q)){
            $employees .= "<li>".$r['fullName']."</li>";
        }
        $employees .= "</ol>";
        $action = ' <a href="projectAllocation.php?edit='.$row['projectId'].'">
                        <button type="button" class="btn btn-success btn-rounded btn-icon" style="width:50px; height:50px;" > 
                            <i class="mdi mdi-border-color" style="line-height: 50px; font-size:18px;"></i>
                        </button>
                    </a>
                    <button type="button" class="btn btn-danger btn-rounded btn-icon" style="width:50px; height:50px;"  onclick="deletefun(\'projectAllocate_db\', \''.$row['projectId'].'\')">
                        <i class="mdi mdi-delete" style="line-height: 50px; font-size:18px;"></i>
                    </button>';
                
        $data[] = array(
                "projectName" => $row['projectName'],
                "fullName" => $employees,
                "action" => $action
        	);
    }
    
    ## Response
    $response = array(
        "draw" => intval($draw),
        "iTotalRecords" => $totalRecords,
        "iTotalDisplayRecords" => $totalRecordwithFilter,
        "data" => $data
    );
    
    echo json_encode($response);
}
?>
