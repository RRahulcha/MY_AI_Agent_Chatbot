<?php
session_start();
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}
if ($_SESSION['userRole'] !== 'hr' && $_SESSION['userRole'] !== 'admin' && ($_SESSION['designation'] ?? '') !== 'hr') {
    echo '<script>
        alert("You are not authorized to access this page.");
        window.location.href = "login.php";
        </script>';
    die();
}
include('../include/db_config.php');
date_default_timezone_set('Asia/Kolkata');

// DELETE
if (isset($_POST['delete_id'])) {
    $id = (int)$_POST['delete_id'];
    mysqli_query($con, "DELETE FROM client_call_notifications WHERE id = $id");
    $_SESSION['status'] = true;
    $_SESSION['msg'] = 'Notification deleted successfully';
    header('Location: Client_Call_Notification.php');
    exit;
}

// UPDATE
if (isset($_POST['update_notification'])) {
    $id = (int)$_POST['notification_id'];
    $message = trim($_POST['message']);

    if ($message !== '') {
        $stmt = $con->prepare(
            "UPDATE client_call_notifications 
             SET message = ?, updated_at = NOW()
             WHERE id = ?"
        );
        $stmt->bind_param('si', $message, $id);
        $stmt->execute();
    }

    $_SESSION['status'] = true;
    $_SESSION['msg'] = 'Notification updated successfully';
    header('Location: Client_Call_Notification.php');
    exit;
}

// ── Analytics & Metrics Queries ───────────────────────────────────────────────
$totalNotifs = (int)mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as c FROM client_call_notifications"))['c'];
$activeNotifs = (int)mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as c FROM client_call_notifications WHERE is_active = 1"))['c'];
$inactiveNotifs = $totalNotifs - $activeNotifs;
$uniqueEmps = (int)mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(DISTINCT employee_userId) as c FROM client_call_notifications"))['c'];

// Role breakdown of notifications
$roleCounts = ['Junior Intern' => 0, 'Senior Intern' => 0, 'Developer / Employee' => 0];
$roleQ = mysqli_query($con, "
    SELECT s.designation, COUNT(*) as cnt
    FROM client_call_notifications c
    JOIN signup s ON s.userId COLLATE utf8mb4_unicode_ci = c.employee_userId COLLATE utf8mb4_unicode_ci
    GROUP BY s.designation
");
if ($roleQ) {
    while ($rq = mysqli_fetch_assoc($roleQ)) {
        $desg = strtolower(trim($rq['designation'] ?? ''));
        if ($desg === 'juniorintern') $roleCounts['Junior Intern'] += (int)$rq['cnt'];
        elseif ($desg === 'seniorintern') $roleCounts['Senior Intern'] += (int)$rq['cnt'];
        else $roleCounts['Developer / Employee'] += (int)$rq['cnt'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <title>SETTribe · Client Call Notification Dispatcher</title>
    <?php include('../partials/_links.php'); ?>

    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet"/>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.4.1/css/responsive.bootstrap4.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary: #4f46e5;
            --primary-dark: #3730a3;
            --primary-light: #eef2ff;
            --success: #10b981;
            --success-light: #ecfdf5;
            --warning: #f59e0b;
            --warning-light: #fffbeb;
            --danger: #ef4444;
            --purple: #8b5cf6;
            --border: #e2e8f0;
            --radius-lg: 14px;
            --radius-md: 10px;
        }

        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: #f1f5f9;
            color: #1e293b;
        }

        /* ── Header Banner ── */
        .analytics-header-card {
            background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 50%, #312e81 100%);
            border-radius: var(--radius-lg);
            padding: 26px 30px;
            color: #ffffff;
            margin-bottom: 24px;
            box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.3);
            position: relative;
            overflow: hidden;
        }
        .analytics-header-card::after {
            content: '';
            position: absolute;
            right: -50px;
            top: -50px;
            width: 240px;
            height: 240px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 50%;
            pointer-events: none;
        }
        .header-title-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 0.76rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 10px;
        }

        /* ── KPI Cards ── */
        .kpi-card {
            background: #ffffff;
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            padding: 18px 20px;
            height: 100%;
            box-shadow: 0 4px 14px rgba(0,0,0,0.03);
            transition: all 0.25s ease;
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .kpi-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 24px rgba(0,0,0,0.06);
            border-color: #cbd5e1;
        }
        .kpi-card::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; }
        .kpi-blue::before    { background: var(--primary); }
        .kpi-cyan::before    { background: #06b6d4; }
        .kpi-amber::before   { background: var(--warning); }
        .kpi-green::before   { background: var(--success); }
        .kpi-purple::before  { background: var(--purple); }

        .kpi-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
        .kpi-title { font-size: 0.78rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.4px; }
        .kpi-icon-wrap { width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1rem; }
        .icon-blue   { background: var(--primary-light); color: var(--primary); }
        .icon-cyan   { background: #ecfeff; color: #06b6d4; }
        .icon-amber  { background: var(--warning-light); color: var(--warning); }
        .icon-green  { background: var(--success-light); color: var(--success); }
        .icon-purple { background: #f3e8ff; color: var(--purple); }

        .kpi-value { font-size: 1.7rem; font-weight: 800; color: #0f172a; line-height: 1.1; margin-bottom: 6px; }
        .kpi-sub { font-size: 0.75rem; color: #64748b; font-weight: 600; }

        /* ── Report Card ── */
        .analytics-table-card {
            background: #ffffff;
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            padding: 24px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.03);
            margin-bottom: 30px;
        }

        .btn-gradient-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: #ffffff; border: none; font-weight: 700; padding: 10px 24px; border-radius: var(--radius-md); box-shadow: 0 4px 12px rgba(79, 70, 229, 0.25); transition: all 0.2s;
        }
        .btn-gradient-primary:hover { color: #fff; transform: translateY(-1px); }

        /* Table Styling */
        #notifTable { width: 100% !important; border-collapse: separate; border-spacing: 0; }
        #notifTable thead th {
            background: linear-gradient(135deg, #1e293b, #334155); color: #fff; padding: 12px 14px; font-weight: 700;
            font-size: .78rem; text-transform: uppercase; letter-spacing: .4px; border: none; text-align:center;
        }
        #notifTable thead th:first-child { border-top-left-radius: 8px; }
        #notifTable thead th:last-child { border-top-right-radius: 8px; }
        #notifTable tbody td {
            padding: 11px 14px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; text-align:center; color:#334155; font-size:.84rem;
        }
        #notifTable tbody tr:hover td { background: #f8fafc !important; }

        /* Select2 wrap fixes */
        .select2-container--default .select2-selection--multiple {
            min-height: 42px; border-radius: var(--radius-md); border: 1px solid var(--border); padding: 4px;
        }
    </style>
</head>

<body>
<div class="container-scroller">
    <?php include('../partials/_navbar.php'); ?>

    <div class="container-fluid page-body-wrapper">
        <?php include('../partials/_sidebar.php'); include('../partials/_settings-panel.php'); ?>

        <div class="main-panel">
            <div class="content-wrapper">

                <!-- ── HEADER BANNER ── -->
                <div class="analytics-header-card">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                        <div>
                            <div class="header-title-badge">
                                <i class="fas fa-bell"></i> Client Call Dispatcher System
                            </div>
                            <h2 style="font-size: 1.65rem; font-weight: 800; margin-bottom: 4px;">
                                Client Call Notification Management
                            </h2>
                            <p style="font-size: 0.85rem; opacity: 0.88; margin: 0;">
                                Configure and manage automated monthly client call notifications (dispatched on 15th of every month) for team members.
                            </p>
                        </div>
                        <div>
                            <span class="badge badge-light text-primary font-weight-bold p-2" style="border-radius:10px; font-size:0.82rem;">
                                <i class="fas fa-clock mr-1"></i> Cron Schedule: 15th of Month
                            </span>
                        </div>
                    </div>
                </div>

                <!-- ── KPI METRICS ROW ── -->
                <div class="row mb-4">
                    <div class="col-xl-2 col-md-4 col-sm-6 mb-3 mb-xl-0">
                        <div class="kpi-card kpi-blue">
                            <div class="kpi-header">
                                <span class="kpi-title">Total Configured</span>
                                <div class="kpi-icon-wrap icon-blue"><i class="fas fa-bell"></i></div>
                            </div>
                            <div>
                                <div class="kpi-value"><?= $totalNotifs ?></div>
                                <div class="kpi-sub">Total Reminders</div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-2 col-md-4 col-sm-6 mb-3 mb-xl-0">
                        <div class="kpi-card kpi-green">
                            <div class="kpi-header">
                                <span class="kpi-title">Active Reminders</span>
                                <div class="kpi-icon-wrap icon-green"><i class="fas fa-check-circle"></i></div>
                            </div>
                            <div>
                                <div class="kpi-value"><?= $activeNotifs ?></div>
                                <div class="kpi-sub">Active Cron Reminders</div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-2 col-md-4 col-sm-6 mb-3 mb-xl-0">
                        <div class="kpi-card kpi-amber">
                            <div class="kpi-header">
                                <span class="kpi-title">Paused / Inactive</span>
                                <div class="kpi-icon-wrap icon-amber"><i class="fas fa-pause-circle"></i></div>
                            </div>
                            <div>
                                <div class="kpi-value"><?= $inactiveNotifs ?></div>
                                <div class="kpi-sub">Disabled Reminders</div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 col-sm-6 mb-3 mb-xl-0">
                        <div class="kpi-card kpi-purple">
                            <div class="kpi-header">
                                <span class="kpi-title">Targeted Team Members</span>
                                <div class="kpi-icon-wrap icon-purple"><i class="fas fa-users"></i></div>
                            </div>
                            <div>
                                <div class="kpi-value"><?= $uniqueEmps ?></div>
                                <div class="kpi-sub">Unique Employees</div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 col-sm-6 mb-3 mb-xl-0">
                        <div class="kpi-card kpi-cyan">
                            <div class="kpi-header">
                                <span class="kpi-title">Next Monthly Dispatch</span>
                                <div class="kpi-icon-wrap icon-cyan"><i class="fas fa-calendar-alt"></i></div>
                            </div>
                            <div>
                                <div class="kpi-value" style="font-size:1.4rem;">15th of Month</div>
                                <div class="kpi-sub">Automated Cron Event</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── CHARTS ROW ── -->
                <div class="row mb-4">
                    <!-- Notification Status Doughnut Chart -->
                    <div class="col-lg-5 mb-3 mb-lg-0">
                        <div class="analytics-table-card h-100" style="padding:20px;">
                            <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-2">
                                <h5 style="font-size:.95rem; font-weight:800; color:#0f172a; margin:0;">
                                    <i class="fas fa-chart-pie text-primary mr-1"></i> Reminder Status Breakdown
                                </h5>
                                <span class="badge badge-light border" style="font-size:.72rem;">Active vs Inactive</span>
                            </div>
                            <div style="position: relative; height: 210px;">
                                <canvas id="notifStatusChart"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Role Breakdown Bar Chart -->
                    <div class="col-lg-7">
                        <div class="analytics-table-card h-100" style="padding:20px;">
                            <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-2">
                                <h5 style="font-size:.95rem; font-weight:800; color:#0f172a; margin:0;">
                                    <i class="fas fa-chart-bar text-purple mr-1"></i> Reminders by Role &amp; Designation
                                </h5>
                                <span class="badge badge-light border" style="font-size:.72rem;">Recipient Breakdown</span>
                            </div>
                            <div style="position: relative; height: 210px;">
                                <canvas id="roleBreakdownChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── NOTIFICATION SCHEDULER FORM CARD ── -->
                <div class="analytics-table-card mb-4">
                    <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-2">
                        <h5 style="font-size:1.05rem; font-weight:800; color:#0f172a; margin:0;">
                            <i class="fas fa-plus-circle text-primary mr-1"></i> Schedule Client Call Notification
                        </h5>
                        <span class="badge badge-primary font-weight-bold" style="font-size:.75rem;">HR / Admin Form</span>
                    </div>

                    <form id="clientCallForm" method="post">
                        <input type="hidden" name="notification_id" id="notification_id">

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <div class="form-group mb-0">
                                    <label style="font-weight:700; font-size:.85rem; color:#334155;">Select Employees / Team Members <span class="text-danger">*</span></label>
                                    <select class="js-example-basic-multiple w-100" name="employees[]" id="employees" multiple required>
                                        <?php
                                        $empQ = mysqli_query($con, "SELECT userId, fullName, designation FROM signup WHERE status='Active' ORDER BY fullName");
                                        while ($e = mysqli_fetch_assoc($empQ)) {
                                            $desg = ucfirst($e['designation'] ?? 'Employee');
                                            echo "<option value='{$e['userId']}'>{$e['fullName']} ({$desg})</option>";
                                        }
                                        ?>
                                    </select>
                                </div>
                            </div>

                            <div class="col-md-6 mb-3">
                                <div class="form-group mb-0">
                                    <label style="font-weight:700; font-size:.85rem; color:#334155;">Notification Message <span class="text-danger">*</span></label>
                                    <textarea class="form-control" name="message" id="message" rows="2" placeholder="Enter client call reminder message..." required style="border-radius:8px;">Friendly Reminder: Please conduct and log your monthly client call before the 15th of this month.</textarea>
                                </div>
                            </div>
                        </div>

                        <div class="mt-2 text-right">
                            <button type="submit" class="btn-gradient-primary" id="submitBtn">
                                <i class="fas fa-save mr-1"></i> Save Client Call Notification
                            </button>
                        </div>
                    </form>
                </div>

                <!-- ── SCHEDULED NOTIFICATIONS TABLE CARD ── -->
                <div class="analytics-table-card">
                    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                        <div>
                            <h5 class="mb-0 font-weight-bold" style="color:#0f172a; font-size:1.05rem;">
                                <i class="fas fa-list-check text-primary mr-1"></i> Scheduled Notifications List
                            </h5>
                            <p style="font-size:0.8rem; color:#64748b; margin:2px 0 0 0;">
                                Manage scheduled monthly reminders, edit messages, toggle active status, or delete.
                            </p>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover" id="notifTable">
                            <thead>
                                <tr>
                                    <th style="text-align:left;">Target Employee</th>
                                    <th style="text-align:left;">Notification Message</th>
                                    <th>Created At</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- JS -->
<script src="../vendors/js/vendor.bundle.base.js"></script>
<script src="../vendors/chart.js/Chart.min.js"></script>
<script src="../vendors/datatables.net/jquery.dataTables.js"></script>
<script src="../vendors/datatables.net-bs4/dataTables.bootstrap4.js"></script>
<script src="https://cdn.datatables.net/responsive/2.4.1/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="../js/off-canvas.js"></script>
<script src="../js/hoverable-collapse.js"></script>
<script src="../js/template.js"></script>
<script src="../js/settings.js"></script>

<script>
$(function(){

    $('.js-example-basic-multiple').select2({ placeholder: '-- Search & Select Employees --', width:'100%' });

    loadTable();

    // 1. Status Doughnut Chart
    var ctxStatus = document.getElementById('notifStatusChart').getContext('2d');
    new Chart(ctxStatus, {
        type: 'doughnut',
        data: {
            labels: ['Active Reminders', 'Paused Reminders'],
            datasets: [{
                data: [<?= $activeNotifs ?>, <?= $inactiveNotifs ?>],
                backgroundColor: ['#10b981', '#f59e0b'],
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            legend: { position: 'right', labels: { boxWidth: 12, fontSize: 11 } }
        }
    });

    // 2. Role Breakdown Bar Chart
    var ctxRole = document.getElementById('roleBreakdownChart').getContext('2d');
    new Chart(ctxRole, {
        type: 'bar',
        data: {
            labels: ['Junior Intern', 'Senior Intern', 'Developer / Employee'],
            datasets: [{
                label: 'Reminders Configured',
                data: [
                    <?= $roleCounts['Junior Intern'] ?>,
                    <?= $roleCounts['Senior Intern'] ?>,
                    <?= $roleCounts['Developer / Employee'] ?>
                ],
                backgroundColor: ['#3b82f6', '#8b5cf6', '#06b6d4'],
                borderRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            legend: { display: false },
            scales: {
                xAxes: [{ gridLines: { display: false } }],
                yAxes: [{ ticks: { beginAtZero: true, stepSize: 1 } }]
            }
        }
    });

    function loadTable(){
        $.get('../ajax/fetch_client_call_notifications.php', function(data){
            if($.fn.DataTable.isDataTable('#notifTable')){
                $('#notifTable').DataTable().destroy();
            }
            $('#notifTable tbody').html(data);
            $('#notifTable').DataTable({
                responsive: true,
                order: [],
                language: { search: "<i class='fas fa-search text-muted mr-1'></i> Search Scheduled Notifications:" }
            });
        });
    }

    $('#clientCallForm').submit(function(e){
        e.preventDefault();

        $.post('../ajax/client_call_notification.php', $(this).serialize(), function(res){
            if (res === 'OK') {
                Swal.fire('Success', 'Notification saved successfully', 'success');

                $('#clientCallForm')[0].reset();
                $('#employees').val(null).trigger('change');
                $('#notification_id').val('');
                $('#submitBtn').html('<i class="fas fa-save mr-1"></i> Save Client Call Notification');

                loadTable();
            } else {
                Swal.fire('Error', res, 'error');
            }
        });
    });

    window.toggleStatus = function(id, status){
        $.post('../ajax/toggle_client_call.php', {id, status}, loadTable);
    };

    window.editNotification = function (id, userId, message) {
        $('#notification_id').val(id);
        $('#message').val(message);
        $('#employees').val([userId]).trigger('change');
        $('#submitBtn').html('<i class="fas fa-edit mr-1"></i> Update Notification');

        $('html, body').animate({
            scrollTop: $('#clientCallForm').offset().top - 100
        }, 400);
    };
});
</script>

<?php
if (isset($_SESSION['status']) && isset($_SESSION['msg'])) {
    $status = $_SESSION['status'];
    $msg = $_SESSION['msg'];
?>
<script>
window.onload = function () {
    Swal.fire({
        icon: '<?= $status ? "success" : "error" ?>',
        title: '<?= $status ? "Success" : "Error" ?>',
        text: '<?= addslashes($msg) ?>',
        confirmButtonColor: '#4f46e5'
    });
};
</script>
<?php
    unset($_SESSION['status']);
    unset($_SESSION['msg']);
}
?>
</body>
</html>
