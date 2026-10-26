<?php
require_once('../include/db_config.php');

if(isset($_GET['projectId']) && isset($_GET['edit'])){
    $projectId = $_GET['projectId'];
    $query = mysqli_query($con, "SELECT projectName, client, status FROM projectMaster WHERE projectId = '$projectId'");
    $result = mysqli_fetch_assoc($query);
    $data['projectName'] = $result['projectName'];
    $data['client'] = $result['client'];
    $data['status'] = $result['status'];
    
    echo json_encode($data);
    exit;
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
    	$searchQuery = " AND (projectName LIKE '%".$searchValue."%' OR 
            client LIKE '%".$searchValue."%' OR
            modificationDate LIKE'%".$searchValue."%'  ) ";
    }
    
    ## Total number of records without filtering
    $sel = mysqli_query($con,"SELECT COUNT(*) AS allcount FROM projectMaster");
    $records = mysqli_fetch_assoc($sel);
    $totalRecords = $records['allcount'];
    
    ## Total number of records with filtering
    $sel = mysqli_query($con,"SELECT COUNT(*) AS allcount FROM projectMaster WHERE 1 ".$searchQuery.$taluka);
    $records = mysqli_fetch_assoc($sel);
    $totalRecordwithFilter = $records['allcount'];
    
    ## Fetch records
    $empQuery = "SELECT * FROM projectMaster WHERE 1 ".$searchQuery." ORDER BY ".$columnName." ".$columnSortOrder." LIMIT ".$row.",".$rowperpage;
    $empRecords = mysqli_query($con, $empQuery);
    $data = array();
    
    while ($row = mysqli_fetch_assoc($empRecords)) {
        
        if($row['status'] == 1){
            $status = '<label class="badge badge-success">Active</label>';
        } else {
            $status = '<label class="badge badge-danger">In-active</label>';
        }
        
        $action = '<button class="btn btn-success btn-rounded btn-icon" type="button" onclick="edit(\''.$row['projectId'].'\')">
                        <i class="mdi mdi-border-color" style="line-height: 3;"></i>
                    </button>
    
                <button class="btn btn-danger btn-rounded btn-icon" type="button" onclick="deletefun(\'projectMaster_db.php\', \''.$row['projectId'].'\')"> 
                    <i class="mdi mdi-delete" style="line-height: 2.7;"></i>
                </button>';
                
        $data[] = array(
                "projectName" => $row['projectName'],
                "client" => $row['client'],
                "modificationDate" => $row['modificationDate'],
                "status" => $status,
                "action" => $action
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