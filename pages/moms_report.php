<?php
session_start();
if(!isset($_SESSION['userId']) && empty($_SESSION['userId'])){
    // header('location:login');
    // die();
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}
if ($_SESSION['userRole'] !== 'admin') {
    echo "<script>
        alert('You cannot open this page. Please log in with the correct role.');
        window.location.href = 'login.php';
    </script>";
    exit();
}
include('../include/db_config.php');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>MOM Report - Expandable View</title>
    <?php include('../partials/_links.php'); ?>
   <script src="https://code.jquery.com/jquery-3.5.1.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

    <style>
        #mom_data {
            width: 100%;
            border-collapse: collapse;
            background-color: #fff;
            margin-top: 20px;
            font-size: 0.95rem;
        }
        #mom_data th, #mom_data td {
            padding: 12px 10px;
            text-align: center;
            border: 1px solid #dee2e6;
        }
        #mom_data th {
            background-color: #103d9eff;
            color: white;
            font-weight: 600;
        }
        #mom_data tbody tr:nth-child(even) { background-color: #fafafa; }
        #mom_data tbody tr:hover { background-color: #dce4f8ff; }

        td.details-control {
    text-align: center;
    cursor: pointer;
    font-size: 18px;
    color: #103d9e;
    font-weight: bold;
}
tr.shown td.details-control {
    color: #d9534f;
}



        /* Expanded row card */
        .details-card {
            background: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 12px;
            margin: 8px 0;
        }
        .details-card strong {
            color: #003366;
        }
    </style>
</head>

<body>
<div class="container-scroller">
    <?php include('../partials/_navbar.php'); ?>
    <div class="container-fluid page-body-wrapper">
        <?php include('../partials/_sidebar.php'); ?>

        <div class="main-panel">
            <div class="content-wrapper">
                <div class="row">
                    <div class="col-lg-12 grid-margin stretch-card">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title">MOM Report (Expandable)</h4>
                                <div class="table-responsive mt-3">
                                    <?php
                                    $sql = "SELECT * FROM moms";
                                    $result = mysqli_query($con, $sql);
                                    ?>
                                    <table class="table" id="mom_data">
                                        <thead>
                                            <tr>
                                                <th></th> <!-- expand button -->
                                                <th>Meeting Called By</th>
                                                <th>Date</th>
                                                <th>Time</th>
                                                <th>Meeting Type</th>
                                                <th>Meeting Mode</th>
                                                <th>Agenda</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php while ($res = mysqli_fetch_assoc($result)) {
                                                // Fetch attendees
                                                $attendeeNames = [];
                                                if (!empty($res['attendies'])) {
                                                    $ids = explode(',', $res['attendies']);
                                                    foreach ($ids as $id) {
                                                        $q = mysqli_query($con, "SELECT fullName FROM signup WHERE userId='$id'");
                                                        if ($q && $r = mysqli_fetch_assoc($q)) $attendeeNames[] = $r['fullName'];
                                                    }
                                                }
                                                $attendees = !empty($attendeeNames) ? implode(', ', $attendeeNames) : 'N/A';

                                                // Fetch apologies
                                                $apologyNames = [];
                                                if (!empty($res['apologies'])) {
                                                    $ids = explode(',', $res['apologies']);
                                                    foreach ($ids as $id) {
                                                        $q = mysqli_query($con, "SELECT fullName FROM signup WHERE userId='$id'");
                                                        if ($q && $r = mysqli_fetch_assoc($q)) $apologyNames[] = $r['fullName'];
                                                    }
                                                }
                                                $apologies = !empty($apologyNames) ? implode(', ', $apologyNames) : 'N/A';

                                                // Key Points
                                                $pointsList = "N/A";
                                                if (!empty($res['key_points'])) {
                                                    $points = explode("$#POINT#$", $res['key_points']);
                                                    $points = array_filter(array_map('trim', $points));
                                                    if (!empty($points)) {
                                                        $pointsList = "<ol style='margin:0; padding-left:18px;'>";
                                                        foreach ($points as $p) {
                                                            $pointsList .= "<li>" . htmlspecialchars($p) . "</li>";
                                                        }
                                                        $pointsList .= "</ol>";
                                                    }
                                                }

                                                // JSON encode details for expand
                                                $extraData = htmlspecialchars(json_encode([
                                                    "Attendees" => $attendees,
                                                    "Apologies" => $apologies,
                                                    "Key Points" => $pointsList
                                                ]), ENT_QUOTES);
                                            ?>
                                            <tr>
                                                <!-- <td class="details-control" data-extra='<?= $extraData ?>'></td> -->
                                                <td class="details-control" data-extra='<?= $extraData ?>'>▼</td>

                                                <td><?= htmlspecialchars($res['meeting_called_by']) ?></td>
                                                <td><?= date("d-m-Y", strtotime($res['date'])) ?></td>
                                                <td><?= htmlspecialchars($res['time']) ?></td>
                                                <td><?= htmlspecialchars($res['meeting_type']) ?></td>
                                                <td><?= htmlspecialchars($res['mode']) ?></td>
                                                <td><?= htmlspecialchars($res['agenda']) ?></td>
                                            </tr>
                                            <?php } ?>
                                        </tbody>
                                    </table>
                                </div>

                                <div class="d-flex justify-content-end mt-3">
                                    <a href="export_file.php?type=excel" class="btn btn-success btn-icon" title="Export to Excel" style="margin-right:10px;">
                                        <i class="fas fa-file-excel"></i> 
                                    </a>
                                    <a href="export_file.php?type=pdf" class="btn btn-danger btn-icon" title="Export to PDF">
                                        <i class="fas fa-file-pdf"></i> 
                                    </a>
                                </div> 
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div> 
</div>

<?php include('../partials/_scripts.php'); ?>

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
    // ✅ Initialize DataTable once
    var table = $('#mom_data').DataTable({
        "pageLength": 10,
        "order": [],
    });

    // ✅ Expand/Collapse details logic
    $('#mom_data tbody').on('click', 'td.details-control', function () {
        var tr = $(this).closest('tr');
        var row = table.row(tr);
        var icon = $(this);
        var extra = icon.attr('data-extra');
        var details = {};

        try {
            details = JSON.parse(extra);
        } catch (e) {
            console.error("Invalid JSON in data-extra", e);
        }

        if (row.child.isShown()) {
            // Collapse
            row.child.hide();
            tr.removeClass('shown');
            icon.text('▼'); // back to down arrow
        } else {


            let html = `
        <div class='details-card' style="display:flex; flex-direction:column; gap:8px; text-align:left;">
            <div style="display:flex; flex-wrap:wrap;">
                <div style="flex:0 0 150px; font-weight:bold;">Attendees:</div>
                <div style="flex:1;">${details["Attendees"] || '-'}</div>
            </div>
            <div style="display:flex; flex-wrap:wrap;">
                <div style="flex:0 0 150px; font-weight:bold;">Apologies:</div>
                <div style="flex:1;">${details["Apologies"] || '-'}</div>
            </div>
            <div style="display:flex; flex-wrap:wrap;">
                <div style="flex:0 0 150px; font-weight:bold;">Key Points:</div>
                <div style="flex:1;">${details["Key Points"] || '-'}</div>
            </div>
        </div>`;
            // // Expand
            // let html = `
            //     <div class='details-card'>
            //         <p><strong>Attendees:</strong> ${details["Attendees"] || '-'}</p>
            //         <p><strong>Apologies:</strong> ${details["Apologies"] || '-'}</p>
            //         <p><strong>Key Points:</strong><br>${details["Key Points"] || '-'}</p>
            //     </div>`;

            
            row.child(html).show();
            tr.addClass('shown');
            icon.text('▲'); // change to up arrow
        }
    });
});
</script>

</body>
</html>
