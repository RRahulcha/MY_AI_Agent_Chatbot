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
    background-color: #eef0f2ff; 
    color: #333; 
    font-weight: 600;
    text-align: center;
    border: 1px solid #dee2e6;
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

.attendance-tour {
    display: inline-block;
    text-align: center;
    font-size: 0.9rem;
    font-weight: bold;
    color: #fff;
    background-color: #ffc107;
    border-radius: 5px;
    padding: 5px 10px;
    line-height: 1.2;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
}

.attendance-details-tour {
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
                                        <form class="forms-sample" action="admin_report.php" method="post">
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
                                                <option value=""disabled>-- Select Name --</option>
                                                <option value="all" <?php echo (isset($_REQUEST['intern']) && $_REQUEST['intern'] == "all") ? "selected" : ""; ?>>All</option>
                                                <option value="seniorIntern" <?php echo (isset($_REQUEST['intern']) && $_REQUEST['intern'] == "seniorIntern") ? "selected" : ""; ?>>Sr. Intern</option>
                                                <option value="juniorIntern" <?php echo (isset($_REQUEST['intern']) && $_REQUEST['intern'] == "juniorIntern") ? "selected" : ""; ?>>Jr. Intern</option>
                                                <!-- Options dynamically populated -->
                                            </select>
                                        </div>    


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

                                        <div class="table-responsive">
                                            <table class="table table-hover table-bordered" id="taskEntryTable">
                                                <thead>
                                                    <tr>
                                                        <th>Intern Name</th>
                                                        <?php
                                                        // Function to get all the dates in given range
                                                        function getDatesFromRange($start, $end, $format = 'Y-m-d')
                                                        {

                                                            // Declare an empty array
                                                            $array = array();

                                                            // Variable that store the date interval
                                                            // of period 1 day
                                                            $interval = new DateInterval('P1D');

                                                            $realEnd = new DateTime($end);
                                                            $realEnd->add($interval);

                                                            $period = new DatePeriod(new DateTime($start), $interval, $realEnd);

                                                            // Use loop to store date into array
                                                            foreach ($period as $date) {
                                                                $array[] = $date->format($format);
                                                            }

                                                            // Return the array elements
                                                            return $array;
                                                        }

                                                        // Function call with passing the start date and end date
                                                        $Dates = getDatesFromRange($_REQUEST['fdate'], $_REQUEST['tdate']);

                                                        for ($i = 0; $i <= count($Dates) - 1; $i++) {
                                                        ?>
                                                            <th><h6 style="font-weight:400"><?php echo date('M', strtotime($Dates[$i])); ?></h6><h6 style="font-weight:bold;"><?php echo date('d', strtotime($Dates[$i])); ?></h6><h6 style="font-weight:400"><?php echo date('D', strtotime($Dates[$i])); ?></h6></th>
                                                        <?php
                                                        }

                                                        ?>

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

                                                    // Start building the base query
                                                    // $query = "SELECT DISTINCT s.* FROM signup s 
                                                    // LEFT JOIN attendance a ON s.userId = a.userId 
                                                    // WHERE 1=1";
                                                    $query = "SELECT DISTINCT s.* 
                                                                FROM signup s
                                                                LEFT JOIN attendance a 
                                                                        ON s.userId = a.userId
                                                                LEFT JOIN tourAttendance ta 
                                                                        ON s.userId = ta.userId
                                                                WHERE 1=1";


                                                    // Add conditions based on form inputs
                                                    if ($fdate && $tdate) {
                                                        $query .= " AND (a.date BETWEEN '$fdate' AND '$tdate' OR a.date IS NULL)
                                                                    OR (ta.date BETWEEN '$fdate' AND '$tdate' OR ta.date IS NULL)";
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

                                                    // Execute the query
                                                    $result = mysqli_query($con, $query) or die("Error in query: " . mysqli_error($con));
                                                    while ($fetch_data = mysqli_fetch_assoc($result)) {
                                                    ?>
                                                        <tr>
                                                            <th style="text-align: left;">
                                                                <span><img src="../images/avatr.png"></span>&nbsp;<?php echo $fetch_data['fullName']; ?>
                                                            </th>
                                                            <?php
                                                            for ($i = 0; $i <= count($Dates) - 1; $i++) {
                                                                if (date('D', strtotime($Dates[$i])) == "Sun") {
                                                                    // Weekend
                                                                    echo "<th><span class='btn btn-inverse-info btn-icon'>WK</span></th>";
                                                                } else {
                                                                    // Check if user is on tour on this date
                                                                    $tourCheck = mysqli_query($con, "
                                                                        SELECT *
                                                                        FROM tourAttendance ta
                                                                        WHERE ta.userId = '{$fetch_data['userId']}'
                                                                        AND punchInTime != '00:00:00' 
                                                                        AND punchOutTime != '00:00:00' 
                                                                        AND date='{$Dates[$i]}'
                                                                        ") or die("ERROR TOUR");

                                                                if (mysqli_num_rows($tourCheck) > 0) {
                                                                    // Display Tour
                                                                    $att_fetch_tour = mysqli_fetch_assoc($tourCheck);
                                                                    $punchInTime = $att_fetch_tour['punchInTime'];
                                                                    $punchOutTime = $att_fetch_tour['punchOutTime'];
                                                                    $manHours=$att_fetch_tour['manHours'];
                                                                    
                                                                    echo "<th>
                                                                                        <span class='attendance-tour'>
                                                                                            T<br>
                                                                                            <span class='attendance-details-tour'>
                                                                                                In: $punchInTime<br>
                                                                                                Out: $punchOutTime<br>
                                                                                                Hours: $manHours
                                                                                            </span>
                                                                                        </span>
                                                                                    </th>";
                                                                } else {
                                                                        // Check normal attendance
                                                                    $attendance = mysqli_query($con, "
                                                                    SELECT * FROM attendance 
                                                                    WHERE punchInTime != '00:00:00' 
                                                                    AND punchOutTime != '00:00:00' 
                                                                    AND date = '{$Dates[$i]}' 
                                                                    AND userId = '{$fetch_data['userId']}'
                                                                    ") or die("ERROR 202");

                                                                    if (mysqli_num_rows($attendance) > 0) {
                                                                    $att_fetch = mysqli_fetch_assoc($attendance);
                                                                    $punchInTime = $att_fetch['punchInTime'];
                                                                    $punchOutTime = $att_fetch['punchOutTime'];

                                                                    // Calculate man hours
                                                                    $punchInDateTime = new DateTime($punchInTime);
                                                                    $punchOutDateTime = new DateTime($punchOutTime);
                                                                    $interval = $punchInDateTime->diff($punchOutDateTime);
                                                                    $actualManHour = $interval->format('%h hours %i minutes');

                                                                                echo "<th>
                                                                                        <span class='attendance-box'>
                                                                                            P<br>
                                                                                            <span class='attendance-details'>
                                                                                                In: $punchInTime<br>
                                                                                                Out: $punchOutTime<br>
                                                                                                Hours: $actualManHour
                                                                                            </span>
                                                                                        </span>
                                                                                    </th>";
                                                                            } else {
                                                                                // Check if on leave
                                                                                $hld = mysqli_query($con, "
                                                                                    SELECT * FROM leaves 
                                                                                    WHERE leave_day = '{$Dates[$i]}' 
                                                                                    AND userId = '{$fetch_data['userId']}'
                                                                                ") or die("ERROR 208");

                                                                                if (mysqli_num_rows($hld) > 0) {
                                                                                    echo "<th><span class='btn btn-inverse-primary btn-icon'>L</span></th>";
                                                                                } else {
                                                                                    if ($Dates[$i] < date('Y-m-d')) {
                                                                                        echo "<th><span class='btn btn-inverse-danger btn-icon'>A</span></th>";
                                                                                    } else {
                                                                                        echo "<th><span class='btn btn-inverse-info btn-icon'>-</span></th>";
                                                                                    }
                                                                                }
                                                                            }
                                                                        }
                                                                    }
                                                                }

                                                            ?>
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
    <script src="../js/off-canvas.js"></script>
    <script src="../js/hoverable-collapse.js"></script>
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
