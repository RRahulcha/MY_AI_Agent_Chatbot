<?php
session_start();
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}

include('../include/db_config.php');

// AJAX handler to fetch punch-in time for a selected date
if (isset($_GET['ajax_get_punch_in'])) {
    $date = mysqli_real_escape_string($con, $_GET['date']);
    $userId = $_SESSION['userId'];
    $lateQuery = mysqli_query($con, "
        SELECT a.punchInTime AS normalPunchIn, 
               ta.punchInTime AS tourPunchIn 
        FROM signup s
        LEFT JOIN attendance a ON s.userId = a.userId AND a.date = '$date'
        LEFT JOIN tourAttendance ta ON s.userId = ta.userId AND ta.date = '$date'
        WHERE s.userId = '$userId'
        LIMIT 1
    ");
    $punchIn = '';
    if ($lateQuery && $row = mysqli_fetch_assoc($lateQuery)) {
        $punchIn = !empty($row['normalPunchIn']) && $row['normalPunchIn'] !== '00:00:00' 
                    ? $row['normalPunchIn'] 
                    : (!empty($row['tourPunchIn']) && $row['tourPunchIn'] !== '00:00:00' 
                        ? $row['tourPunchIn'] : '');
    }
    echo json_encode(['punch_in' => $punchIn]);
    exit();
}

$userId = $_SESSION['userId'];

// Fetch user's details & expected punch-in from signup table
$userRow = mysqli_fetch_assoc(mysqli_query($con, "SELECT fullName, employment_type, designation, internId, emp_id, expectedPunchIn, createdDate, registration_date FROM signup WHERE userId='$userId'"));
$userName    = $userRow ? htmlspecialchars($userRow['fullName']) : 'Unknown';
$userType    = isset($userRow['employment_type']) ? strtolower($userRow['employment_type']) : 'employee';
$designation = isset($userRow['designation']) ? $userRow['designation'] : '';

// Dynamically fetch user expected punch-in time from signup table
$userExpectedPunchIn = (!empty($userRow['expectedPunchIn']) && $userRow['expectedPunchIn'] !== '00:00:00') ? $userRow['expectedPunchIn'] : '09:30:00';

// Build list of user IDs for query matching
$userQueryList = "'" . implode("','", array_map(fn($id) => mysqli_real_escape_string($con, $id), array_filter([$userId, $userRow['internId'] ?? '', $userRow['emp_id'] ?? '']))) . "'";

// Handle form submit
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date     = mysqli_real_escape_string($con, $_POST['date']);
    $punch_in = mysqli_real_escape_string($con, $_POST['punch_in']);
    $reason   = mysqli_real_escape_string($con, $_POST['reason']);

    $punchInSec = strtotime($punch_in);
    $minTime    = strtotime('08:00:00');
    $maxTime    = strtotime('20:00:00');

    if ($punchInSec < $minTime || $punchInSec > $maxTime) {
        $error = "Error: Punch In time must be between 08:00 AM and 08:00 PM.";
    } else {
        $insert = "INSERT INTO attendance_regularization 
                   (user_id, user_type, date, punch_in, reason, status, created_at)
                   VALUES ('$userId', '$userType', '$date', '$punch_in', '$reason', 'pending', NOW())";

        if (mysqli_query($con, $insert)) {
            // Notify HRs
            $hrQuery = mysqli_query($con, "SELECT userId FROM signup WHERE LOWER(designation)='hr' OR LOWER(userRole)='hr'");
            if ($hrQuery) {
                while ($hr = mysqli_fetch_assoc($hrQuery)) {
                    $hrId   = $hr['userId'];
                    $ntitle = "Attendance Regularization Request";
                    $nmsg   = "{$userName} has submitted an attendance regularization request for {$date}.";
                    mysqli_query($con, "INSERT INTO notifications (userId, title, message, type, is_read, created_at) VALUES ('$hrId', '$ntitle', '$nmsg', 'attendance', 0, NOW())");
                }
            }

            $_SESSION['status'] = true;
            $_SESSION['msg']    = "Attendance Regularization Submitted! <b>Please wait for approval.</b>";
            echo "<script>window.location.href='Attendance_Regularization.php';</script>";
            exit();
        } else {
            $error = "Error: " . mysqli_error($con);
        }
    }
}

// ── 1. Dates Where User ALREADY Punched In ──
$punchedDates = [];
$attQ = mysqli_query($con, "SELECT DISTINCT date FROM attendance WHERE userId IN ($userQueryList) AND punchInTime IS NOT NULL AND punchInTime != '00:00:00'");
if ($attQ) {
    while ($r = mysqli_fetch_assoc($attQ)) {
        $punchedDates[$r['date']] = true;
    }
}
$tourQ = mysqli_query($con, "SELECT DISTINCT date FROM tourAttendance WHERE userId IN ($userQueryList) AND punchInTime IS NOT NULL AND punchInTime != '00:00:00'");
if ($tourQ) {
    while ($r = mysqli_fetch_assoc($tourQ)) {
        $punchedDates[$r['date']] = true;
    }
}

// ── 2. Approved Leaves & Tours (All approved leaves including Rostering Off) ──
$leaveDates = [];
$lq = mysqli_query($con, "SELECT from_date, to_date FROM leaves WHERE userId IN ($userQueryList) AND status='Approved'");
if ($lq) {
    while ($lr = mysqli_fetch_assoc($lq)) {
        $cur = strtotime($lr['from_date']);
        $end = strtotime($lr['to_date']);
        while ($cur <= $end) {
            $leaveDates[date('Y-m-d', $cur)] = true;
            $cur = strtotime('+1 day', $cur);
        }
    }
}
$tourLQ = mysqli_query($con, "SELECT fromDate, toDate FROM tour WHERE userID IN ($userQueryList) AND status='Approved'");
if ($tourLQ) {
    while ($tr = mysqli_fetch_assoc($tourLQ)) {
        $cur = strtotime($tr['fromDate']);
        $end = strtotime($tr['toDate']);
        while ($cur <= $end) {
            $leaveDates[date('Y-m-d', $cur)] = true;
            $cur = strtotime('+1 day', $cur);
        }
    }
}

// ── 3. Holidays ──
$holidayDates = [];
$hq = mysqli_query($con, "SELECT from_date, to_date FROM holidays");
if ($hq) {
    while ($hr = mysqli_fetch_assoc($hq)) {
        $cur = strtotime($hr['from_date']);
        $end = strtotime($hr['to_date']);
        while ($cur <= $end) {
            $holidayDates[date('Y-m-d', $cur)] = true;
            $cur = strtotime('+1 day', $cur);
        }
    }
}

// ── 4. Already Regularized Dates (Pending or Approved) ──
$alreadyRegDates = [];
$regCheckQ = mysqli_query($con, "SELECT DISTINCT date FROM attendance_regularization WHERE user_id IN ($userQueryList) AND LOWER(status) IN ('pending', 'approved')");
if ($regCheckQ) {
    while ($rc = mysqli_fetch_assoc($regCheckQ)) {
        $alreadyRegDates[$rc['date']] = true;
    }
}

// ── 5. Late Punch-In Intimations ──
$lateIntimations = [];
$lpiQ = mysqli_query($con, "SELECT date, delayed_time FROM late_punch_in WHERE userId IN ($userQueryList) OR emp_id IN ($userQueryList)");
if ($lpiQ) {
    while ($lp = mysqli_fetch_assoc($lpiQ)) {
        $lateIntimations[$lp['date']] = $lp['delayed_time'];
    }
}

// ── 6. Collect Past Working Dates Where User DID NOT PUNCH IN (Max 5) ──
$today = date('Y-m-d');
$nowTs = time();
$eligibleDates = [];

$checkDate = strtotime($today);
$userRegDateStr = !empty($userRow['createdDate']) ? date('Y-m-d', strtotime($userRow['createdDate'])) : (!empty($userRow['registration_date']) ? date('Y-m-d', strtotime($userRow['registration_date'])) : date('Y-m-d', strtotime('-1 year')));
$startDate = strtotime($userRegDateStr);

while ($checkDate >= $startDate) {
    $dStr = date('Y-m-d', $checkDate);
    $dayOfWeek = date('N', $checkDate); // 7 = Sunday

    // Exclude Sundays, Approved Leaves (including Rostering Off), Holidays, Already Regularized, and ALREADY PUNCHED dates
    if ($dayOfWeek != 7 && !isset($leaveDates[$dStr]) && !isset($holidayDates[$dStr]) && !isset($alreadyRegDates[$dStr])) {
        
        if (!isset($punchedDates[$dStr])) {
            if ($dStr === $today) {
                $delayed = $lateIntimations[$today] ?? null;
                if (!empty($delayed) && $delayed !== '00:00:00') {
                    $cutoffTs = strtotime("$today $delayed");
                } else {
                    $cutoffTs = strtotime("$today $userExpectedPunchIn") + 1800;
                }
                if ($nowTs >= $cutoffTs) {
                    $eligibleDates[$dStr] = ['date' => $dStr, 'punchIn' => ''];
                }
            } else {
                $eligibleDates[$dStr] = ['date' => $dStr, 'punchIn' => ''];
            }
        }
    }

    if (count($eligibleDates) >= 5) {
        break; // Found the 5 most recent un-punched working dates
    }

    $checkDate = strtotime('-1 day', $checkDate);
}

// Fetch KPI statistics
$statsQ = mysqli_query($con, "
    SELECT 
        COUNT(*) AS total,
        SUM(CASE WHEN LOWER(status) = 'approved' THEN 1 ELSE 0 END) AS approved,
        SUM(CASE WHEN LOWER(status) = 'pending' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN LOWER(status) = 'rejected' THEN 1 ELSE 0 END) AS rejected
    FROM attendance_regularization 
    WHERE user_id IN ($userQueryList)
");
$stats = $statsQ ? mysqli_fetch_assoc($statsQ) : [];
$totalRequests    = (int)($stats['total'] ?? 0);
$approvedRequests = (int)($stats['approved'] ?? 0);
$pendingRequests  = (int)($stats['pending'] ?? 0);
$rejectedRequests = (int)($stats['rejected'] ?? 0);

// Fetch History Requests
$historyQ = mysqli_query($con, "
    SELECT id, date, punch_in, punch_out, reason, status, hr_reason, created_at, user_type
    FROM attendance_regularization 
    WHERE user_id IN ($userQueryList)
    ORDER BY created_at DESC
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Attendance Regularization</title>
    <?php include('../partials/_links.php'); ?>
    <style>
        .required { color: #dc3545; }
        .card { border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 2px 10px rgba(0,0,0,0.03); }
        .card-title { font-size: 1.1rem; font-weight: 700; color: #1e293b; }
        .form-control, .form-select { border-radius: 8px; border: 1px solid #cbd5e1; padding: 10px 14px; font-size: 0.88rem; }
        .form-control:focus, .form-select:focus { border-color: #4b49ac; box-shadow: 0 0 0 3px rgba(75,73,172,0.12); }
        
        /* KPI Cards */
        .kpi-card {
            background: #fff; border-radius: 12px; border: 1px solid #e2e8f0;
            padding: 18px 20px; transition: transform 0.2s, box-shadow 0.2s;
        }
        .kpi-card:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(0,0,0,0.06); }
        .kpi-icon {
            width: 48px; height: 48px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.3rem; flex-shrink: 0;
        }
        .kpi-val { font-size: 1.6rem; font-weight: 800; color: #0f172a; line-height: 1.1; margin-top: 4px; }
        .kpi-lbl { font-size: 0.78rem; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.04em; }

        .badge-pending { background: #FEF3C7; color: #92400E; border: 1px solid #FCD34D; }
        .badge-approved { background: #D1FAE5; color: #065F46; border: 1px solid #6EE7B7; }
        .badge-rejected { background: #FEE2E2; color: #991B1B; border: 1px solid #FCA5A5; }
        
        #regularizationHistoryTable thead th {
            background: #f8fafc; color: #475569; font-weight: 700;
            font-size: 0.78rem; text-transform: uppercase; border-bottom: 2px solid #e2e8f0;
        }
    </style>
</head>
<body>
    <div class="container-scroller">
        <?php include('../partials/_navbar.php'); ?>
        <div class="container-fluid page-body-wrapper">
            <?php
            include('../partials/_sidebar.php');
            include('../partials/_settings-panel.php');
            ?>

            <div class="main-panel">
                <div class="content-wrapper" style="background:#f4f6fb; padding: 24px;">

                    <!-- Page Title & Header -->
                    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
                        <div>
                            <h3 class="fw-bold text-dark mb-1">
                                <i class="fa-solid fa-clipboard-check text-primary me-2"></i> Attendance Regularization
                            </h3>
                            <p class="text-muted small mb-0">Submit punch-in regularization requests for dates with missing attendance.</p>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-white text-dark border px-3 py-2" style="font-size:0.85rem; border-radius:20px;">
                                👤 <strong><?= $userName ?></strong> <span class="text-muted">(<?= ucfirst($userType) ?>)</span>
                            </span>
                        </div>
                    </div>

                    <!-- ── KPI Summary Cards ── -->
                    <div class="row mb-4">
                        <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-lbl">Total Submitted</div>
                                    <div class="kpi-val text-primary"><?= $totalRequests ?></div>
                                    <small class="text-muted" style="font-size:0.72rem;">All time requests</small>
                                </div>
                                <div class="kpi-icon" style="background:#EEEDFE; color:#4b49ac;">
                                    <i class="fa-solid fa-paper-plane"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-lbl">Approved</div>
                                    <div class="kpi-val text-success"><?= $approvedRequests ?></div>
                                    <small class="text-muted" style="font-size:0.72rem;">Successfully regularized</small>
                                </div>
                                <div class="kpi-icon" style="background:#E6F4EA; color:#1E8E3E;">
                                    <i class="fa-solid fa-circle-check"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-lbl">Pending</div>
                                    <div class="kpi-val text-warning" style="color:#b06000 !important;"><?= $pendingRequests ?></div>
                                    <small class="text-muted" style="font-size:0.72rem;">Awaiting HR review</small>
                                </div>
                                <div class="kpi-icon" style="background:#FEF7E0; color:#B06000;">
                                    <i class="fa-solid fa-clock"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-lbl">Rejected</div>
                                    <div class="kpi-val text-danger"><?= $rejectedRequests ?></div>
                                    <small class="text-muted" style="font-size:0.72rem;">Declined requests</small>
                                </div>
                                <div class="kpi-icon" style="background:#FCE8E6; color:#C5221F;">
                                    <i class="fa-solid fa-circle-xmark"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── Main Grid Section ── -->
                    <div class="row">
                        <!-- Left: Submit Request Form -->
                        <div class="col-lg-5 mb-4">
                            <div class="card h-100">
                                <div class="card-body p-4">
                                    <h5 class="card-title mb-3">
                                        <i class="fa-solid fa-plus-circle text-primary me-2"></i> Submit Regularization Request
                                    </h5>
                                    <hr class="text-muted opacity-25 mb-4">

                                    <?php if (!empty($error)): ?>
                                        <div class="alert alert-danger mb-3" style="border-radius:8px;"><?php echo $error; ?></div>
                                    <?php endif; ?>

                                    <?php
                                    if (isset($_SESSION['status'])) {
                                        echo "<div class='alert alert-success mb-3' style='border-radius:8px;'>" . $_SESSION['msg'] . "</div>";
                                        unset($_SESSION['status']);
                                        unset($_SESSION['msg']);
                                    }
                                    ?>

                                    <form class="forms-sample" method="POST">
                                        <div class="form-group mb-3">
                                            <label for="date" class="fw-bold text-secondary mb-1">Select Un-punched Date <span class="required">*</span></label>
                                            <select class="form-select" id="date" name="date" required>
                                                <option value="" data-punchin="">-- Select Un-punched Date --</option>
                                                <?php
                                                if (!empty($eligibleDates)) {
                                                    foreach ($eligibleDates as $ldate => $itemData) {
                                                        echo '<option value="' . htmlspecialchars($ldate) . '" data-punchin="">' . date('d M Y', strtotime($ldate)) . '</option>';
                                                    }
                                                } else {
                                                    echo '<option value="" disabled>No un-punched dates found</option>';
                                                }
                                                ?>
                                            </select>
                                        </div>

                                        <div class="form-group mb-3">
                                            <label for="punch_in" class="fw-bold text-secondary mb-1">Punch In Time <span class="required">*</span></label>
                                            <input type="time" class="form-control" id="punch_in" name="punch_in" step="1" min="08:00:00" max="20:00:00" required>
                                            <small class="text-muted" style="font-size:0.75rem;">Allowed Window: 08:00 AM to 08:00 PM.</small>
                                        </div>

                                        <div class="form-group mb-4">
                                            <label for="reason" class="fw-bold text-secondary mb-1">Reason <span class="required">*</span></label>
                                            <textarea class="form-control" id="reason" name="reason" placeholder="Explain reason for missing punch-in..." rows="4" required></textarea>
                                        </div>

                                        <button type="submit" class="btn btn-primary px-4 py-2" style="border-radius:8px; font-weight:600;" <?= empty($eligibleDates) ? 'disabled' : '' ?>>
                                            <i class="fa-solid fa-paper-plane me-1"></i> Submit Request
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- Right: History & Status Report Table -->
                        <div class="col-lg-7 mb-4">
                            <div class="card h-100">
                                <div class="card-body p-4">
                                    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                                        <h5 class="card-title mb-0">
                                            <i class="fa-solid fa-clock-rotate-left text-primary me-2"></i> My Regularization Requests & Status
                                        </h5>
                                        <span class="badge bg-light text-dark border px-3 py-1" style="border-radius:20px; font-size:0.78rem;">
                                            <?= mysqli_num_rows($historyQ) ?> Entries Recorded
                                        </span>
                                    </div>
                                    <hr class="text-muted opacity-25 mb-4">

                                    <div class="table-responsive">
                                        <table id="regularizationHistoryTable" class="table table-hover align-middle w-100" style="font-size:0.84rem;">
                                            <thead>
                                                <tr>
                                                    <th>#</th>
                                                    <th>Target Date</th>
                                                    <th>Punch In</th>
                                                    <th>Reason</th>
                                                    <th>Status</th>
                                                    <th>HR Note / Reason</th>
                                                    <th>Submitted On</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                if ($historyQ && mysqli_num_rows($historyQ) > 0) {
                                                    $sr = 1;
                                                    while ($h = mysqli_fetch_assoc($historyQ)) {
                                                        $st = strtolower($h['status'] ?? 'pending');
                                                        if ($st === 'approved') {
                                                            $statusBadge = '<span class="badge badge-approved px-2 py-1"><i class="fa-solid fa-check me-1"></i> Approved</span>';
                                                        } elseif ($st === 'rejected') {
                                                            $statusBadge = '<span class="badge badge-rejected px-2 py-1"><i class="fa-solid fa-xmark me-1"></i> Rejected</span>';
                                                        } else {
                                                            $statusBadge = '<span class="badge badge-pending px-2 py-1"><i class="fa-solid fa-clock me-1"></i> Pending</span>';
                                                        }

                                                        $formattedPunch = !empty($h['punch_in']) ? date('h:i A', strtotime($h['punch_in'])) : '-';
                                                        $formattedTarget = !empty($h['date']) ? date('d M Y', strtotime($h['date'])) : '-';
                                                        $submittedOn = !empty($h['created_at']) ? date('d M Y, h:i A', strtotime($h['created_at'])) : '-';
                                                        $hrNote = !empty($h['hr_reason']) ? htmlspecialchars($h['hr_reason']) : '<span class="text-muted" style="font-style:italic;">—</span>';
                                                        ?>
                                                        <tr>
                                                            <td><?= $sr++ ?></td>
                                                            <td><strong class="text-dark"><?= $formattedTarget ?></strong></td>
                                                            <td><code class="text-primary fw-bold" style="font-size:0.85rem;"><?= $formattedPunch ?></code></td>
                                                            <td style="max-width:180px; word-break:break-word;"><?= htmlspecialchars($h['reason'] ?? '') ?></td>
                                                            <td><?= $statusBadge ?></td>
                                                            <td style="max-width:160px; word-break:break-word;"><?= $hrNote ?></td>
                                                            <td class="text-muted" style="font-size:0.78rem;"><?= $submittedOn ?></td>
                                                        </tr>
                                                        <?php
                                                    }
                                                }
                                                ?>
                                            </tbody>
                                        </table>
                                    </div>

                                </div>
                            </div>
                        </div>

                    </div> <!-- Row -->

                </div> <!-- Content Wrapper -->
            </div> <!-- Main Panel -->

        </div>
    </div>

    <!-- Scripts -->
    <script src="../vendors/js/vendor.bundle.base.js"></script>
    <script src="../js/off-canvas.js"></script>
    <script src="../js/hoverable-collapse.js"></script>
    <script src="../js/template.js"></script>
    <script src="../js/settings.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

    <script>
        $(document).ready(function() {
            $('#regularizationHistoryTable').DataTable({
                paging: true,
                pageLength: 10,
                lengthMenu: [[5, 10, 25, 50, -1], [5, 10, 25, 50, "All"]],
                ordering: true,
                order: [[0, 'asc']],
                searching: true,
                info: true,
                responsive: true,
                language: {
                    search: "Filter Requests:",
                    lengthMenu: "Show _MENU_ per page",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries",
                    infoEmpty: "No regularization requests recorded"
                }
            });

            if ($('#date option').length === 2 && $('#date option:last').val() !== '') {
                $('#date')[0].selectedIndex = 1;
            }
        });
    </script>

    <?php require_once('../include/sweetAlert.php'); ?>
</body>
</html>
<?php
if (isset($_SESSION["status"])) {
    unset($_SESSION['status']);
    unset($_SESSION['msg']);
}
?>
