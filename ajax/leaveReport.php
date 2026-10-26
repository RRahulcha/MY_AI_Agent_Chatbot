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
            modificationDate LIKE'%".$searchValue."%'  ) AND ";
    }
    
    ## Total number of records without filtering
    $sel = mysqli_query($con,"SELECT COUNT(DISTINCT (leave_id)) as 'allcount' FROM leaves WHERE userId='{$_SESSION['userId']}'");
    $records = mysqli_fetch_assoc($sel);
    $totalRecords = $records['allcount'];
    
    ## Total number of records with filtering
    $sel = mysqli_query($con,"SELECT COUNT(DISTINCT (leave_id)) as 'allcount' FROM leaves WHERE 1 $searchQuery userId='{$_SESSION['userId']}'");
    $records = mysqli_fetch_assoc($sel);
    $totalRecordwithFilter = $records['allcount'];
    
    ## Fetch records
    $empQuery = "SELECT * FROM leaves WHERE 1 ".$searchQuery." userId='{$_SESSION['userId']}' ORDER BY ".$columnName." ".$columnSortOrder." LIMIT ".$row.",".$rowperpage;
    $empRecords = mysqli_query($con, $empQuery);
    $data = array();
    
    while ($row = mysqli_fetch_assoc($empRecords)) {
        
        $date_time = $row['leave_day'];
        //if()
        $from_date = $row['from_date'];
        $to_date = $row['date'];
        
        $action = ' <a href="projectAllocation?edit='.$row['projectId'].'">
                        <button type="button" class="btn btn-success btn-rounded btn-icon"> 
                            <i class="mdi mdi-border-color"></i>
                        </button>
                    </a>
                    <button type="button" class="btn btn-danger btn-rounded btn-icon" onclick="deletefun(\'projectAllocate_db\', \''.$row['projectId'].'\')">
                        <i class="mdi mdi-delete"></i>
                    </button>';
                
        $data[] = array(
                "leave" => $row['projectName'],
                "leaveType" => $employees,
                "leave" => $action
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