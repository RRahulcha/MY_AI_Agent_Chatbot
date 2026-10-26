<?php
session_start();
if(!isset($_SESSION['userId']) && empty($_SESSION['userId'])){
    // header('location:login');
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
include('../include/db_config.php');
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>Project Progress</title>
    <?php
    include('../partials/_links.php')
    ?>

    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-beta.1/css/select2.min.css" rel="stylesheet" />
    <style>
        .select2-container .select2-selection--single {
            box-sizing: border-box;
            cursor: pointer;
            padding-top: 6px;
            display: block;
            height: 40px;
            user-select: none;
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            margin: 5px;
        }
          /* Ensure Select2's height grows dynamically */
        .select2-container--default .select2-selection--multiple {
            min-height: 40px;
            max-height: 150px; /* Set a max-height to prevent overflow */
            overflow-y: auto; /* Add a scrollbar if the content exceeds max-height */
            display: flex;
            flex-wrap: wrap; /* Wrap selected items within the box */
            align-items: center;
        }

        .select2-container--default .select2-selection--multiple .select2-selection__choice {
            font-size: 14px; /* Adjust font size for better readability */
        margin: 2px; /* Add spacing between selected items */
        padding: 5px 10px; /* Adjust padding for selected items */
        background-color: #f1f1f1; /* Light background for selected items */
        border-radius: 4px; /* Rounded corners for selected items */
        color: #333; /* Text color for selected items */
        }

        .select2-container--default .select2-selection--multiple .select2-search--inline {
            flex: 1; /* Make the input box take remaining space */
        }
        
    </style>
    <style>
        /* Style the progress bar (range input) */
        input[type="range"] {
            -webkit-appearance: none; /* Remove default styling for webkit browsers */
            appearance: none;
            width: 100%; /* Make the range input take up the full width of the parent container */
            height: 15px; /* Adjust the height of the track */
            border-radius: 5px; /* Round the corners of the track */
            /* background: #ddd; */
            /* outline: none; */
            transition: background 0.2s ease-in-out; /* Smooth transition for background color */
            /* border: none; */
            padding: 0px
        }

        /* Style the track */
        input[type="range"]::-webkit-slider-runnable-track {
            width: 100%;
            height: 25px;
            border-radius: 5px;
            background: transparent;
        }

        input[type="range"]::-moz-range-track {
            width: 100%;
            height: 10px;
            border-radius: 5px;
            background: transparent;
        }

        /* Style the thumb (slider knob) */
        input[type="range"]::-webkit-slider-thumb {
            -webkit-appearance: none; /* Remove default styling for webkit browsers */
            appearance: none;
            width: 25px; 
            height: 25px;
            border-radius: 50%; /* Make the thumb circular */
            background:#4B49AC;
            cursor: pointer;
        }

        input[type="range"]::-moz-range-thumb {
            width: 25px; 
            height: 25px;
            border-radius: 50%; /* Make the thumb circular */
            background: #4B49AC; /* Thumb color */
            cursor: pointer; /* Change the cursor to a pointer when hovering over the thumb */
        }

        /* Change the track color when the range input is active (in focus or being dragged) */
        input[type="range"]:focus {
            /* background: #ccc; */
        }

        input[type="range"]:active {
            /* background-color: yellow; */
        }

    </style>
    <!-- DataTables CSS -->
<link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.13.1/css/jquery.dataTables.min.css">

</head>

<body>

class="container-scroller">

<?php
include('../partials/_navbar.php')
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
                            <h4 class="card-title" id="pageTitle">Project Progress</h4>
                            

                            <form class="forms-sample" action="projectProgress-db.php" method="post" onsubmit="return validateForm()">
                                    <?php
                                            $projectSql = "SELECT DISTINCT pm.projectId, pm.projectName, pm.status, pm.priority, pm.deadLine
                                                           FROM projectMaster pm
                                                           INNER JOIN allocatedProject ap
                                                           ON pm.projectId = ap.projectId
                                                           WHERE status = 1 AND ap.modifiedUserId = '{$_SESSION['userId']}'
                                                           ORDER BY pm.projectName";
                                    ?>

                                    <div class="row">
                                        <!-- Project Name -->
                                        <div class="col-md-4">
                                            <div class="form-group">
                                            <label>Project</label>
                                                <select class="js-example-basic-single w-100" required name="projectId" id="projectSelect" onchange="fetchProjectData()">
                                                    <option selected disabled value="">-- Select Project --</option>
                                                    <?php
                                                    $q = mysqli_query($con, $projectSql);
                                                    while ($r = mysqli_fetch_assoc($q)) {
                                                        $selected = ($projectId == $r['projectId']) ? "selected" : "";
                                                        echo "<option value='{$r['projectId']}' {$selected}>{$r['projectName']}</option>";
                                                    }
                                                    ?>
                                                </select>
                                            </div>
                                        </div>

                                        <!-- Seek Bar: To set progress percentage. precision of 2 decimal places -->
                                        <div class="col-md-4">
                                            <div class="form-group">
                                            <label>Progress </label> &nbsp; - &nbsp;&nbsp; <span id="progressValue" class="mt-2">0</span> %
                                            <input type="range" class="form-control" min="0" max="100" step="0.1" value="0" name="progress" id="progress">
                                            </div>
                                        </div>

                                        <!-- Remark -->
                                        <div class="col-md-4">
                                            <div class="form-group">
                                            <label>Remark</label>
                                            <textarea class="form-control" name="remark" id="remark" rows="3"></textarea>
                                            </div>
                                        </div>

                                        <!-- Hidden Field for User ID -->
                                        <input type="hidden" name="userId" value="<?php echo $_SESSION['userId']; ?>">

                                    </div>

                                    <button type="submit" id="submitBtn" class="btn btn-primary mr-2" name="<?php echo isset($projectId) ? 'update' : 'create'; ?>" value="<?php echo $projectId ?? ''; ?>">
                                        Set
                                    </button>
                                    
                                <!-- Cancel Button (Initially hidden) -->
                                <a href="projectMaster" id="cancelBtn" style="display: none;">
                                    <button class="btn btn-light" type="button">Cancel</button>
                                </a>
                                
                            </form>
                        </div>
                    </div>
                </div> <!-- end of 1st col -->

                <div class="col-lg-12 grid-margin stretch-card">
                    <div class="card">
                        <div class="card-body">
                            <h4 class="card-title">Project Status</h4>
                            <div class="table-responsive">
                                <table class="table table-striped" id="projectStatusTable">
                                    <thead>
                                        <tr>
                                            <th>Sr. No.</th>
                                            <th>ProjectId</th>
                                            <th>ProjectName</th>
                                            <th>Progress</th>
                                            <th>Deadline</th>
                                            <th>Remarks</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                            // SQL Query to join projectMaster and projectProgress on projectId
                                            $query = "
                                            SELECT pm.projectId, pm.projectName, pp.progress, pp.remark, pm.deadline
                                            FROM projectMaster pm
                                            JOIN projectProgress pp ON pm.projectId = pp.projectId
                                            WHERE pp.userId = '{$_SESSION['userId']}'
                                              AND pp.modificationDate = (
                                                SELECT MAX(modificationDate)
                                                FROM projectProgress
                                                WHERE projectId = pm.projectId
                                                AND userId = '{$_SESSION['userId']}'
                                                )
                                            ORDER BY pm.projectName, pp.modificationDate DESC;
                                            "; // You can adjust the WHERE clause to filter based on the logged-in user, if needed

                                            $result = mysqli_query($con, $query);

                                            // Check if query was successful
                                            if (!$result) {
                                            die("Query failed: " . mysqli_error($con));
                                            }
                                            $sr_no = 1;
                                        ?>
                                        <?php
                                        // Loop through the query result and display each row in the table
                                        while ($row = mysqli_fetch_assoc($result)) {
                                            echo "<tr>";
                                            echo "<td>" . $sr_no++ . "</td>";  // Increment and display Sr. No.
                                            echo "<td>" . htmlspecialchars($row['projectId']) . "</td>";
                                            echo "<td>" . htmlspecialchars($row['projectName']) . "</td>";
                                            echo "<td>" . htmlspecialchars($row['progress']) . "%</td>";
                                            echo "<td>". htmlspecialchars($row["deadline"]) . "</td>";
                                            echo "<td>" . htmlspecialchars($row['remark']) . "</td>";
                                            echo "</tr>";
                                        }
                                        ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

            </div> <!-- end of row -->
        </div>
    </div>

    <!-- plugins:js -->
    <!-- <script src="../vendors/js/vendor.bundle.base.js"></script> -->
    <script src="../vendors/js/vendor.bundle.base.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-beta.1/js/select2.min.js"></script>

    
    <script src="../js/off-canvas.js"></script>
    <script src="../js/hoverable-collapse.js"></script>
    <script src="../js/template.js"></script>
    <script src="../js/settings.js"></script>
    <script src="../js/todolist.js"></script>
    
    <!-- This page script -->
    <!-- <script src="../js/projectMaster.js"></script> -->
    <!-- This page script end -->
    
    <!-- Data Table Script Link -->
    <script src="../vendors/datatables.net/jquery.dataTables.js"></script>
    <script src="../vendors/datatables.net-bs4/dataTables.bootstrap4.js"></script>
    <!-- Data Table Script Link End -->
    <script>
        
        window.addEventListener('onbeforeunload', function (e) {
            status = document.getElementById("body").value;
            if (status == "running") {
                e.preventDefault();
                e.returnValue = '';
            }
        })
        $(document).ready(function() {
        $('.js-example-basic-single').select2({
            placeholder: "Select a Project",
            allowClear: true
        });
    });
    </script>
    <script>
        // Function to update the displayed progress percentage
        document.getElementById("progress").addEventListener("input", function() {
            const progress = this.value;
            document.getElementById("progressValue").innerText = progress;
        });

        function fetchProjectData() {
            const projectId = document.getElementById("projectSelect").value;

            if (projectId) {
                // Send AJAX request to fetch progress and remark for the selected project
                const xhr = new XMLHttpRequest();
                xhr.open("GET", "fetchProjectData.php?projectId=" + projectId, true);
                xhr.onreadystatechange = function () {
                    if (xhr.readyState === 4 && xhr.status === 200) {
                        const response = JSON.parse(xhr.responseText);
                        if (response.success) {
                            // Set progress and remark fields from the response
                            document.getElementById("progress").value = response.progress;
                            document.getElementById("progressValue").innerText = response.progress + "%";
                            document.getElementById("remark").value = response.remark;
                            updateRangeBackground(progress);
                        } else {
                            // If no data found for this project
                            // alert(response.message);
                            if (response.message === 'No data found') {
                                // If no data is found, show an error message
                                Swal.fire({
                                    icon: 'warning',
                                    title: 'No data found',
                                    text: 'No progress data found for this project.',
                                    toast: true,
                                    position: 'top-end',
                                    showConfirmButton: false,
                                    timer: 3000,
                                    timerProgressBar: true,
                                    didOpen: (toast) => {
                                        toast.addEventListener('mouseenter', Swal.stopTimer)
                                        toast.addEventListener('mouseleave', Swal.resumeTimer)
                                    }
                                })
                            } else {
                                // General error handling (e.g., database query failed or other issues)
                                Swal.fire({
                                    icon: 'warning',
                                    title: 'No data found',
                                    text: response.message,
                                    toast: true,
                                    position: 'top-end',
                                    showConfirmButton: false,
                                    timer: 3000,
                                    timerProgressBar: true,
                                    didOpen: (toast) => {
                                        toast.addEventListener('mouseenter', Swal.stopTimer)
                                        toast.addEventListener('mouseleave', Swal.resumeTimer)
                                    }
                                })
                            }
                        }
                    }
                };
                xhr.send();
            }
        }

        // Update the range background based on the value
        function updateRangeBackground(progress) {
            const range = document.getElementById("progress");
            const min = range.min;
            const max = range.max;

            // Calculate the percentage of the thumb position
            const percentage = ((progress - min) / (max - min)) * 100;

            // Update the background using a linear gradient
            range.style.background = `linear-gradient(to right, rgb(97, 208, 0) ${percentage}%, transparent ${percentage}%)`;
        }
    
    </script>
    <script>
    // Get the range input element
    const range = document.querySelector("input[type='range']");

    // Function to update the track background dynamically
    function updateRangeBackground() {
        const value = range.value;
        const min = range.min;
        const max = range.max;

        // Calculate the percentage of the thumb position
        const percentage = ((value - min) / (max - min)) * 100;

        // Update the background using a linear gradient
        range.style.background = `linear-gradient(to right,rgb(97, 208, 0) ${percentage}%, transparent ${percentage}%)`;
    }

    // Add event listener to update background when the slider is moved
    range.addEventListener('input', updateRangeBackground);

    // Initialize the background when the page loads
    window.addEventListener('load', updateRangeBackground);

    
</script>


    <?php
    require_once('../include/sweetAlert.php');
    if (isset($_SESSION['status'])) {
        $status = $_SESSION['status'];
        $msg = $_SESSION['msg'];
        if ($status != true) {
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



    