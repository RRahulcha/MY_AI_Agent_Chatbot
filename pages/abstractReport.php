<?php
session_start();
if (!isset($_SESSION['userId']) && empty($_SESSION['userId'])) {
    // header('location:login');
    // die();
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}

include('../include/db_config.php');

if ($_SESSION['userRole'] !== 'admin') {
    // Display alert and then redirect
    echo "<script>
        alert('You cannot open this page. Please log in with the correct role.');
        window.location.href = 'login.php';
    </script>";
    exit(); // Ensure the script stops execution after the redirect
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>Attendance Report</title>
    <?php
    include('../partials/_links.php');
    ?>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.4.0/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.23/jspdf.plugin.autotable.min.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/css/select2.min.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    
    <style>
        .btn-secondary{
            color: white;
        }
        
        #cancelBtn{
            display: none;
        }
    </style>
    <style>
    .form-control
    {
        height:2.3rem;
    }
    .select2-container .select2-selection--single {
    box-sizing: border-box;
    cursor: pointer;
    padding: 1%;
    display: block;
    height: 37px;
    user-select: none;
    -webkit-user-select: none;
}
.select2-container--default .select2-selection--multiple .select2-selection__choice{
    font-size: 1rem;
}

.task
{
    display:none;
}

.required
{
   color:red; 
}

.table thead th, .jsgrid .jsgrid-table thead th
{
    text-align:center;
}

.table, .jsgrid .jsgrid-table {
    margin-bottom: 0;
    text-align: center;
}

/* .btn.btn-icon
{
    line-height:3;
    font-weight:bold;
    border-radius:0;
} */

.table th, .jsgrid .jsgrid-table th, .table td, .jsgrid .jsgrid-table td
{
    padding: 0.2rem 0.2rem;
}

.custom-tooltip {
    position: relative;
    display: inline-block;
    cursor: pointer;
}

.custom-tooltip .tooltip-text {
    visibility: hidden;
    background-color: #333;
    color: #fff;
    text-align: left;
    padding: 5px;
    border-radius: 5px;
    position: absolute;
    z-index: 1;
    bottom: 125%; /* Adjust as needed */
    left: 50%;
    transform: translateX(-50%);
    white-space: nowrap;
    width: max-content;
}

.custom-tooltip:hover .tooltip-text {
    visibility: visible;
}

.attendance-box {
    display: inline-block;
    text-align: center;
    font-size: 0.9rem;
    font-weight: bold;
    color: #fff;
    background-color: #28a745;
    border-radius: 5px;
    padding: 5px 10px;
    line-height: 1.2;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
}

.attendance-details {
    font-size: 0.8rem;
    font-weight: normal;
    margin-top: 5px;
    display: block;
}

.custom-margin {
        margin-top: 10px; /* Adjust as needed */
    }
   
        
.btn i {
    font-size: 20px; /* Adjust icon size */
}

.btn:hover .ex {
    font-size: 25px;
}
.btn:hover .pd {
    font-size: 25px;
}

.btn.btn-icon
{
    line-height:3;
    font-weight:bold;
    border-radius:0;
}

</style>

<style>

     #taskEntryTable{
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    background: #fff;
    border-radius: 8px;
    overflow: hidden;
    font-family: 'Segoe UI', sans-serif;
    font-size: 14px;
}


/* #reportTable {
    width: 100%;
    border-collapse: collapse; 
    background: #fff;
    font-family: 'Segoe UI', sans-serif;
    font-size: 14px;
    border: 1px solid #ddd; outer border
} */

/* Table header style */
#taskEntryTable thead {
    background-color: #103d9eff;
    color: #ffffffff;
}

#taskEntryTable thead th {
    padding: 12px 15px;
    font-weight: 600;
    text-align: left;
    border: 1px solid #ddd; /* header cell border */
    white-space: nowrap;
    padding-right: 30px !important;
}

#taskEntryTable tbody tr:nth-child(odd) {
     background-color: #f1f5ff;
}


#taskEntryTable tbody tr:hover {
    background-color: #dce4f8ff;
}

/* Table body cells */
#taskEntryTable tbody td {
    padding: 10px 15px;
    border: 1px solid #ddd; /* cell border */
    vertical-align: middle;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.swal2-icon {
    margin: 0 auto !important;   
}

</style>
</head>

<body>
    <div class="container-scroller">

        <?php
        include('../partials/_navbar.php');
        ?>

        <div class="container-fluid page-body-wrapper">

            <?php 
            include('../partials/_sidebar.php');
            include('../partials/_settings-panel.php');
            ?>

            <div class="main-panel">
                <div class="content-wrapper">
                    <div class="row">
                        <div class="col-12 grid-margin stretch-card">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title" id="pageTitle">Attendance Report</h4>
                                        <form class="forms-sample" action="abstractReport.php" method="post">
                                            <div class="row">
                                                <div class="col-md-4">
                                                    <div class="form-group">
                                                        <label for="fromDate">From Date</label>
                                                        <input type="date" name="fdate" class="form-control" id="fromDate" 
                                                            value="<?php echo $_REQUEST['fdate']; ?>" max="">
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="form-group">
                                                        <label for="toDate">To Date</label>
                                                        <input type="date" name="tdate" class="form-control" id="toDate" 
                                                            value="<?php echo $_REQUEST['tdate']; ?>" max="">
                                                    </div>
                                                </div>
                                            <div class="col-md-4">
                                            <label>Select Office</label>
                                            <select class="js-example-basic-single w-100 remov" style="width:100%" id="officeSelect" name="office">
                                                <option value="" disabled selected>-- Select Type --</option>
                                                <option value="kinwat" <?php if(isset($_POST['office']) && $_POST['office'] == 'kinwat') echo 'selected'; ?>>Settribe Kinwat</option>
                                                <option value="pune" <?php if(isset($_POST['office']) && $_POST['office'] == 'pune') echo 'selected'; ?>>Settribe Pune</option>
                                            </select>
                                        </div>

                                        <div class="col-md-4">
                                            <label>Employee Mode</label>
                                            <select class="js-example-basic-single w-100 remov" style="width:100%" id="modeSelect" name="mode">
                                                <option value="" disabled selected>-- Select Type --</option>
                                                <option value="Online" <?php if(isset($_POST['mode']) && $_POST['mode'] == 'Online') echo 'selected'; ?>>Online</option>
                                                <option value="Offline" <?php if(isset($_POST['mode']) && $_POST['mode'] == 'Offline') echo 'selected'; ?>>Offline</option>
                                                <option value="Reset" <?php if(isset($_POST['mode']) && $_POST['mode'] == 'Reset') echo 'selected'; ?>>Reset</option>
                                            </select>
                                        </div>
                                        

                                        <div class="col-md-4">
                                            <label>Employees</label>
                                            <select class="js-example-basic-single w-100 remov" style="width: 100%;" id="employeesDropdown" name="intern">
                                                <option value="all" <?php echo (isset($_POST['intern'], $_POST['status']) && $_POST['intern'] == "all" && $_POST['status'] == "Active") ? "selected" : ""; ?>>All</option>
                                                <option value="seniorIntern" <?php echo (isset($_POST['intern'], $_POST['status']) && $_POST['intern'] == "seniorIntern" && $_POST['status'] == "Active") ? "selected" : ""; ?>>Sr. Intern</option>
                                                <option value="juniorIntern" <?php echo (isset($_POST['intern'], $_POST['status']) && $_POST['intern'] == "juniorIntern" && $_POST['status'] == "Active") ? "selected" : ""; ?>>Jr. Intern</option>

                                                <!-- Options dynamically populated -->
                                            </select>
                                        </div> 


                                        <!-- <div class="col-md-4">
                                            <label>Employees</label>
                                            <select class="js-example-basic-single w-100 remov" style="width: 100%;" id="employeesDropdown" name="intern">
                                                <option value=""disabled>-- Select Name --</option>
                                                <option value="all" <?php echo (isset($_REQUEST['intern']) && $_REQUEST['intern'] == "all") ? "selected" : ""; ?>>All</option>
                                                <option value="seniorIntern" <?php echo (isset($_REQUEST['intern']) && $_REQUEST['intern'] == "seniorIntern") ? "selected" : ""; ?>>Sr. Intern</option>
                                                <option value="juniorIntern" <?php echo (isset($_REQUEST['intern']) && $_REQUEST['intern'] == "juniorIntern") ? "selected" : ""; ?>>Jr. Intern</option> -->
                                                <!-- Options dynamically populated -->
                                            <!-- </select>
                                        </div>     -->


                                        </div>

                                        <button type="submit" id="submitBtn" class="btn btn-primary mr-2 custom-margin" name="search">Show</button>
                                        
                                    </form>
                                </div>
                            </div>
                        </div>
                        <?php
                        if (isset($_POST['search'])) {
                        ?>
                            <div class="col-lg-12 grid-margin stretch-card">
                                <div class="card">
                                    <div class="card-body">
                                        <h4 class="card-title">Attendance Report</h4>
                                        
                                        <div class="export-buttons d-flex justify-content-end mb-3" style="gap: 10px">
                                            <button class="btn btn-success" onclick="exportTableToExcel()" title="Export to Excel">
                                                <i class="fas fa-file-excel ex"></i>
                                            </button>
                                            <button class="btn btn-danger" onclick="exportTableToPDF()" title="Export to PDF">
                                                <i class="fas fa-file-pdf pd"></i>
                                            </button>
                                        </div>

                                        <div class="table-responsive mt-4">
                                            <table class="table table-hover table-bordered" id="taskEntryTable" style="width:100%">
                                                <thead>
                                                    <tr>
                                                        <th style="text-align: center;">Intern Name</th>
                                                        <th style="text-align: center;"># Total Days</th>
                                                        <th style="text-align: center;"># Weekend</th>
                                                        <th style="text-align: center;"># Present Days</th>
                                                        <th style="text-align: center;"># Absent Days</th>
                                                        <th style="text-align: center;"># Tour Days</th>
                                                        <th style="text-align: center;"># Late Mark</th>
                                                        <th style="text-align: center;"># Early Go</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php
                                                    // Get values from the form submission
                                                    $fdate = isset($_REQUEST['fdate']) ? mysqli_real_escape_string($con, $_REQUEST['fdate']) : '';
                                                    $tdate = isset($_REQUEST['tdate']) ? mysqli_real_escape_string($con, $_REQUEST['tdate']) : '';
                                                    $office = isset($_REQUEST['office']) ? mysqli_real_escape_string($con, $_REQUEST['office']) : '';
                                                    $mode = isset($_REQUEST['mode']) ? mysqli_real_escape_string($con, $_REQUEST['mode']) : '';
                                                    $intern = isset($_REQUEST['intern']) ? mysqli_real_escape_string($con, $_REQUEST['intern']) : '';
                                                    $status = isset($_REQUEST['status']) ? mysqli_real_escape_string($con, $_REQUEST['status']) : '';

                                                    //Start building the base query
                                                    $query = "SELECT DISTINCT s.* FROM signup s 
                                                    LEFT JOIN attendance a ON s.userId = a.userId 
                                                    WHERE 1=1";


                                                    // Add conditions based on form inputs
                                                    if ($fdate && $tdate) {
                                                        $query .= " AND (a.date BETWEEN '$fdate' AND '$tdate' OR a.date IS NULL)";
                                                    }

                                                    if ($office) {
                                                        $query .= " AND s.office = '$office'";
                                                    }

                                                    if ($mode && $mode != 'Reset') {
                                                        $query .= " AND s.type = '$mode'";
                                                    }

                                                    // Handle intern type filtering
                                                    if ($intern) {
                                                        switch ($intern) {
                                                            case 'all':
                                                                // No additional filtering needed
                                                                break;
                                                            case 'seniorIntern':
                                                                $query .= " AND s.designation = 'seniorIntern'";
                                                                break;
                                                            case 'juniorIntern':
                                                                $query .= " AND s.designation = 'juniorIntern'";
                                                                break;

                                                            default:
                                                                // For fullname filtering (if specific intern is selected)
                                                                $query .= " AND s.fullname = '$intern'";
                                                                break;
                                                        }
                                                    }

                                                    if ($status) {
                                                                    $query .= " AND s.status = '$status'";
                                                                }

                                                    
                                                    // Execute the query
                                                    $result = mysqli_query($con, $query) or die("Error in query: " . mysqli_error($con));
                                                    while ($fetch_data = mysqli_fetch_assoc($result)) {
                                                    ?>
                                                        <tr>
                                                            <th style="text-align: left;">
                                                                <span><img src="../images/avatr.png"></span>&nbsp;<?php echo $fetch_data['fullName']; ?>
                                                            </th>
                                                            <?php
                                                            
                                                                // Convert to DateTime objects
                                                                $start = new DateTime($fdate);
                                                                $end = new DateTime($tdate);
                                                                
                                                                // Include the end date in the interval
                                                                $end->modify('+1 day');
                                                                
                                                                // Calculate the difference in days
                                                                $interval = $start->diff($end);
                                                                $totalDays = $interval->days;
                                                                //_____________________________________________
                                                                
                                                                //Count sundays
                                                                $start1 = new DateTime($fdate);
                                                                $end1 = new DateTime($tdate);
                                                                
                                                                // Include the end date in the period
                                                                $end1->modify('+1 day');
                                                                
                                                                // Initialize the counter and array
                                                                $sundays = [];
                                                                
                                                                while ($start1 < $end1) {
                                                                    if ($start1->format('w') == 0) { // 0 = Sunday
                                                                        $sundays[] = $start1->format('Y-m-d');
                                                                    }
                                                                    $start1->modify('+1 day');
                                                                }
                                                                
                                                                $totalSunday = count($sundays);
                                                            
                                                                
                                                                $attendanceCount = mysqli_query($con,"SELECT COUNT(id) as cnt FROM attendance WHERE punchInTime != '00:00:00' AND punchOutTime != '00:00:00' AND date BETWEEN '$fdate' AND '$tdate' AND userId = '{$fetch_data['userId']}'");
                                                                
                                                                $acRes = mysqli_fetch_assoc($attendanceCount);
                                                                
                                                                $attendanceCountLate = mysqli_query($con,"SELECT COUNT(id) as cnt FROM attendance WHERE punchInTime != '00:00:00' AND punchOutTime != '00:00:00' AND date BETWEEN '$fdate' AND '$tdate' AND userId = '{$fetch_data['userId']}' AND punchInTime >= '{$fetch_data['expectedPunchIn']}'");
                                                                
                                                                $aclRes = mysqli_fetch_assoc($attendanceCountLate);
                                                                
                                                                $attendanceCountEarly = mysqli_query($con,"SELECT COUNT(id) as cnt FROM attendance WHERE punchInTime != '00:00:00' AND punchOutTime != '00:00:00' AND date BETWEEN '$fdate' AND '$tdate' AND userId = '{$fetch_data['userId']}' AND punchOutTime <= '{$fetch_data['expectedPunchOut']}'");
                                                                
                                                                $aceRes = mysqli_fetch_assoc($attendanceCountEarly);
                                                                
                                                                // $tourDay = mysqli_query($con,"SELECT COUNT(id) as cnt FROM leaves WHERE 1=1 AND (from_date <= '$fdate' AND to_date >= '$tdate') AND userId = '{$fetch_data['userId']}' AND leave_type = 'TD'");

                                                                $tourDay = mysqli_query($con,"
                                                                    SELECT COUNT(id) as cnt 
                                                                    FROM tour
                                                                    WHERE fromDate <= STR_TO_DATE('$fdate', '%d-%m-%y')
                                                                    AND toDate   >= STR_TO_DATE('$tdate', '%d-%m-%y')
                                                                    AND userId = '{$fetch_data['userId']}'
                                                                ");


                                                                if (!$tourDay) {
                                                                    die("Query failed: " . mysqli_error($con));
                                                                }
                                                            
                                                                $tdRes = mysqli_fetch_assoc($tourDay);
                                                            
                                                            ?>
                                                            <td><?php echo $totalDays; ?></td>
                                                            <td><?php echo $totalSunday; ?></td>
                                                            <td><?php echo $acRes['cnt'] + $totalSunday + $tdRes['cnt']; ?></td>
                                                            <td><?php echo $totalDays - $acRes['cnt'] - $tdRes['cnt'] - $totalSunday; ?></td>
                                                            <td><?php echo $tdRes['cnt']; ?></td>
                                                            <td><?php echo $aclRes['cnt']; ?></td>
                                                            <td><?php echo $aceRes['cnt']; ?></td>
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
                    <?php } ?>    
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- Add this script at the end -->
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.5.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/js/select2.min.js"></script>
   
    <script src="../vendors/js/vendor.bundle.base.js"></script>
  
    <script src="../vendors/typeahead.js/typeahead.bundle.min.js"></script>
    <script src="../vendors/select2/select2.min.js"></script>
    <script src="../js/template.js"></script>
    <script src="../js/settings.js"></script>
    <script src="../js/select2.js"></script>
    
    <!-- Date picker script -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

    <script>
    function exportTableToExcel() {
        // Get the table element
        var table = document.getElementById('taskEntryTable');

        // Create a workbook and add the table to it
        var workbook = XLSX.utils.table_to_book(table, { sheet: "Attendance Report" });

        // Access the worksheet
        var worksheet = workbook.Sheets["Attendance Report"];

        // Make the header row bold
        var range = XLSX.utils.decode_range(worksheet["!ref"]);
        for (var col = range.s.c; col <= range.e.c; col++) {
            var cellAddress = XLSX.utils.encode_cell({ r: 0, c: col }); // Header row is the 0th row
            if (worksheet[cellAddress]) {
                worksheet[cellAddress].s = {
                    font: {
                        bold: true
                    }
                };
            }
        }

        // Export the workbook to an Excel file
        XLSX.writeFile(workbook, 'Attendance_Report.xlsx');
    }
</script>

    <script>
    async function exportTableToPDF() {
    const { jsPDF } = window.jspdf;

    // Create a new instance of jsPDF in landscape mode for more width
    const doc = new jsPDF('l');

    // Add title to the PDF
    doc.text('Attendance Report', 14, 10);

    // Extract table data
    const table = document.getElementById('taskEntryTable');
    const tableData = [];

    // Get table headers
    const headers = Array.from(table.querySelectorAll('thead tr th')).map(th => th.innerText);

    // Get table rows
    const rows = Array.from(table.querySelectorAll('tbody tr'));
    rows.forEach(row => {
        const rowData = Array.from(row.querySelectorAll('td, th')).map(cell => cell.innerText);
        tableData.push(rowData);
    });

    const maxColumnsPerPage = 6; // Adjust to fit the page (excluding Intern Name)
    const totalColumns = headers.length;
    let columnStart = 1; // Start slicing after "Intern Name"
    let pageNumber = 1;

    while (columnStart < totalColumns) {
        let columnEnd = Math.min(columnStart + maxColumnsPerPage, totalColumns);

        // Keep "Intern Name" as the first fixed column
        const headerSlice = [headers[0], ...headers.slice(columnStart, columnEnd)];
        const tableSlice = tableData.map(row => [row[0], ...row.slice(columnStart, columnEnd)]);

        // Add new page except for the first time
        if (columnStart > 1) {
            doc.addPage();
        }

        // Add page title
        doc.text(`Attendance Report - Page ${pageNumber}`, 14, 10);
        pageNumber++;

        // Add table to PDF
        doc.autoTable({
            head: [headerSlice],
            body: tableSlice,
            startY: 20,
            styles: { fontSize: 10, cellWidth: 'auto', overflow: 'linebreak' },
            margin: { left: 10, right: 10 },
            theme: 'grid',
        });

        columnStart = columnEnd; // Move to the next set of columns
    }

    // Save the PDF
    doc.save('Attendance_Report.pdf');
}



</script>

    <!-- Date picker script end -->
    <script>
                                      $(document).ready(function () {
    // Trigger when "Office" or "Employee Mode" dropdown values change
    $('#officeSelect, #modeSelect').change(function () {
        // Get the selected values from the dropdowns
        var office = $('#officeSelect').val();
        var mode = $('#modeSelect').val();

        // Ensure both fields are selected before making the request
        if (office && mode) {
            $.ajax({
                url: 'filter_emp.php', // Path to your PHP script
                type: 'POST',
                data: { 
                    office: office, 
                    mode: mode 
                },
                dataType: 'json', // Expect a JSON response from the server
                success: function (response) {
                    // Target the "Employees" dropdown
                    var $employeesDropdown = $('#employeesDropdown');
                    
                    // Clear existing options
                    $employeesDropdown.empty();
                    
                    // Add default options
                    $employeesDropdown.append('<option value="" selected disabled>-- Select Name --</option>');
                    $employeesDropdown.append('<option value="all">All</option>');

                    // Populate the dropdown with the filtered employees
                    if (response && response.length > 0) {
                        $.each(response, function (index, employee) {
                            $employeesDropdown.append('<option value="' + employee.fullname + '">' + employee.fullname + '</option>');
                        });
                    } else {
                        // If no employees found, show a message
                        $employeesDropdown.append('<option value="" disabled>No employees found</option>');
                    }

                    // Refresh Select2 or any custom dropdown styling if used
                    if ($employeesDropdown.hasClass('js-example-basic-single')) {
                        $employeesDropdown.select2();
                    }
                },
                error: function (xhr, status, error) {
                    // Log any errors to the console for debugging
                    console.error('Error fetching employees:', error);
                }
            });
        } else {
            // Clear the dropdown if either field is unselected
            $('#employeesDropdown').empty().append('<option value="" selected disabled>-- Select Name --</option>');
        }
    });
});

                                    </script>
    
    <script>
    // Get today's date in YYYY-MM-DD format
    const today = new Date().toISOString().split('T')[0];
    
    // Set the max attribute for both date fields
    document.getElementById("fromDate").setAttribute("max", today);
    document.getElementById("toDate").setAttribute("max", today);
    </script>

    <!-- This page script -->
    
    <!-- This page script end -->
    
    <!-- Data Table Script Link -->
    <script src="../vendors/datatables.net/jquery.dataTables.js"></script>
    <script src="../vendors/datatables.net-bs4/dataTables.bootstrap4.js"></script>
    <script src="../js/tooltips.js"></script>
     <script src="../js/popover.js"></script>
    <!-- Data Table Script Link End -->
    <?php
    require_once('../include/sweetAlert.php');
    if(isset($_SESSION['status'])){
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
</body>

</html>
