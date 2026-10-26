<?php
session_start();
include('../include/db_config.php');

// ✅ Session check
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href='login.php';</script>";
    exit;
}

$userId = $_SESSION['userId'];
$int_type = isset($_SESSION['int_type']) ? $_SESSION['int_type'] : '';

$from = isset($_GET['from']) ? $_GET['from'] : '';
$to = isset($_GET['to']) ? $_GET['to'] : '';
$type = isset($_GET['types']) ? $_GET['types'] : '';
$mode = isset($_GET['mode']) ? $_GET['mode'] : '';
$intern = isset($_GET['intern']) ? $_GET['intern'] : '';

$where_late = "WHERE 1=1";

// ✅ Mandatory date check
$showAlert = false;
$lateEmpty = false;
$loadTables = true; // decide if tables should load
if (!empty($_GET)) {
    if (empty($from) || empty($to)) {
        $_SESSION['alert_icon'] = 'warning';
        $_SESSION['alert_title'] = 'Select From and To Date';
        $_SESSION['alert_text'] = 'Both From Date and To Date are mandatory!';
        $loadTables = false;
    } else {
        $from_db = date('Y-m-d', strtotime(str_replace('/', '-', $from)));
        $to_db = date('Y-m-d', strtotime(str_replace('/', '-', $to)));
        $where_late .= " AND l.date BETWEEN '$from_db' AND '$to_db'";
    }
}

// ✅ Optional filters
if (!empty($type)) {
    $where_late .= " AND s.branch = '$type'";
}
if (!empty($mode)) {
    $where_late .= " AND s.mode = '$mode'";
}
if (!empty($intern)) {
    $where_late .= " AND l.userId = '$intern'";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Late Punch-in Records</title>
    <?php include('../partials/_links.php'); ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/themes/material_blue.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">

<script src="https://code.jquery.com/jquery-3.5.1.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

    <!-- ⭐ NEW: Select2 CSS for searchable dropdown ⭐ -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" />

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { background: #f5f6fa; }
        .card { border: none; border-radius: 12px; background: #fff; box-shadow: 0 4px 12px rgba(0,0,0,0.08); margin-bottom: 25px; }
        .card-title { color: #103d9e; font-weight: 600; }
        .table th { background: #103d9e; color: #fff; text-align: center; vertical-align: middle; }
        .table td { text-align: center; vertical-align: middle; }
        .flatpickr-input { background-color: #fff !important; cursor: pointer; }
        /* Fix Select2 width */
        .select2-container--default .select2-selection--single {
            height: calc(1.5em + .75rem + 2px);
            padding: .375rem .75rem;
            font-size: 1rem;
            line-height: 1.5;
            border: 1px solid #ced4da;
            border-radius: .25rem;
        }
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 1.5;
            padding-left: 0;
            padding-right: 0;
        }
        .select2-container .select2-selection--single .select2-selection__arrow {
            height: calc(1.5em + .75rem + 2px);
            right: 0px;
        }
    </style>
</head>
<body>
<div class="container-scroller">
    <?php include('../partials/_navbar.php'); ?>
    <div class="container-fluid page-body-wrapper">
        <?php include('../partials/_sidebar.php'); ?>
        <?php include('../partials/_settings-panel.php'); ?>
        <div class="main-panel">
            <div class="content-wrapper">
                <div class="container py-4">
                    <!-- ✅ Filter Section -->
                    <form method="GET" id="filterForm">
                        <div class="row mb-4">
                            <div class="col-md-3">
                                <label>From Date</label>
                                <input type="text" id="fromDate" class="form-control datepicker" name="from"
                                       value="<?php echo htmlspecialchars($from); ?>" placeholder="Select From Date" autocomplete="off">
                            </div>
                            <div class="col-md-3">
                                <label>To Date</label>
                                <input type="text" id="toDate" class="form-control datepicker" name="to"
                                       value="<?php echo htmlspecialchars($to); ?>" placeholder="Select To Date" autocomplete="off">
                            </div>
                            <div class="col-md-3">
                                <label>Select Office</label>
                                <select class="form-control" name="types">
                                    <option value="">-- Select Office --</option>
                                    <option value="kinwat" <?php if ($type == 'kinwat') echo 'selected'; ?>>Settribe Kinwat</option>
                                    <option value="pune" <?php if ($type == 'pune') echo 'selected'; ?>>Settribe Pune</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label>Employee Mode</label>
                                <select class="form-control" name="mode">
                                    <option value="">-- Select Mode --</option>
                                    <option value="Online" <?php if ($mode == 'Online') echo 'selected'; ?>>Online</option>
                                    <option value="Offline" <?php if ($mode == 'Offline') echo 'selected'; ?>>Offline</option>
                                    <option value="Reset" <?php if ($mode == 'Reset') echo 'selected'; ?>>Reset</option>
                                </select>
                            </div>
                            <div class="col-md-4 mt-3">
                                <label>Employee</label>
                                <!-- ⭐ Changed: Added id="employeeSelect" ⭐ -->
                                <select class="form-control" name="intern" id="employeeSelect"> 
                                    <option value="">-- Select Employee --</option>
                                    <option value="" <?php if ($intern == "") echo "selected"; ?>>All</option>
                                    <?php
                                    $empFilter = "";
                                    if (!empty($type)) $empFilter .= " AND office='$type'";
                                    if (!empty($mode)) $empFilter .= " AND type='$mode'";
                                    // The query fetches Active INT/EMP data (designation != 'admin' AND status='Active')
                                    $query1 = mysqli_query($con, "SELECT * FROM signup WHERE designation !='admin' AND status='Active' $empFilter ORDER BY fullName ASC");
                                    while ($fetch = mysqli_fetch_assoc($query1)) {
                                        $selected = ($intern == $fetch['userId']) ? 'selected' : '';
                                        echo "<option value='{$fetch['userId']}' $selected>{$fetch['fullName']}</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-2 mt-4 d-flex align-items-end gap-2">
                                <button type="submit" class="btn btn-primary">Show</button>
                                <button type="button" id="resetBtn" class="btn btn-secondary">Reset</button>
                            </div>
                        </div>
                    </form>

                    <?php if($loadTables): ?>
                        <!-- ✅ Late Punch-in Table -->
                        <div class="card p-3">
                            <h4 class="card-title mb-3">Late Punch_In Records</h4>
                            <div class="table-responsive">
                                <table id="latePunchTable" class="table table-striped table-bordered">
                                    <thead>
                                        <tr>
                                            <th>Sr</th>
                                            <th>Employee/Intern Name</th>
                                            <th>Delayed Time</th>
                                            <th>Date</th>
                                            <th>Reason</th>
                                        </tr>
                                    </thead>
<tbody>
<?php
$rows = [];

$lateQuery = mysqli_query($con, "
    SELECT 
        CASE 
            WHEN l.emp_name IS NOT NULL AND l.emp_name != '' THEN l.emp_name
            WHEN s.fullName IS NOT NULL AND s.fullName != '' THEN s.fullName
            ELSE l.emp_id
        END AS emp_name,

        l.delayed_time,
        l.date,
        DATE_FORMAT(l.date, '%d/%m/%Y') AS formatted_date,
        l.reason
    FROM late_punch_in l
    LEFT JOIN signup s 
        ON l.userId = s.userId OR l.emp_id = s.userId
    $where_late
        ORDER BY l.date DESC

");


// store rows
if ($lateQuery && mysqli_num_rows($lateQuery) > 0) {

    while ($r = mysqli_fetch_assoc($lateQuery)) {
        $rows[] = $r;
    }

    // sort latest date → earliest date (DESC)
    usort($rows, function($a, $b) {
        return strtotime($b['date']) - strtotime($a['date']);
    });

    // Sr number in ascending order
    $sr = 1;

    foreach ($rows as $row) {
        echo "<tr>
                <td>{$sr}</td>
                <td>{$row['emp_name']}</td>
                <td>{$row['delayed_time']}</td>
                <td>{$row['formatted_date']}</td>
                <td>{$row['reason']}</td>
              </tr>";
        $sr++;
    }

} else {
        $lateEmpty = true; 

    echo "<tr><td colspan='5' class='text-center text-muted'>No Records Found</td></tr>";
}
?>
</tbody>

                                </table>
                            </div>
                        </div>
                    <?php endif; ?>

                </div>
            </div>
            <?php include('../partials/_footer.php'); ?>
        </div>
    </div>
</div>

<!-- DataTables + Export Buttons -->
<!-- <script src="https://code.jquery.com/jquery-3.5.1.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script> -->
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.flash.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>
   
    <!-- <script src="../vendors/select2/select2.min.js"></script> -->
    <script src="../js/off-canvas.js"></script>
    <script src="../js/hoverable-collapse.js"></script>
    <script src="../js/template.js"></script>
    <script src="../js/settings.js"></script>
    <script src="../js/select2.js"></script>

<script>
$(document).ready(function() {
    flatpickr("#fromDate", {
    dateFormat: "d/m/Y",
    allowInput: true
});

flatpickr("#toDate", {
    dateFormat: "d/m/Y",
    allowInput: true
});

    // ⭐ NEW: Initialize Select2 on the Employee dropdown ⭐
    $('#employeeSelect').select2({
        placeholder: "Select Employee",
        allowClear: true // optional
    });

    var lateEmpty = <?php echo $lateEmpty ? 'true' : 'false'; ?>;

    if (!lateEmpty) { // If it's NOT empty, initialize DataTables

        $('#latePunchTable').DataTable({
            pageLength: 10,
            ordering: false,  // 🔥 Important: prevents DataTables from breaking Sr order
           autoWidth: false
 });
    }

    $("#resetBtn").click(function () {
    $("#filterForm")[0].reset();
    $('#employeeSelect').val('').trigger('change');
    window.location.href = window.location.pathname;
    });

    // ✅ Toast-style alerts like login page
    function showToast(icon, text){
        const Toast = Swal.mixin({
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 2500,
            timerProgressBar: true,
            didOpen: (toast) => {
                toast.addEventListener('mouseenter', Swal.stopTimer);
                toast.addEventListener('mouseleave', Swal.resumeTimer);
            }
        });
        Toast.fire({ icon: icon, title: text });
    }

    <?php
    if(isset($_SESSION['alert_icon']) && isset($_SESSION['alert_text'])){
        $icon = $_SESSION['alert_icon'];
        $text = $_SESSION['alert_text'];
        echo "showToast('$icon', '$text');";
        unset($_SESSION['alert_icon'], $_SESSION['alert_text'], $_SESSION['alert_title']);
    }

    if($lateEmpty){
        echo "showToast('info', 'No Late Punch-in data available for the selected filters.');";
    }
    ?>
});
</script>

</body>
</html>