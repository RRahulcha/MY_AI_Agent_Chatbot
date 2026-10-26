<?php
session_start();
require_once('../include/db_config.php');

if (isset($_GET['exportAll'])) {
    // Fetch all tasks without pagination
    $empQuery = "SELECT taskEntry.id, taskEntry.task, taskEntry.expectedDeadline, taskEntry.location, taskEntry.status, signup.fullName FROM `taskEntry` LEFT JOIN signup ON taskEntry.userId = signup.userId WHERE 1";
    $empRecords = mysqli_query($con, $empQuery);
    $data = array();

    while ($row = mysqli_fetch_assoc($empRecords)) {
        $data[] = array(
            "fullName" => $row['fullName'],
            "task" => $row['task'],
            "expectedTime" => date('h:ia', strtotime($row['expectedDeadline'])), // Format expected time
            "location" => $row['location'],
            "status" => $row['status'],
        );
    }

    // Return all tasks as JSON
    header('Content-Type: application/json');
    echo json_encode($data);
    exit; // Stop further execution
}

if (isset($_GET['dataTable'])) {
   


    $loggedInUserId = $_SESSION['userId']; 
    $draw = $_POST['draw'];
    $row = $_POST['start'];
    $rowperpage = $_POST['length']; 
    $columnIndex = $_POST['order'][0]['column']; 
    $columnName = $_POST['columns'][$columnIndex]['data']; 
    $columnSortOrder = $_POST['order'][0]['dir']; 
    $searchValue = mysqli_real_escape_string($con, $_POST['search']['value']); 

    // Retrieve filters from POST
    $intern = isset($_POST['intern']) ? mysqli_real_escape_string($con, $_POST['intern']) : '';
    $fdate = isset($_POST['fdate']) ? mysqli_real_escape_string($con, $_POST['fdate']) : '';
    $tdate = isset($_POST['tdate']) ? mysqli_real_escape_string($con, $_POST['tdate']) : '';

    $searchQuery = "";
    if (!empty($searchValue)) {
        $searchQuery .= " AND (taskEntry.task LIKE '%$searchValue%' 
                             OR signup.fullName LIKE '%$searchValue%' 
                             OR taskEntry.location LIKE '%$searchValue%' 
                             OR projectMaster.projectName LIKE '%$searchValue%'
                             OR taskEntry.status LIKE '%$searchValue%') ";
    }

    // Additional Filters
    if (!empty($intern)) {
        $searchQuery .= " AND taskEntry.userId = '$intern' ";
    }
    if (!empty($fdate) && !empty($tdate)) {
        $searchQuery .= " AND taskEntry.expectedDeadline BETWEEN '$fdate' AND '$tdate' ";
    }

    // Total Records without filters
    $sel = mysqli_query($con, "SELECT COUNT(*) as allcount FROM taskEntry WHERE userId != '$loggedInUserId'");
    $records = mysqli_fetch_assoc($sel);
    $totalRecords = $records['allcount'];

    // Total Records with filters
    $sel = mysqli_query($con, "SELECT COUNT(*) as allcount FROM taskEntry 
                                LEFT JOIN signup ON taskEntry.userId = signup.userId
                                LEFT JOIN projectMaster ON taskEntry.projectId = projectMaster.projectId
                                WHERE taskEntry.userId != '$loggedInUserId' $searchQuery");
    $records = mysqli_fetch_assoc($sel);
    $totalRecordwithFilter = $records['allcount'];

    // Fetch records
    $empQuery = "SELECT taskEntry.id, taskEntry.task, taskEntry.expectedDeadline, taskEntry.manHours, 
                        taskEntry.location, taskEntry.status, signup.fullName, projectMaster.projectName
                 FROM taskEntry 
                 LEFT JOIN signup ON taskEntry.userId = signup.userId
                 LEFT JOIN projectMaster ON taskEntry.projectId = projectMaster.projectId
                 WHERE taskEntry.userId != '$loggedInUserId' $searchQuery
                 ORDER BY $columnName $columnSortOrder 
                 LIMIT $row, $rowperpage";

    $empRecords = mysqli_query($con, $empQuery);
    $data = [];
    $i = 1;
    while ($row = mysqli_fetch_assoc($empRecords)) {
        $statusLabel = ($row['status'] == "Pending") ? '<label class="badge badge-danger">Pending</label>' :
        (($row['status'] == "Completed") ? '<label class="badge badge-success">Completed</label>' :
        (($row['status'] == "resumed") ? '<label class="badge badge-primary">In Progress</label>' :
        (($row['status'] == "pause") ? '<label class="badge badge-warning">Pause</label>' :
        (($row['status'] == "end") ? '<label class="badge badge-success">Completed</label>' :
        '<label class="badge badge-danger">Not Started</label>'))));

        $data[] = [
            "srNo" => $i++,
            "fullName" => $row['fullName'],
            "projectName" => $row['projectName'],
            "task" => $row['task'],
            "expectedDeadline" => (!empty($row['expectedDeadline']) && $row['expectedDeadline'] != '0000-00-00 00:00:00') 
    ? date('d-m-Y H:i:s', strtotime($row['expectedDeadline'])) 
    : '-',
            "manHours" => $row['manHours'],
            "location" => $row['location'],
            "status" => $statusLabel,
        ];
    }

    // Response
    echo json_encode([
        "draw" => intval($draw),
        "iTotalRecords" => $totalRecords,
        "iTotalDisplayRecords" => $totalRecordwithFilter,
        "aaData" => $data
    ]);
    exit;
}
?>