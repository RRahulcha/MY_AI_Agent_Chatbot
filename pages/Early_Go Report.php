<?php
session_start();
include('../include/db_config.php');
require_once('../include/early_go_helper.php');
ensureEarlyGoTableColumns($con);

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

$where_early = "WHERE 1=1";

// ✅ Mandatory date check
$earlyEmpty = false;
$loadTables = !empty($_GET);
if (!empty($_GET)) {
    if (empty($from) || empty($to)) {
        $_SESSION['alert_icon'] = 'warning';
        $_SESSION['alert_title'] = 'Select From and To Date';
        $_SESSION['alert_text'] = 'Both From Date and To Date are mandatory!';
        $loadTables = false;
    } else {
        $from_db = date('Y-m-d', strtotime(str_replace('/', '-', $from)));
        $to_db = date('Y-m-d', strtotime(str_replace('/', '-', $to)));
        $where_early .= " AND e.date BETWEEN '$from_db' AND '$to_db'";
    }
}

// Optional filters
if (!empty($type)) {
    $where_early .= " AND s.branch = '$type'";
}

if (!empty($mode)) {
    $where_early .= " AND s.mode = '$mode'";
}

if (!empty($intern)) {
    $where_early .= " AND e.userId = '$intern'";
}

// ✅ Query
$rows = [];
if ($loadTables) {
    $earlyQuery = mysqli_query($con, "
        SELECT 
            COALESCE(e.emp_name, s.fullName) AS emp_name,
            e.early_go_time,
            e.early_departure_minutes,
            COALESCE(e.early_go_mark, 0) AS early_go_mark,
            COALESCE(e.action_taken, 'None') AS action_taken,
            COALESCE(e.hr_granted, 0) AS hr_granted,
            e.date,
            DATE_FORMAT(e.date, '%d/%m/%Y') AS formatted_date,
            e.reason
        FROM early_go e
        LEFT JOIN signup s ON e.userId = s.userId
        $where_early
        ORDER BY e.date DESC, e.early_go_time DESC
    ");

    if ($earlyQuery && mysqli_num_rows($earlyQuery) > 0) {
        while ($r = mysqli_fetch_assoc($earlyQuery)) {
            $rows[] = $r;
        }
    } else {
        $earlyEmpty = true;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Early Go Records</title>
    <?php include('../partials/_links.php'); ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/themes/material_blue.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" />
<script src="https://code.jquery.com/jquery-3.5.1.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://code.jquery.com/jquery-3.5.1.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>


    <style>
        body { background: #f5f6fa; }
        .card { border: none; border-radius: 12px; background: #fff; box-shadow: 0 4px 12px rgba(0,0,0,0.08); margin-bottom: 25px; }
        .card-title { color: #103d9e; font-weight: 600; }
        .table th { background: #103d9e; color: #fff; text-align: center; vertical-align: middle; }
        .table td { text-align: center; vertical-align: middle; }
        .flatpickr-input { background-color: #fff !important; cursor: pointer; }
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

                    <!-- FILTER FORM -->
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
                                <select class="form-control" name="intern">
                                    <option value="">-- Select Employee --</option>
                                    <option value="" <?php if ($intern == "") echo "selected"; ?>>All</option>

                                    <?php
                                    $empFilter = "";
                                    if (!empty($type)) $empFilter .= " AND office='$type'";
                                    if (!empty($mode)) $empFilter .= " AND type='$mode'";

                                    $query1 = mysqli_query($con, "SELECT * FROM signup WHERE designation !='admin' AND status='Active' $empFilter");
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

                    <!-- EARLY GO TABLE -->
                    <?php if($loadTables): ?>
                        <div class="card p-3">
                            <h4 class="card-title mb-3">Early Go Records</h4>

                            <div class="table-responsive">
                                <table id="earlyGoTable" class="table table-striped table-bordered align-middle">
                                    <thead>
                                        <tr>
                                            <th>Sr</th>
                                            <th>Employee/Intern Name</th>
                                            <th>Date</th>
                                            <th>Early Go Time</th>
                                            <th>Early Duration</th>
                                            <th>Early Go Mark</th>
                                            <th>System Action Taken</th>
                                            <th>HR Status</th>
                                            <th>Reason</th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        <?php
                                        if(!$earlyEmpty){
                                            $sr = 1;
                                            foreach($rows as $row){
                                                $mins = (int)($row['early_departure_minutes'] ?? 0);
                                                $durDisp = $mins > 0 ? "{$mins} mins" : "-";
                                                $markBadge = ((int)$row['early_go_mark'] === 1 && (int)$row['hr_granted'] === 0)
                                                    ? '<span class="badge bg-warning text-dark">1 Mark</span>'
                                                    : '<span class="badge bg-success">0 Mark</span>';
                                                $hrBadge = ((int)$row['hr_granted'] === 1)
                                                    ? '<span class="badge bg-success">Approved</span>'
                                                    : '<span class="badge bg-secondary">Unapproved</span>';
                                                $actionDisp = htmlspecialchars($row['action_taken'] ?? 'None');
                                                $reasonDisp = htmlspecialchars($row['reason'] ?? '');
                                                $empNameDisp = htmlspecialchars($row['emp_name'] ?? '');

                                                echo "<tr>
                                                    <td class='text-center'>$sr</td>
                                                    <td><strong>{$empNameDisp}</strong></td>
                                                    <td class='text-center'>{$row['formatted_date']}</td>
                                                    <td class='text-center'>{$row['early_go_time']}</td>
                                                    <td class='text-center'>{$durDisp}</td>
                                                    <td class='text-center'>{$markBadge}</td>
                                                    <td>{$actionDisp}</td>
                                                    <td class='text-center'>{$hrBadge}</td>
                                                    <td>{$reasonDisp}</td>
                                                </tr>";
                                                $sr++;
                                            }
                                        }else{
                                            echo "<tr><td colspan='9' class='text-center text-muted'>No Records Found</td></tr>";
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

    if($('#earlyGoTable tbody tr').length > 0 && !<?php echo $earlyEmpty ? 'true' : 'false'; ?>){
        $('#earlyGoTable').DataTable({
            pageLength: 10,
            ordering: false // ✅ keeps PHP order
        });
    }

    $("#resetBtn").click(function () {
    $("#filterForm")[0].reset();
    window.location.href = window.location.pathname;
    });

    function showToast(icon, text){
        const Toast = Swal.mixin({
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 2500,
            timerProgressBar: true
        });
        Toast.fire({ icon: icon, title: text });
    }

    <?php
    if(isset($_SESSION['alert_icon'])){
        echo "showToast('{$_SESSION['alert_icon']}', '{$_SESSION['alert_text']}');";
        unset($_SESSION['alert_icon'], $_SESSION['alert_text'], $_SESSION['alert_title']);
    }

    if($earlyEmpty){
        echo "showToast('info', 'No Early Go data available for the selected filters.');";
    }
    ?>
});
</script>

</body>
</html>
