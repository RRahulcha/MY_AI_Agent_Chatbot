<?php
session_start();

// ✅ Session Check
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href='login.php';</script>";
    exit;
}
if ($_SESSION['userRole'] !== 'admin' && $_SESSION['designation'] !== 'hr') {
    echo "<script>alert('Access Denied! Admins only.'); window.location.href='login.php';</script>";
    exit;
}

/* 🔐 ADMIN GUARD FOR API CALLS */
function requireAdmin()
{
    if (!isset($_SESSION['userRole']) || 
       ($_SESSION['userRole'] !== 'admin' && $_SESSION['designation'] !== 'hr')) {
        http_response_code(403);
        die("Unauthorized action");
    }
}
// ✅ Database connection
include('../include/db_config.php');
if (!$con) {
    die("Database connection failed: " . mysqli_connect_error());
}

function calculate_man_hours_time_format(string $shift_in_str, string $shift_out_str, int $break_minutes = 0): string
{
    $shift_in = DateTime::createFromFormat('H:i', $shift_in_str);
    $shift_out = DateTime::createFromFormat('H:i', $shift_out_str);

    if ($shift_in === false || $shift_out === false) {
        return '';
    }

    if ($shift_out < $shift_in) {
        $shift_out->modify('+1 day');
    }

    $interval = $shift_in->diff($shift_out);
    $total_minutes = $interval->days * 1440;
    $total_minutes += $interval->h * 60;
    $total_minutes += $interval->i;
    $total_minutes -= $break_minutes;

    if ($total_minutes < 0) {
        $total_minutes = 0;
    }

    $hours = floor($total_minutes / 60);
    $minutes = $total_minutes % 60;
    $seconds = 0;

    return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
}

if (isset($_GET['calculate'])) {
    $shift_in = "10:00:00";
    $shift_out = "18:00:00";
    $man_hours = calculate_man_hours_time_format($shift_in, $shift_out);
    echo $man_hours;
    exit();
}

/* -----------------------------------------------------
   ✅ Approve Logic (update signup table only)
----------------------------------------------------- */
if (isset($_POST['approve'])) {
    requireAdmin();

    $signup_id = $_POST['signup_id'];
    $emp_id = $_POST['emp_id'];
    $start_date = $_POST['start_date'];
    $end_date = isset($_POST['end_date']) ? $_POST['end_date'] : NULL;
    $shift_in = $_POST['shift_in'];
    $shift_out = $_POST['shift_out'];
    $medical_leave = isset($_POST['medical_leave']) ? $_POST['medical_leave'] : 4;
    $casual_leave = isset($_POST['casual_leave']) ? $_POST['casual_leave'] : 4;
    $privilege_leave = isset($_POST['privilege_leave']) ? $_POST['privilege_leave'] : 5;
    $remarks = isset($_POST['remarks']) ? $_POST['remarks'] : NULL;
    $totalWorkingHours = calculate_man_hours_time_format($shift_in, $shift_out);

    $updateQuery = "UPDATE signup 
                SET regstatus = 'Approved', 
                    emp_id = '$emp_id', 
                    start_date = '$start_date', 
                    end_date = '$end_date', 
                    expectedPunchIn = '$shift_in', 
                    expectedPunchOut = '$shift_out', 
                    SL = '$medical_leave',
                    CL = '$casual_leave',
                    PL = '$privilege_leave',
                    remarks = '$remarks',
                    totalWorkingHours = '$totalWorkingHours' 
                WHERE srNo = '$signup_id'";

    if (mysqli_query($con, $updateQuery)) {
        $_SESSION['popup'] = ['type' => 'success', 'message' => 'Employee Approved Successfully!'];

        include('../include/notifications.php');

        $tokenQuery = mysqli_query($con, "SELECT token, userId, fullName FROM signup WHERE srNo='$signup_id'");
        if ($tokenQuery && mysqli_num_rows($tokenQuery) > 0) {
            $tokenRow = mysqli_fetch_assoc($tokenQuery);
            $fcmToken = $tokenRow['token'];
            $userId = $tokenRow['userId'];
            $fullName = $tokenRow['fullName'] ?: 'User';
        } else {
            $fcmToken = null;
            $fullName = 'User';
        }

        if (!empty($fcmToken)) {
            $title = "Account Approved 🎉";
            $body = "Hello $fullName! Your account has been approved! Your Employee ID is $emp_id. Now you can login to AMS system.";

            $response = sendFCMNotification($fcmToken, $title, $body, $accessToken, $projectId);
            if (!empty($response)) {
                $stmt = $con->prepare("INSERT INTO notifications (userId, title, message, status, is_read) VALUES (?, ?, ?, ?, 0)");
                $status = 'sent';
                $stmt->bind_param('ssss', $userId, $title, $body, $status);
                $stmt->execute();
            }
        }

        echo "<script>window.location.href = 'user_register.php';</script>";
        exit;
    } else {
        $_SESSION['popup'] = ['type' => 'error', 'message' => 'Error Approving Employee: ' . mysqli_error($con)];
    }
}

/* -----------------------------------------------------
   ✅ Reject Logic
----------------------------------------------------- */
if (isset($_GET['reject_id'])) {
    $reject_id = $_GET['reject_id'];
    $rejectQuery = "UPDATE signup SET regstatus='Rejected' WHERE srNo='$reject_id'";
    if (mysqli_query($con, $rejectQuery)) {
        $_SESSION['popup'] = ['type' => 'error', 'message' => 'User Rejected Successfully!'];
        header("Location: user_register.php");
        exit;
    } else {
        $_SESSION['popup'] = ['type' => 'error', 'message' => 'Error Rejecting User: ' . mysqli_error($con)];
    }
}

/* -----------------------------------------------------
   ✅ BULK APPROVE
----------------------------------------------------- */
if (isset($_POST['bulk_approve']) && !empty($_POST['ids'])) {
    requireAdmin();

    $ids = $_POST['ids'];
    $idList = implode(",", array_map('intval', $ids));

    $query = "UPDATE signup SET regstatus = 'Approved' WHERE srNo IN ($idList)";

    if (mysqli_query($con, $query)) {
        $_SESSION['popup'] = [
            'type' => 'success',
            'message' => 'Selected users approved successfully!'
        ];
    } else {
        $_SESSION['popup'] = [
            'type' => 'error',
            'message' => 'Bulk approve failed: ' . mysqli_error($con)
        ];
    }

    echo "OK";
    exit;
}

/* -----------------------------------------------------
   ✅ BULK REJECT
----------------------------------------------------- */
if (isset($_POST['bulk_reject']) && !empty($_POST['ids'])) {
    requireAdmin();

    $ids = $_POST['ids'];
    $idList = implode(",", array_map('intval', $ids));

    $query = "UPDATE signup SET regstatus = 'Rejected' WHERE srNo IN ($idList)";

    if (mysqli_query($con, $query)) {
        $_SESSION['popup'] = [
            'type' => 'success',
            'message' => 'Selected users rejected successfully!'
        ];
    } else {
        $_SESSION['popup'] = [
            'type' => 'error',
            'message' => 'Bulk reject failed: ' . mysqli_error($con)
        ];
    }

    echo "OK";
    exit;
}

/* -----------------------------------------------------
   📊 REAL-TIME ANALYTICS STATS QUERY
----------------------------------------------------- */
$statsSql = "SELECT 
    COUNT(*) AS total,
    SUM(CASE WHEN regstatus = 'Pending' OR regstatus IS NULL OR regstatus = '' THEN 1 ELSE 0 END) AS pending,
    SUM(CASE WHEN regstatus = 'Approved' THEN 1 ELSE 0 END) AS approved,
    SUM(CASE WHEN regstatus = 'Rejected' THEN 1 ELSE 0 END) AS rejected,
    SUM(CASE WHEN type = 'Online' THEN 1 ELSE 0 END) AS online,
    SUM(CASE WHEN type = 'Offline' THEN 1 ELSE 0 END) AS offline
FROM signup 
WHERE (designation IS NULL OR (LOWER(designation) != 'admin' AND LOWER(designation) != 'developer'))";

$statsRes = mysqli_query($con, $statsSql);
$stats = mysqli_fetch_assoc($statsRes) ?? ['total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0, 'online' => 0, 'offline' => 0];

$totalCount    = intval($stats['total']);
$pendingCount  = intval($stats['pending']);
$approvedCount = intval($stats['approved']);
$rejectedCount = intval($stats['rejected']);
$onlineCount   = intval($stats['online']);
$offlineCount  = intval($stats['offline']);

$approvedPct = $totalCount > 0 ? round(($approvedCount / $totalCount) * 100, 1) : 0;
$pendingPct  = $totalCount > 0 ? round(($pendingCount / $totalCount) * 100, 1) : 0;
$rejectedPct = $totalCount > 0 ? round(($rejectedCount / $totalCount) * 100, 1) : 0;
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>User Registration Management & Analytics</title>
    <?php include('../partials/_links.php'); ?>
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

        #recordsTable thead {
            background: #103d9e !important;
            color: #ffffff !important;
        }

        #recordsTable thead th {
            font-weight: 600;
            font-size: 13px;
            border: none;
            padding: 12px 14px;
            color: #ffffff !important;
        }

        td.details-control {
            background: url('https://www.datatables.net/examples/resources/details_open.png') no-repeat center center;
            cursor: pointer;
            width: 24px;
        }

        tr.shown td.details-control {
            background: url('https://www.datatables.net/examples/resources/details_close.png') no-repeat center center;
        }

        /* Popup Overlay */
        #popup-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(15, 23, 42, 0.55);
            z-index: 9998;
        }

        /* Popup Box */
        #popup {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 95%;
            max-width: 540px;
            background: #fff;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, .25);
            z-index: 9999;
            max-height: 85vh;
            overflow-y: auto;
        }

        #popup h5 {
            font-weight: 700;
            color: #103d9e;
            margin-bottom: 18px;
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

                    <!-- ── TOP HEADER CARD ── -->
                    <div class="card mb-4 border-0 shadow-sm" style="border-radius: 12px; background: #ffffff;">
                        <div class="card-body p-4">
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <div>
                                    <h4 class="font-weight-bold mb-1" style="color: #103d9e;">
                                        <i class="fa-solid fa-user-gear me-2"></i>User Registration & Approval Management
                                    </h4>
                                    <p class="text-muted font-13 mb-0">Review, verify, approve, and manage new employee and intern onboarding registrations with real-time statistics.</p>
                                </div>
                                <?php if (isset($_GET['back'])) { ?>
                                    <a href="adminDashboard.php" class="btn btn-sm btn-outline-primary" style="border-radius: 6px;">
                                        <i class="fa-solid fa-arrow-left me-1"></i> Back to Dashboard
                                    </a>
                                <?php } ?>
                            </div>
                        </div>
                    </div>

                    <?php 
                    /* -----------------------------------------------------
                       ✅ Fetch data filters
                    ----------------------------------------------------- */
                    $mode = isset($_GET['type']) ? $_GET['type'] : '';
                    $status = isset($_GET['status']) ? $_GET['status'] : '';
                    
                    if (!empty($mode)) {
                        $where = " AND type = '$mode'";
                    } else {
                        $where = "";
                    }
                    
                    if (!empty($status)) {
                        $where .= " AND regstatus = '$status'";
                    }
                    
                    $sql = "SELECT * FROM signup WHERE 1 = 1 $where AND (designation IS NULL OR (designation != 'admin' AND designation != 'developer')) ORDER BY srNo DESC";
                    
                    $result = mysqli_query($con, $sql);
                    if (!$result) {
                        die("❌ Query Failed: " . mysqli_error($con));
                    }
                    $data = [];
                    while ($row = mysqli_fetch_assoc($result)) {
                        $data[] = $row;
                    }
                    ?>

                    <!-- 📊 ANALYTICS STAT CARDS -->
                    <div class="row mb-4 g-3">
                        <!-- Card 1: Total Registrations -->
                        <div class="col-xl-3 col-md-6">
                            <a href="user_register.php" class="text-decoration-none">
                                <div class="stat-card-box p-3">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <span class="text-uppercase text-muted font-11 font-weight-bold">Total Registrations</span>
                                        <div class="icon-circle bg-light text-primary">
                                            <i class="fa-solid fa-users"></i>
                                        </div>
                                    </div>
                                    <h3 class="font-weight-bold text-dark mb-1"><?= number_format($totalCount) ?></h3>
                                    <div class="d-flex align-items-center font-12 text-muted gap-2">
                                        <span class="badge badge-light border text-dark font-11"><i class="fa-solid fa-globe text-primary me-1"></i>Online: <?= $onlineCount ?></span>
                                        <span class="badge badge-light border text-dark font-11"><i class="fa-solid fa-building text-secondary me-1"></i>Offline: <?= $offlineCount ?></span>
                                    </div>
                                </div>
                            </a>
                        </div>

                        <!-- Card 2: Pending Approval -->
                        <div class="col-xl-3 col-md-6">
                            <a href="user_register.php?status=Pending" class="text-decoration-none">
                                <div class="stat-card-box p-3 <?= $status == 'Pending' ? 'active-filter' : '' ?>">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <span class="text-uppercase text-warning font-11 font-weight-bold">Pending Action</span>
                                        <div class="icon-circle bg-warning-light text-warning" style="background:#fffbeb;">
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
                                        <strong><?= $pendingPct ?>%</strong> of total awaiting review
                                    </p>
                                </div>
                            </a>
                        </div>

                        <!-- Card 3: Approved Accounts -->
                        <div class="col-xl-3 col-md-6">
                            <a href="user_register.php?status=Approved" class="text-decoration-none">
                                <div class="stat-card-box p-3 <?= $status == 'Approved' ? 'active-filter' : '' ?>">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <span class="text-uppercase text-success font-11 font-weight-bold">Approved Users</span>
                                        <div class="icon-circle bg-success-light text-success" style="background:#ecfdf5;">
                                            <i class="fa-solid fa-circle-check"></i>
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-baseline gap-2 mb-1">
                                        <h3 class="font-weight-bold text-dark mb-0"><?= number_format($approvedCount) ?></h3>
                                        <span class="badge badge-success font-11"><?= $approvedPct ?>% Rate</span>
                                    </div>
                                    <p class="font-12 text-muted mb-0">
                                        Active onboarded users
                                    </p>
                                </div>
                            </a>
                        </div>

                        <!-- Card 4: Rejected Requests -->
                        <div class="col-xl-3 col-md-6">
                            <a href="user_register.php?status=Rejected" class="text-decoration-none">
                                <div class="stat-card-box p-3 <?= $status == 'Rejected' ? 'active-filter' : '' ?>">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <span class="text-uppercase text-danger font-11 font-weight-bold">Rejected Requests</span>
                                        <div class="icon-circle bg-danger-light text-danger" style="background:#fff1f2;">
                                            <i class="fa-solid fa-circle-xmark"></i>
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-baseline gap-2 mb-1">
                                        <h3 class="font-weight-bold text-dark mb-0"><?= number_format($rejectedCount) ?></h3>
                                        <span class="badge badge-danger font-11"><?= $rejectedPct ?>%</span>
                                    </div>
                                    <p class="font-12 text-muted mb-0">
                                        Declined applications
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
                                <span class="font-weight-bold font-13 text-dark"><i class="fa-solid fa-chart-pie me-2 text-primary"></i>Registration Status Breakdown</span>
                                <span class="font-12 text-muted">Total Applications: <strong><?= $totalCount ?></strong></span>
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

                    <!-- 🔍 MAIN TABLE & FILTERS CARD -->
                    <div class="card border-0 shadow-sm" style="border-radius: 12px; background: #ffffff;">
                        <div class="card-body p-4">

                            <!-- Filter Bar -->
                            <form method="GET" class="mb-4 p-3 rounded" style="background: #f8fafc; border: 1px solid #e2e8f0;">
                                <div class="row align-items-end g-3">
                                    <div class="col-md-4">
                                        <label class="form-label font-weight-bold text-dark font-12 mb-1">
                                            <i class="fa-solid fa-laptop text-muted me-1"></i> Mode of Internship
                                        </label>
                                        <select name="type" class="form-control form-control-sm" style="border-radius: 6px; height: 38px;">
                                            <option value="">-- All Modes --</option>
                                            <option value="Online" <?= ($mode == 'Online' ? 'selected' : '') ?>>Online</option>
                                            <option value="Offline" <?= ($mode == 'Offline' ? 'selected' : '') ?>>Offline</option>
                                        </select>
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label font-weight-bold text-dark font-12 mb-1">
                                            <i class="fa-solid fa-list-check text-muted me-1"></i> Application Status
                                        </label>
                                        <select name="status" class="form-control form-control-sm" style="border-radius: 6px; height: 38px;">
                                            <option value="">-- All Statuses --</option>
                                            <option value="Approved" <?= ($status == 'Approved' ? 'selected' : '') ?>>Approved</option>
                                            <option value="Pending" <?= ($status == 'Pending' ? 'selected' : '') ?>>Pending</option>
                                            <option value="Rejected" <?= ($status == 'Rejected' ? 'selected' : '') ?>>Rejected</option>
                                        </select>
                                    </div>

                                    <div class="col-md-4 d-flex gap-2">
                                        <button type="submit" class="btn btn-primary btn-sm px-3 flex-grow-1" style="background: #103d9e; border: none; height: 38px; border-radius: 6px;">
                                            <i class="fa-solid fa-filter me-1"></i> Apply Filter
                                        </button>
                                        <a href="user_register.php" class="btn btn-light btn-sm border px-3" style="height: 38px; border-radius: 6px; display: inline-flex; align-items: center; justify-content: center;" title="Reset Filter">
                                            <i class="fa-solid fa-rotate-right me-1"></i> Reset
                                        </a>
                                    </div>
                                </div>
                            </form>

                            <!-- Bulk Actions Toolbar -->
                            <div class="d-flex justify-content-between align-items-center mb-3 pb-3 border-bottom flex-wrap gap-2">
                                <div class="d-flex align-items-center gap-2">
                                    <button type="button" class="btn btn-success btn-sm px-3" onclick="bulkApprove()" style="border-radius: 6px;">
                                        <i class="fa-solid fa-check-double me-1"></i> Bulk Approve
                                    </button>
                                    <button type="button" class="btn btn-outline-danger btn-sm px-3" onclick="bulkReject()" style="border-radius: 6px;">
                                        <i class="fa-solid fa-user-xmark me-1"></i> Bulk Reject
                                    </button>
                                    <span id="selectedCountBadge" class="badge badge-secondary font-12 d-none">0 Selected</span>
                                </div>
                                <div class="text-muted font-13">
                                    Showing <strong class="text-dark"><?= count($data) ?></strong> record(s)
                                </div>
                            </div>

                            <!-- ✅ Table -->
                            <div class="table-responsive">
                                <table id="recordsTable" class="table table-bordered table-hover align-middle" style="width:100%;">
                                    <thead>
                                        <tr>
                                            <th style="width: 25px;"></th>
                                            <th style="width: 25px;" class="text-center">
                                                <input type="checkbox" id="select_all">
                                            </th>
                                            <th style="width: 40px;">#</th>
                                            <th>Full Name</th>
                                            <th>Email</th>
                                            <th>Mobile</th>
                                            <th>Designation</th>
                                            <th>Mode</th>
                                            <th>Status</th>
                                            <th class="text-center" style="width: 160px;">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $i = 1;
                                        foreach ($data as $row) {
                                        ?>
                                            <tr data-id="<?= $i ?>">
                                                <td class="details-control"></td>
                                                <td class="text-center">
                                                    <input type="checkbox" class="row_checkbox" value="<?= $row['srNo']; ?>">
                                                </td>

                                                <td class="font-13 text-muted"><?= $i++; ?></td>
                                                <td class="font-weight-bold text-dark"><?= htmlspecialchars($row['fullName']); ?></td>
                                                <td class="font-13">
                                                    <?= ($row['email'] == '' ? '-' : htmlspecialchars($row['email'])); ?>
                                                </td>
                                                <td class="font-13">
                                                    <?= ($row['mobileNo'] == '0' || $row['mobileNo'] == '' ? '-' : htmlspecialchars($row['mobileNo'])); ?>
                                                </td>
                                                <td class="font-13"><span class="badge badge-light border text-dark font-11"><?= htmlspecialchars($row['designation'] ?: 'Applicant'); ?></span></td>
                                                <td class="font-13">
                                                    <?php if ($row['type'] == 'Online'): ?>
                                                        <span class="badge badge-info font-11 px-2 py-1"><i class="fa-solid fa-globe me-1"></i>Online</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-secondary font-11 px-2 py-1"><i class="fa-solid fa-building me-1"></i>Offline</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php
                                                    if ($row['regstatus'] == 'Pending' || empty($row['regstatus'])) {
                                                        echo "<span class='badge badge-warning text-dark font-12 px-2 py-1'><i class='fa-solid fa-clock me-1'></i>Pending</span>";
                                                    } elseif ($row['regstatus'] == 'Approved') {
                                                        echo "<span class='badge badge-success font-12 px-2 py-1'><i class='fa-solid fa-check me-1'></i>Approved</span>";
                                                    } else {
                                                        echo "<span class='badge badge-danger font-12 px-2 py-1'><i class='fa-solid fa-xmark me-1'></i>Rejected</span>";
                                                    }
                                                    ?>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($row['regstatus'] == 'Pending' || empty($row['regstatus'])) { ?>
                                                        <button class="btn btn-sm btn-success py-1 px-2 font-12 me-1" data-id="<?= $row['srNo'] ?>" onclick="openPopup(this.dataset.id)" style="border-radius: 4px;">
                                                            <i class="fa-solid fa-user-check me-1"></i> Approve
                                                        </button>
                                                        <button class="btn btn-sm btn-outline-danger py-1 px-2 font-12" onclick="confirmReject('<?= $row['srNo'] ?>')" style="border-radius: 4px;">
                                                            <i class="fa-solid fa-user-xmark me-1"></i> Reject
                                                        </button>
                                                    <?php } else { ?>
                                                        <span class="text-muted font-12"><i class="fa-solid fa-minus"></i></span>
                                                    <?php } ?>
                                                </td>
                                            </tr>
                                        <?php } ?>
                                    </tbody>
                                </table>
                            </div>

                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <!-- ✅ Popup Overlay -->
    <div id="popup-overlay" onclick="closePopup()"></div>

    <!-- ✅ Approve Popup -->
    <div id="popup">
        <button type="button" onclick="closePopup()" class="btn btn-light border rounded-circle p-2 position-absolute" style="top:14px; right:14px; width:34px; height:34px; display:flex; align-items:center; justify-content:center; z-index:10000;">
            <i class="fa-solid fa-xmark"></i>
        </button>

        <h5 id="popup_title" class="font-weight-bold mb-3"><i class="fa-solid fa-user-check me-2"></i>Approve Employee / Intern</h5>

        <form method="POST">
            <input type="hidden" name="signup_id" id="signup_id">

            <div class="row g-2">
                <!-- Name -->
                <div class="col-md-6 mb-2" id="full_name_col">
                    <label id="emp_name_label" class="form-label font-12 font-weight-bold text-muted mb-1">Employee / Intern Name:</label>
                    <input type="text" name="full_name" id="full_name" class="form-control form-control-sm" readonly style="border-radius: 6px; background: #f8fafc;">
                </div>

                <!-- Auto Emp/Int ID -->
                <div class="col-md-6 mb-2" id="emp_id_col">
                    <label id="emp_id_label" class="form-label font-12 font-weight-bold text-muted mb-1">Emp / Intern ID:</label>
                    <input type="text" name="emp_id" id="emp_id" class="form-control form-control-sm" readonly style="border-radius: 6px; background: #f8fafc;">
                </div>

                <!-- Start Date -->
                <div class="col-md-6 mb-2">
                    <label class="form-label font-12 font-weight-bold text-muted mb-1">Start Date <span class="text-danger">*</span></label>
                    <input type="date" name="start_date" class="form-control form-control-sm" required style="border-radius: 6px;">
                </div>

                <!-- End Date -->
                <div class="col-md-6 mb-2">
                    <label class="form-label font-12 font-weight-bold text-muted mb-1">End Date:</label>
                    <input type="date" name="end_date" class="form-control form-control-sm" style="border-radius: 6px;">
                </div>

                <!-- Shift In -->
                <div class="col-md-6 mb-2">
                    <label class="form-label font-12 font-weight-bold text-muted mb-1">Shift In <span class="text-danger">*</span></label>
                    <input type="time" name="shift_in" class="form-control form-control-sm" required style="border-radius: 6px;">
                </div>

                <!-- Shift Out -->
                <div class="col-md-6 mb-2">
                    <label class="form-label font-12 font-weight-bold text-muted mb-1">Shift Out <span class="text-danger">*</span></label>
                    <input type="time" name="shift_out" class="form-control form-control-sm" required style="border-radius: 6px;">
                </div>

                <!-- Total Medical Leave -->
                <div class="col-md-4 mb-2">
                    <label class="form-label font-12 font-weight-bold text-muted mb-1">Medical Leave (SL):</label>
                    <input type="number" name="medical_leave" class="form-control form-control-sm" value="4" min="0" style="border-radius: 6px;">
                </div>

                <!-- Total Casual Leave -->
                <div class="col-md-4 mb-2">
                    <label class="form-label font-12 font-weight-bold text-muted mb-1">Casual Leave (CL):</label>
                    <input type="number" name="casual_leave" class="form-control form-control-sm" value="4" min="0" style="border-radius: 6px;">
                </div>

                <!-- Total Privilege / Earned Leave -->
                <div class="col-md-4 mb-2">
                    <label class="form-label font-12 font-weight-bold text-muted mb-1">Privilege Leave (PL/EL):</label>
                    <input type="number" name="privilege_leave" class="form-control form-control-sm" value="5" min="0" style="border-radius: 6px;">
                </div>

                <!-- Remarks -->
                <div class="col-md-12 mb-2">
                    <label class="form-label font-12 font-weight-bold text-muted mb-1">Remarks:</label>
                    <textarea name="remarks" class="form-control form-control-sm" rows="2" style="border-radius: 6px;"></textarea>
                </div>
            </div>

            <div class="text-center mt-3">
                <button type="submit" name="approve" class="btn btn-success px-4" onclick="showLoader()" style="border-radius: 6px;">
                    <i class="fa-solid fa-check me-1"></i> Confirm & Approve
                </button>
            </div>
        </form>
    </div>

    <!-- Loading Overlay -->
    <div id="loader-overlay">
        <div class="spinner-border text-primary" role="status">
            <span class="sr-only">Processing...</span>
        </div>
    </div>

    <style>
        #loader-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(15, 23, 42, 0.4);
            z-index: 20000;
            align-items: center;
            justify-content: center;
        }
    </style>

    <script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.flash.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>

    <script src="../js/off-canvas.js"></script>
    <script src="../js/hoverable-collapse.js"></script>
    <script src="../js/template.js"></script>
    <script src="../js/settings.js"></script>
    <script src="../js/select2.js"></script>

    <script>
        function openPopup(id) {
            document.getElementById('signup_id').value = id;

            $.ajax({
                url: "fetch_signup_single.php",
                type: "POST",
                data: { id: id },
                success: function (response) {
                    let data = {};
                    try {
                        data = JSON.parse(response);
                    } catch (e) {
                        console.error("Invalid JSON Response:", response);
                        return;
                    }

                    let empId = (data.empAutoId ?? "").trim();
                    let empType = (data.employment_type ?? "").trim();
                    let desig = (data.designation ?? "").trim().toLowerCase();

                    $('#full_name').val(data.fullName ?? "");
                    $('#emp_id').val(empId);

                    let nameLabel = "Employee / Intern Name:";
                    let idLabel = "Emp / Intern ID:";

                    if (empType === 'Staff' || desig === 'staff' || /^STF/i.test(empId)) {
                        nameLabel = "Staff Name:";
                        $("#popup_title").html('<i class="fa-solid fa-user-check me-2"></i>Approve Staff');
                        $("#emp_id_col").hide();
                        $("#full_name_col").removeClass('col-md-6').addClass('col-md-12');
                    } else {
                        $("#emp_id_col").show();
                        $("#full_name_col").removeClass('col-md-12').addClass('col-md-6');

                        if (/^Emp/i.test(empId)) {
                            nameLabel = "Employee Name:";
                            idLabel = "Employee ID:";
                            $("#popup_title").html('<i class="fa-solid fa-user-check me-2"></i>Approve Employee');
                        } else if (/^(Int|Ent)/i.test(empId)) {
                            nameLabel = "Intern Name:";
                            idLabel = "Intern ID:";
                            $("#popup_title").html('<i class="fa-solid fa-user-check me-2"></i>Approve Intern');
                        } else {
                            $("#popup_title").html('<i class="fa-solid fa-user-check me-2"></i>Approve Employee / Intern');
                        }
                    }

                    $("#emp_name_label").text(nameLabel);
                    $("#emp_id_label").text(idLabel);

                    document.getElementById('popup').style.display = 'block';
                    document.getElementById('popup-overlay').style.display = 'block';
                },
                error: function (err) {
                    console.error("AJAX Error:", err);
                }
            });
        }

        function closePopup() {
            document.getElementById('popup').style.display = 'none';
            document.getElementById('popup-overlay').style.display = 'none';
        }

        function confirmReject(id) {
            Swal.fire({
                title: "Are you sure?",
                text: "You want to reject this application!",
                icon: "warning",
                showCancelButton: true,
                confirmButtonColor: "#dc3545",
                cancelButtonColor: "#6c757d",
                confirmButtonText: "Yes, Reject!"
            }).then((result) => {
                if (result.isConfirmed) {
                    showLoader();
                    window.location.href = "?reject_id=" + id;
                }
            });
        }

        <?php if (isset($_SESSION['popup'])): ?>
            Swal.fire({
                icon: '<?= $_SESSION['popup']['type'] ?>',
                title: '<?= $_SESSION['popup']['message'] ?>',
                showConfirmButton: false,
                timer: 2000
            });
            <?php unset($_SESSION['popup']);
        endif; ?>

        $(document).ready(function () {
            var table = $('#recordsTable').DataTable({
                pageLength: 10,
                ordering: true,
                columnDefs: [
                    { orderable: false, targets: [0, 1, 9] } // Disable sorting on expand, checkbox, and action
                ],
                responsive: true,
                info: true,
                lengthChange: true
            });

            $('#recordsTable tbody').on('click', 'td.details-control', function () {
                var tr = $(this).closest('tr');
                var row = table.row(tr);
                var signupId = tr.data('id');

                if (!signupId) {
                    console.error("Signup ID not found!");
                    return;
                }

                if (row.child.isShown()) {
                    row.child.hide();
                    tr.removeClass('shown');
                } else {
                    $.ajax({
                        url: "fetch_full_details.php",
                        type: "POST",
                        data: { id: signupId },
                        success: function (response) {
                            row.child(response).show();
                            tr.addClass('shown');
                        },
                        error: function () {
                            row.child("<div class='p-2 text-danger'>Error loading data.</div>").show();
                            tr.addClass('shown');
                        }
                    });
                }
            });

            // Select all checkbox & counter update
            $("#select_all, .row_checkbox").on("change", function () {
                if (this.id === 'select_all') {
                    $(".row_checkbox").prop("checked", this.checked);
                }
                let count = $(".row_checkbox:checked").length;
                if (count > 0) {
                    $("#selectedCountBadge").removeClass("d-none").text(count + " Selected");
                } else {
                    $("#selectedCountBadge").addClass("d-none");
                }
            });
        });

        function bulkApprove() {
            let ids = [];
            $(".row_checkbox:checked").each(function () {
                ids.push($(this).val());
            });

            if (ids.length === 0) {
                Swal.fire("Warning", "Please select at least one user", "warning");
                return;
            }

            Swal.fire({
                title: "Approve selected users?",
                text: "They will be marked as Approved in the system.",
                icon: "question",
                showCancelButton: true,
                confirmButtonColor: "#28a745",
                confirmButtonText: "Yes, Approve"
            }).then((result) => {
                if (result.isConfirmed) {
                    showLoader();
                    $.post("user_register.php", { bulk_approve: true, ids: ids }, function () {
                        location.reload();
                    });
                }
            });
        }

        function bulkReject() {
            let ids = [];
            $(".row_checkbox:checked").each(function () {
                ids.push($(this).val());
            });

            if (ids.length === 0) {
                Swal.fire("Warning", "Please select at least one user", "warning");
                return;
            }

            Swal.fire({
                title: "Reject selected users?",
                text: "They will be marked as Rejected.",
                icon: "warning",
                showCancelButton: true,
                confirmButtonColor: "#dc3545",
                cancelButtonColor: "#6c757d",
                confirmButtonText: "Yes, Reject"
            }).then((result) => {
                if (result.isConfirmed) {
                    showLoader();
                    $.post("user_register.php", { bulk_reject: true, ids: ids }, function () {
                        location.reload();
                    });
                }
            });
        }

        function showLoader() {
            document.getElementById("loader-overlay").style.display = "flex";
        }

        function hideLoader() {
            document.getElementById("loader-overlay").style.display = "none";
        }
    </script>
</body>
</html>
