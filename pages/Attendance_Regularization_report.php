<?php
session_start();
if (!isset($_SESSION['userId']) && empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}
include('../include/db_config.php');
require_once('../include/late_mark_helper.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $id     = mysqli_real_escape_string($con, $_POST['id'] ?? '');
    $action = strtolower(trim($_POST['action']));

    if ($id === '') { 
        $_SESSION['msg']    = "Invalid regularization request.";
        $_SESSION['status'] = false;
    } elseif ($action == 'approve') {
        $req_sql = "SELECT * FROM attendance_regularization WHERE id = '$id'";
        $req_res = mysqli_query($con, $req_sql);

        if (mysqli_num_rows($req_res) > 0) {
            $req_row  = mysqli_fetch_assoc($req_res);
            $user_id  = $req_row['user_id'];
            $date     = $req_row['date'];
            $punch_in = $req_row['punch_in'];

            // 1. Update status in attendance_regularization
            $update_sql = "UPDATE attendance_regularization SET status = 'Approved' WHERE id = '$id'";
            $update_res = mysqli_query($con, $update_sql);

            if ($update_res) {

                // Fetch user's expected punch in & working hours
                $q = mysqli_query($con, "SELECT totalWorkingHours, expectedPunchIn FROM signup WHERE userId = '$user_id'");
                $r = mysqli_fetch_assoc($q);
                $actualWorkingHours = $r['totalWorkingHours']  ?? '09:00:00';
                $expectedPunchIn    = $r['expectedPunchIn']    ?? '09:30:00';

                // Recalculate lateMark based on regularized punch_in time
                $lateEval = evaluateLateMarkPolicy($punch_in, $expectedPunchIn, $date);
                $lateMark = $lateEval['lateMark'];
                $regStatus = $lateEval['isHalfDay'] ? 'Half Day' : 'Present';
                $dateTime = date('Y-m-d H:i:s');

                // 2. Check if attendance record already exists for that date
                $attCheck = mysqli_query($con, "SELECT id FROM attendance WHERE userId='$user_id' AND date='$date'");

                if (mysqli_num_rows($attCheck) > 0) {
                    $update_att = mysqli_query($con, "
                        UPDATE attendance
                        SET punchInTime = '$punch_in',
                            lateMark    = '$lateMark',
                            status      = '$regStatus'
                        WHERE userId = '$user_id' AND date = '$date'
                    ");
                    $insert_res = $update_att;
                } else {
                    $insert_res = mysqli_query($con, "
                        INSERT INTO attendance (
                            date, userId, punchInTime, lateMark, manHours,
                            actualManHour, dateTime, punchOutTime, status
                        ) VALUES (
                            '$date', '$user_id', '$punch_in', '$lateMark', '00:00:00',
                            '$actualWorkingHours', '$dateTime', '00:00:00', '$regStatus'
                        )
                    ") or die($con->error);
                }

                if ($insert_res) {
                    $_SESSION['msg']    = "Request Approved Successfully";
                    $_SESSION['status'] = true;
                } else {
                    $_SESSION['msg']    = "There is a technical issue while approving the request, please try again later.";
                    $_SESSION['status'] = false;
                }

            } else {
                $_SESSION['msg']    = "Failed to update request status";
                $_SESSION['status'] = false;
            }
        } else {
            $_SESSION['msg']    = "Regularization request not found.";
            $_SESSION['status'] = false;
        }

    } elseif ($action == 'reject') {
        $hr_reason  = mysqli_real_escape_string($con, $_POST['hr_reason'] ?? '');
        $update_sql = "UPDATE attendance_regularization SET status = 'Rejected', hr_reason = '$hr_reason' WHERE id = '$id'";
        if (mysqli_query($con, $update_sql)) {
            $_SESSION['msg']    = "Request Rejected";
            $_SESSION['status'] = true;
        } else {
            $_SESSION['msg']    = "Failed to reject request";
            $_SESSION['status'] = false;
        }
    } else {
        $_SESSION['msg']    = "Invalid action.";
        $_SESSION['status'] = false;
    }

    echo "<script>window.location.href = 'Attendance_Regularization_report.php';</script>";
    exit;
}

/* -----------------------------------------------------
   📊 REAL-TIME REGULARIZATION ANALYTICS QUERY
----------------------------------------------------- */
$statsSql = "SELECT 
    COUNT(*) AS total,
    SUM(CASE WHEN LOWER(TRIM(status)) = 'pending' OR status IS NULL OR status = '' THEN 1 ELSE 0 END) AS pending,
    SUM(CASE WHEN LOWER(TRIM(status)) = 'approved' THEN 1 ELSE 0 END) AS approved,
    SUM(CASE WHEN LOWER(TRIM(status)) = 'rejected' THEN 1 ELSE 0 END) AS rejected
FROM attendance_regularization";

$statsRes = mysqli_query($con, $statsSql);
$stats = mysqli_fetch_assoc($statsRes) ?? ['total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0];

$totalCount    = intval($stats['total']);
$pendingCount  = intval($stats['pending']);
$approvedCount = intval($stats['approved']);
$rejectedCount = intval($stats['rejected']);

$approvedPct = $totalCount > 0 ? round(($approvedCount / $totalCount) * 100, 1) : 0;
$pendingPct  = $totalCount > 0 ? round(($pendingCount / $totalCount) * 100, 1) : 0;
$rejectedPct = $totalCount > 0 ? round(($rejectedCount / $totalCount) * 100, 1) : 0;

$filterStatus = isset($_GET['status']) ? trim($_GET['status']) : '';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>Attendance Regularization Management & Analytics</title>
    <?php include('../partials/_links.php'); ?>
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.4.1/css/responsive.bootstrap4.min.css">
    <script src="https://code.jquery.com/jquery-3.5.1.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

    <style>
        body {
            background: #f4f6fb;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
        }

        .stat-card-box {
            background: #ffffff;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 12px rgba(0,0,0,0.03);
            transition: all 0.2s ease-in-out;
        }

        .stat-card-box:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(16, 61, 158, 0.1);
        }

        .stat-card-box.active-filter {
            border: 2px solid #103d9e !important;
            background: #f0f4ff !important;
        }

        .icon-circle {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }

        .font-11 { font-size: 11px; }
        .font-12 { font-size: 12px; }
        .font-13 { font-size: 13px; }

        #regularization_table thead {
            background: #103d9e !important;
            color: #ffffff !important;
        }

        #regularization_table thead th {
            font-weight: 600;
            font-size: 13px;
            border: none;
            padding: 12px 14px;
            color: #ffffff !important;
        }

        #regularization_table td:nth-child(4),
        #regularization_table td:nth-child(7) {
            max-width: 240px;
            white-space: normal !important;
            word-wrap: break-word;
            overflow-wrap: break-word;
            line-height: 1.4;
        }

        .badge-success { background: #d1fae5; color: #065f46; border-radius: 20px; padding: 4px 10px; font-weight: 700; }
        .badge-danger  { background: #fee2e2; color: #991b1b; border-radius: 20px; padding: 4px 10px; font-weight: 700; }
        .badge-warning { background: #fef3c7; color: #92400e; border-radius: 20px; padding: 4px 10px; font-weight: 700; }
    </style>
</head>

<body>
    <div class="container-scroller">
        <?php include('../partials/_navbar.php') ?>

        <div class="container-fluid page-body-wrapper">
            <?php
            include('../partials/_sidebar.php');
            include('../partials/_settings-panel.php');
            ?>
            <div class="main-panel">
                <div class="content-wrapper">

                    <!-- ── HEADER CARD ── -->
                    <div class="card mb-4 border-0 shadow-sm" style="border-radius: 12px; background: #ffffff;">
                        <div class="card-body p-4">
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <div>
                                    <h4 class="font-weight-bold mb-1" style="color: #103d9e;">
                                        <i class="fa-solid fa-calendar-check me-2"></i>Attendance Regularization Approval & Analytics
                                    </h4>
                                    <p class="text-muted font-13 mb-0">Review, verify, approve, and manage employee attendance regularization requests with real-time analytics.</p>
                                </div>
                                <?php if (isset($_GET['back'])) { ?>
                                    <a href="adminDashboard.php" class="btn btn-sm btn-outline-primary" style="border-radius: 6px;">
                                        <i class="fa-solid fa-arrow-left me-1"></i> Back to Dashboard
                                    </a>
                                <?php } ?>
                            </div>
                        </div>
                    </div>

                    <!-- 📊 ANALYTICS STAT CARDS -->
                    <div class="row mb-4 g-3">
                        <!-- Card 1: Total Requests -->
                        <div class="col-xl-3 col-md-6">
                            <a href="Attendance_Regularization_report.php" class="text-decoration-none">
                                <div class="stat-card-box p-3">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <span class="text-uppercase text-muted font-11 font-weight-bold">Total Requests</span>
                                        <div class="icon-circle bg-light text-primary">
                                            <i class="fa-solid fa-calendar-days"></i>
                                        </div>
                                    </div>
                                    <h3 class="font-weight-bold text-dark mb-1"><?= number_format($totalCount) ?></h3>
                                    <div class="d-flex align-items-center font-12 text-muted gap-2">
                                        <span class="badge badge-light border text-dark font-11"><i class="fa-solid fa-clock text-warning me-1"></i>Pending: <?= $pendingCount ?></span>
                                        <span class="badge badge-light border text-dark font-11"><i class="fa-solid fa-check text-success me-1"></i>Approved: <?= $approvedCount ?></span>
                                    </div>
                                </div>
                            </a>
                        </div>

                        <!-- Card 2: Pending Action -->
                        <div class="col-xl-3 col-md-6">
                            <a href="Attendance_Regularization_report.php?status=Pending" class="text-decoration-none">
                                <div class="stat-card-box p-3 <?= strtolower($filterStatus) == 'pending' ? 'active-filter' : '' ?>">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <span class="text-uppercase text-warning font-11 font-weight-bold">Pending Action</span>
                                        <div class="icon-circle text-warning" style="background:#fffbeb;">
                                            <i class="fa-solid fa-hourglass-half"></i>
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-baseline gap-2 mb-1">
                                        <h3 class="font-weight-bold text-dark mb-0"><?= number_format($pendingCount) ?></h3>
                                        <?php if ($pendingCount > 0): ?>
                                            <span class="badge badge-warning text-dark font-11">Action Required</span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="font-12 text-muted mb-0">
                                        <strong><?= $pendingPct ?>%</strong> of total awaiting approval
                                    </p>
                                </div>
                            </a>
                        </div>

                        <!-- Card 3: Approved Requests -->
                        <div class="col-xl-3 col-md-6">
                            <a href="Attendance_Regularization_report.php?status=Approved" class="text-decoration-none">
                                <div class="stat-card-box p-3 <?= strtolower($filterStatus) == 'approved' ? 'active-filter' : '' ?>">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <span class="text-uppercase text-success font-11 font-weight-bold">Approved Requests</span>
                                        <div class="icon-circle text-success" style="background:#ecfdf5;">
                                            <i class="fa-solid fa-circle-check"></i>
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-baseline gap-2 mb-1">
                                        <h3 class="font-weight-bold text-dark mb-0"><?= number_format($approvedCount) ?></h3>
                                        <span class="badge badge-success font-11"><?= $approvedPct ?>% Rate</span>
                                    </div>
                                    <p class="font-12 text-muted mb-0">
                                        Attendance updated & verified
                                    </p>
                                </div>
                            </a>
                        </div>

                        <!-- Card 4: Rejected Requests -->
                        <div class="col-xl-3 col-md-6">
                            <a href="Attendance_Regularization_report.php?status=Rejected" class="text-decoration-none">
                                <div class="stat-card-box p-3 <?= strtolower($filterStatus) == 'rejected' ? 'active-filter' : '' ?>">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <span class="text-uppercase text-danger font-11 font-weight-bold">Rejected Requests</span>
                                        <div class="icon-circle text-danger" style="background:#fff1f2;">
                                            <i class="fa-solid fa-circle-xmark"></i>
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-baseline gap-2 mb-1">
                                        <h3 class="font-weight-bold text-dark mb-0"><?= number_format($rejectedCount) ?></h3>
                                        <span class="badge badge-danger font-11"><?= $rejectedPct ?>%</span>
                                    </div>
                                    <p class="font-12 text-muted mb-0">
                                        Declined regularization requests
                                    </p>
                                </div>
                            </a>
                        </div>
                    </div>

                    <!-- 📊 STATUS BREAKDOWN PROGRESS BAR -->
                    <?php if ($totalCount > 0): ?>
                    <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px; background: #ffffff;">
                        <div class="card-body p-3">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="font-weight-bold font-13 text-dark"><i class="fa-solid fa-chart-pie me-2 text-primary"></i>Regularization Request Status Ratio</span>
                                <span class="font-12 text-muted">Total: <strong><?= $totalCount ?></strong> Applications</span>
                            </div>
                            <div class="progress" style="height: 10px; border-radius: 6px; background: #e2e8f0;">
                                <div class="progress-bar bg-success" role="progressbar" style="width: <?= $approvedPct ?>%" title="Approved: <?= $approvedCount ?> (<?= $approvedPct ?>%)"></div>
                                <div class="progress-bar bg-warning" role="progressbar" style="width: <?= $pendingPct ?>%" title="Pending: <?= $pendingCount ?> (<?= $pendingPct ?>%)"></div>
                                <div class="progress-bar bg-danger" role="progressbar" style="width: <?= $rejectedPct ?>%" title="Rejected: <?= $rejectedCount ?> (<?= $rejectedPct ?>%)"></div>
                            </div>
                            <div class="d-flex justify-content-between font-12 text-muted mt-2 flex-wrap gap-2">
                                <span><i class="fa-solid fa-circle text-success me-1 font-11"></i> Approved: <strong><?= $approvedCount ?></strong> (<?= $approvedPct ?>%)</span>
                                <span><i class="fa-solid fa-circle text-warning me-1 font-11"></i> Pending: <strong><?= $pendingCount ?></strong> (<?= $pendingPct ?>%)</span>
                                <span><i class="fa-solid fa-circle text-danger me-1 font-11"></i> Rejected: <strong><?= $rejectedCount ?></strong> (<?= $rejectedPct ?>%)</span>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- 🔍 FILTER BAR & TABLE CARD -->
                    <div class="card border-0 shadow-sm" style="border-radius: 12px; background: #ffffff;">
                        <div class="card-body p-4">

                            <!-- Filter Bar -->
                            <form method="GET" class="mb-4 p-3 rounded" style="background: #f8fafc; border: 1px solid #e2e8f0;">
                                <div class="row align-items-end g-3">
                                    <div class="col-md-6">
                                        <label class="form-label font-weight-bold text-dark font-12 mb-1">
                                            <i class="fa-solid fa-filter text-muted me-1"></i> Filter by Request Status
                                        </label>
                                        <select name="status" class="form-control form-control-sm" style="border-radius: 6px; height: 38px;">
                                            <option value="">-- All Request Statuses --</option>
                                            <option value="Pending" <?= strtolower($filterStatus) == 'pending' ? 'selected' : '' ?>>Pending</option>
                                            <option value="Approved" <?= strtolower($filterStatus) == 'approved' ? 'selected' : '' ?>>Approved</option>
                                            <option value="Rejected" <?= strtolower($filterStatus) == 'rejected' ? 'selected' : '' ?>>Rejected</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6 d-flex gap-2">
                                        <button type="submit" class="btn btn-primary btn-sm px-4 flex-grow-1" style="background: #103d9e; border: none; height: 38px; border-radius: 6px;">
                                            <i class="fa-solid fa-filter me-1"></i> Apply Filter
                                        </button>
                                        <a href="Attendance_Regularization_report.php" class="btn btn-light btn-sm border px-3" style="height: 38px; border-radius: 6px; display: inline-flex; align-items: center; justify-content: center;" title="Reset Filter">
                                            <i class="fa-solid fa-rotate-right me-1"></i> Reset
                                        </a>
                                    </div>
                                </div>
                            </form>

                            <?php
                            $whereStatus = "";
                            if (!empty($filterStatus)) {
                                $escStatus = mysqli_real_escape_string($con, strtolower($filterStatus));
                                $whereStatus = "WHERE LOWER(TRIM(status)) = '$escStatus'";
                            }

                            $sql    = "SELECT * FROM attendance_regularization $whereStatus ORDER BY created_at DESC";
                            $result = mysqli_query($con, $sql) or die("Query failed: " . mysqli_error($con));
                            ?>

                            <div class="table-responsive">
                                <table class="table table-hover table-bordered align-middle" style="width:100%" id="regularization_table">
                                    <thead>
                                        <tr>
                                            <th style="width: 35px;">#</th>
                                            <th>Date</th>
                                            <th>Employee / Intern</th>
                                            <th>Reason</th>
                                            <th>Punch In</th>
                                            <th>Status</th>
                                            <th>HR Reason</th>
                                            <th>Created At</th>
                                            <th class="text-center" style="width: 140px;">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $i = 1;
                                        while ($res = mysqli_fetch_assoc($result)) {
                                            $q        = mysqli_query($con, "SELECT fullName FROM signup WHERE userId = '{$res['user_id']}'");
                                            $r        = mysqli_fetch_assoc($q);
                                            $fullName = $r['fullName'] ?? 'User Not Found';
                                            $requestStatus = strtolower(trim($res['status'] ?? 'pending'));
                                            ?>
                                            <tr>
                                                <td class="font-13 text-muted"><?php echo $i++; ?></td>
                                                <td class="font-13 font-weight-bold"><?php echo date('d-m-Y', strtotime($res['date'])); ?></td>
                                                <td><strong class="text-dark"><?php echo htmlspecialchars($fullName); ?></strong></td>
                                                <td class="font-13"><?php echo htmlspecialchars($res['reason']); ?></td>
                                                <td class="font-13"><span class="badge badge-light border text-dark"><?php echo htmlspecialchars($res['punch_in']); ?></span></td>
                                                <td>
                                                    <?php
                                                    if ($requestStatus == 'approved') {
                                                        echo '<span class="badge badge-success"><i class="fa-solid fa-circle-check me-1"></i>Approved</span>';
                                                    } elseif ($requestStatus == 'rejected') {
                                                        echo '<span class="badge badge-danger"><i class="fa-solid fa-circle-xmark me-1"></i>Rejected</span>';
                                                    } else {
                                                        echo '<span class="badge badge-warning"><i class="fa-solid fa-clock me-1"></i>Pending</span>';
                                                    }
                                                    ?>
                                                </td>
                                                <td class="font-13"><?php echo htmlspecialchars($res['hr_reason'] ?? '-'); ?></td>
                                                <td class="font-13"><?php echo date('d-m-Y H:i', strtotime($res['created_at'])); ?></td>
                                                <td class="text-center">
                                                    <?php if ($requestStatus == 'pending') { ?>
                                                        <button type="button" class="btn btn-success btn-sm py-1 px-2 font-12 me-1" style="border-radius: 4px;"
                                                            onclick="confirmAction('<?php echo htmlspecialchars($res['id'], ENT_QUOTES); ?>', 'approve')">
                                                            <i class="fa-solid fa-check me-1"></i> Approve
                                                        </button>
                                                        <button type="button" class="btn btn-danger btn-sm py-1 px-2 font-12" style="border-radius: 4px;"
                                                            onclick="confirmAction('<?php echo htmlspecialchars($res['id'], ENT_QUOTES); ?>', 'reject')">
                                                            <i class="fa-solid fa-xmark me-1"></i> Reject
                                                        </button>
                                                    <?php } else {
                                                        echo '<span class="text-muted font-12"><i class="fa-solid fa-minus"></i></span>';
                                                    } ?>
                                                </td>
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
        </div>
    </div>

    <script src="https://cdn.datatables.net/responsive/2.4.1/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.4.1/js/responsive.bootstrap4.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.flash.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>

    <script>
        $(document).ready(function () {
            $('#regularization_table').DataTable({
                dom: '<"d-flex justify-content-between align-items-center mb-3"B l f>rt<"d-flex justify-content-between mt-3"i p>',
                responsive: true,
                buttons: [
                    {
                        extend: 'excelHtml5',
                        text: '<i class="fa-solid fa-file-excel me-1"></i> Export Excel',
                        className: 'btn btn-success btn-sm font-12'
                    },
                ],
                columnDefs: [
                    { orderable: false, targets: 8 }
                ],
                language: {
                    paginate: { previous: '&laquo; Prev', next: 'Next &raquo;' },
                    lengthMenu: 'Show _MENU_ entries',
                    search: 'Search:'
                },
                pagingType: 'simple_numbers'
            });
        });

        function confirmAction(id, action) {
            if (action === "reject") {
                Swal.fire({
                    title: "Reject Request",
                    text: "Please provide a reason for rejection:",
                    input: "textarea",
                    inputPlaceholder: "Enter reason here...",
                    showCancelButton: true,
                    confirmButtonColor: "#dc3545",
                    cancelButtonColor: "#6c757d",
                    confirmButtonText: "Reject",
                    inputValidator: (value) => {
                        if (!value.trim()) return "You must enter a reason!";
                    }
                }).then((result) => {
                    if (result.isConfirmed) submitForm(id, action, result.value);
                });
            } else {
                Swal.fire({
                    title: "Approve Request?",
                    text: "Are you sure you want to approve this regularization request?",
                    icon: "warning",
                    showCancelButton: true,
                    confirmButtonColor: "#28a745",
                    cancelButtonColor: "#6c757d",
                    confirmButtonText: "Yes, Approve"
                }).then((result) => {
                    if (result.isConfirmed) submitForm(id, action, "");
                });
            }
        }

        function submitForm(id, action, reason = "") {
            let form = document.createElement("form");
            form.method = "POST";
            form.action = "Attendance_Regularization_report.php";

            let idInput = document.createElement("input");
            idInput.type = "hidden";
            idInput.name = "id";
            idInput.value = id;
            form.appendChild(idInput);

            let actionInput = document.createElement("input");
            actionInput.type = "hidden";
            actionInput.name = "action";
            actionInput.value = action;
            form.appendChild(actionInput);

            if (action === "reject") {
                let reasonInput = document.createElement("input");
                reasonInput.type = "hidden";
                reasonInput.name = "hr_reason";
                reasonInput.value = reason;
                form.appendChild(reasonInput);
            }

            document.body.appendChild(form);
            form.submit();
        }
    </script>

    <?php
    require_once('../include/sweetAlert.php');
    if (isset($_SESSION['msg'])) {
        $status = $_SESSION['status'];
        $msg    = $_SESSION['msg'];
        if ($status != true) { toastMsg('error', $msg); } else { toastMsg('success', $msg); }
        unset($_SESSION['status'], $_SESSION['msg']);
    }
    ?>
</body>

</html>
