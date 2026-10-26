<?php
session_start();
include('../include/db_config.php');
require_once('../include/early_go_helper.php');

// ✅ Session check
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href='login.php';</script>";
    exit;
}

$userId = $_SESSION['userId'];
$today  = date('Y-m-d');
$currentMonth = date('Y-m');

/* ---------------------------------------------------
   🔥 DELETE RECORD LOGIC
-----------------------------------------------------*/
if (isset($_GET['delete_id'])) {
    $deleteId = intval($_GET['delete_id']);

    $stmt = $con->prepare("DELETE FROM late_punch_in WHERE id = ? AND userId = ? AND date = ?");
    $stmt->bind_param("iss", $deleteId, $userId, $today);
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        $_SESSION['alert_icon'] = 'success';
        $_SESSION['alert_text'] = 'Record deleted successfully!';
    } else {
        $_SESSION['alert_icon'] = 'error';
        $_SESSION['alert_text'] = 'Error deleting record or record expired.';
    }
    $stmt->close();

    header("Location: late_punch_early_go.php");
    exit;
}

/* ---------------------------------------------------
   🔥 FORM SUBMISSION LOGIC
-----------------------------------------------------*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Late Punch Form
    if (isset($_POST['form_type']) && $_POST['form_type'] === 'late_punch_in') {

        $date   = mysqli_real_escape_string($con, $_POST['date']);
        $reason = mysqli_real_escape_string($con, trim($_POST['reason']));

        // Convert 12hr dropdown to 24hr format
        $hour_12 = intval($_POST['delayed_hour']);
        $minute  = intval($_POST['delayed_minute']);
        $ampm    = $_POST['delayed_ampm'];

        if ($ampm === 'AM') {
            $hour_24 = ($hour_12 == 12) ? 0 : $hour_12;
        } else {
            $hour_24 = ($hour_12 == 12) ? 12 : $hour_12 + 12;
        }

        $delayed_time = sprintf('%02d:%02d', $hour_24, $minute);
        $delayed_time = mysqli_real_escape_string($con, $delayed_time);

        // Validation for today's date
        $punchInTime      = "10:00";
        $limitTimestamp   = strtotime("$punchInTime - 5 minutes");
        $currentTimestamp = strtotime(date("H:i"));

        if ($date == $today && $currentTimestamp > $limitTimestamp) {
            $_SESSION['alert_icon'] = 'error';
            $_SESSION['alert_text'] = 'Late Punch-In allowed only till 5 minutes before Punch-In time!';
            header("Location: late_punch_early_go.php");
            exit;
        }

        $insertLate = "INSERT INTO late_punch_in (userId, delayed_time, date, reason)
                       VALUES ('$userId', '$delayed_time', '$date', '$reason')";
        if (mysqli_query($con, $insertLate)) {
            $_SESSION['alert_icon'] = 'success';
            $_SESSION['alert_text'] = 'Late Punch record added successfully!';
        } else {
            $_SESSION['alert_icon'] = 'error';
            $_SESSION['alert_text'] = 'Error while adding Late Punch record.';
        }
        header("Location: late_punch_early_go.php");
        exit;
    }

    // Early Go Form
    else if (isset($_POST['early_go_time'], $_POST['early_date'], $_POST['approved_by'], $_POST['early_reason'])) {
        $early_date    = mysqli_real_escape_string($con, $_POST['early_date']);
        $early_go_time = mysqli_real_escape_string($con, $_POST['early_go_time']);
        $approved_by   = mysqli_real_escape_string($con, $_POST['approved_by']);
        $early_reason  = mysqli_real_escape_string($con, trim($_POST['early_reason']));

        $insertEarly = "INSERT INTO early_go (userId, early_go_time, date, approved_by, reason)
                        VALUES ('$userId', '$early_go_time', '$early_date', '$approved_by', '$early_reason')";
        if (mysqli_query($con, $insertEarly)) {
            $_SESSION['alert_icon'] = 'success';
            $_SESSION['alert_text'] = 'Early Go record added successfully!';
        } else {
            $_SESSION['alert_icon'] = 'error';
            $_SESSION['alert_text'] = 'Error while adding Early Go record.';
        }
        header("Location: late_punch_early_go.php");
        exit;
    }
}

// Calculate KPI Analytics
$kpiQ = mysqli_query($con, "
    SELECT 
        COUNT(*) AS totalIntimations,
        SUM(CASE WHEN DATE_FORMAT(date, '%Y-%m') = '$currentMonth' THEN 1 ELSE 0 END) AS monthIntimations,
        SUM(CASE WHEN date = '$today' THEN 1 ELSE 0 END) AS todayIntimations
    FROM late_punch_in
    WHERE userId = '$userId'
");
$kpi = $kpiQ ? mysqli_fetch_assoc($kpiQ) : [];
$totalIntimations = (int)($kpi['totalIntimations'] ?? 0);
$monthIntimations = (int)($kpi['monthIntimations'] ?? 0);
$todayIntimations = (int)($kpi['todayIntimations'] ?? 0);

$egSummary = getMonthlyEarlyGoSummary($con, $userId, $currentMonth);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Late Punch-in Intimation</title>
    <?php include('../partials/_links.php'); ?>
    <style>
        .card { border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 2px 10px rgba(0,0,0,0.03); }
        .card-title { font-size: 1.15rem; font-weight: 700; color: #1e293b; }
        .form-control { border-radius: 8px; border: 1px solid #cbd5e1; padding: 10px 14px; font-size: 0.88rem; }
        .form-control:focus { border-color: #4b49ac; box-shadow: 0 0 0 3px rgba(75,73,172,0.12); }
        
        /* Custom Select Dropdown Styling */
        select.form-control, select.form-select, .time-picker-group select {
            height: 42px !important;
            padding: 8px 32px 8px 12px !important;
            border-radius: 8px !important;
            border: 1px solid #cbd5e1 !important;
            background-color: #ffffff !important;
            color: #1e293b !important;
            font-size: 0.88rem !important;
            font-weight: 500 !important;
            appearance: none !important;
            -webkit-appearance: none !important;
            -moz-appearance: none !important;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%3c475569' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m2 5 6 6 6-6'/%3e%3c/svg%3e") !important;
            background-repeat: no-repeat !important;
            background-position: right 10px center !important;
            background-size: 14px 10px !important;
            cursor: pointer;
        }

        .time-picker-group {
            display: flex; gap: 6px; align-items: center;
        }
        .time-picker-group select {
            flex: 1;
        }
        .time-sep { font-weight: 800; font-size: 1.1rem; color: #475569; }

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

        #latePunchTable thead th {
            background: #f8fafc; color: #475569; font-weight: 700;
            font-size: 0.78rem; text-transform: uppercase; border-bottom: 2px solid #e2e8f0;
        }

        .policy-badge {
            display: inline-block; padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600;
        }
        .policy-badge-success { background: #dcfce7; color: #166534; }
        .policy-badge-warning { background: #fef3c7; color: #92400e; }
        .policy-badge-danger { background: #fee2e2; color: #991b1b; }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.4.1/css/responsive.bootstrap4.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
                                <i class="fa-solid fa-user-clock text-primary me-2"></i> Late Punch-in Intimation & Early Go Policy
                            </h3>
                            <p class="text-muted small mb-0">Submit advance intimations for expected late punch-ins and monitor Early Go policy status.</p>
                        </div>
                    </div>

                    <!-- ── 1. KPI Analytics Summary Dashboard ── -->
                    <div class="row mb-4">
                        <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-lbl">Total Intimations</div>
                                    <div class="kpi-val text-primary"><?= $totalIntimations ?></div>
                                    <small class="text-muted" style="font-size:0.72rem;">All-time requests submitted</small>
                                </div>
                                <div class="kpi-icon" style="background:#EEEDFE; color:#4b49ac;">
                                    <i class="fa-solid fa-user-clock"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-lbl">This Month</div>
                                    <div class="kpi-val text-warning" style="color:#b06000 !important;"><?= $monthIntimations ?></div>
                                    <small class="text-muted" style="font-size:0.72rem;">Logged in <?= date('F Y') ?></small>
                                </div>
                                <div class="kpi-icon" style="background:#FEF7E0; color:#B06000;">
                                    <i class="fa-solid fa-calendar-day"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-lbl">Monthly Early Go Marks</div>
                                    <div class="kpi-val <?= $egSummary['unapprovedMarks'] >= 3 ? 'text-danger' : 'text-info' ?>"><?= $egSummary['unapprovedMarks'] ?></div>
                                    <small class="text-muted" style="font-size:0.72rem;"><?= $egSummary['unapprovedMarks'] >= 3 ? '⚠️ Warning Threshold Reached' : 'Threshold: 3 Marks' ?></small>
                                </div>
                                <div class="kpi-icon" style="background:<?= $egSummary['unapprovedMarks'] >= 3 ? '#FEE2E2' : '#E0F2FE' ?>; color:<?= $egSummary['unapprovedMarks'] >= 3 ? '#991B1B' : '#0369A1' ?>;">
                                    <i class="fa-solid fa-person-walking-arrow-right"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-lbl">Early Go Deductions</div>
                                    <div class="kpi-val text-danger"><?= $egSummary['halfDayDeductions'] ?></div>
                                    <small class="text-muted" style="font-size:0.72rem;">Half Day Leaves deducted</small>
                                </div>
                                <div class="kpi-icon" style="background:#FEE2E2; color:#DC2626;">
                                    <i class="fa-solid fa-file-invoice-dollar"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Early Go Policy Summary Banner -->
                    <div class="card mb-4" style="border-left: 4px solid #4b49ac; background: #fafafa;">
                        <div class="card-body p-3">
                            <h5 class="fw-bold text-dark mb-2" style="font-size:0.95rem;">
                                <i class="fa-solid fa-shield-halved text-primary me-2"></i> Early Go Policy Rules
                            </h5>
                            <div class="row text-muted small g-2">
                                <div class="col-md-3"><strong>≤ 15 Mins:</strong> Allowed (No mark)</div>
                                <div class="col-md-3"><strong>15–120 Mins:</strong> 1 Early Go Mark</div>
                                <div class="col-md-3"><strong>> 120 Mins:</strong> Half Day Leave</div>
                                <div class="col-md-3"><strong>3 Marks:</strong> Warning | <strong>5th, 7th...:</strong> Half Day Deduction</div>
                            </div>
                        </div>
                    </div>

                    <!-- ── 2. Intimation Form Card ── -->
                    <div class="card mb-4">
                        <div class="card-body p-4">
                            <h4 class="card-title mb-3">
                                <i class="fa-solid fa-pen-to-square text-primary me-2"></i> Submit Late Punch Intimation
                            </h4>
                            <p class="text-muted small mb-3">
                                <i class="fa-solid fa-circle-info text-info me-1"></i> 
                                Intimations for today's date are accepted up to <strong>5 minutes prior</strong> to expected punch-in time.
                            </p>
                            <hr class="text-muted opacity-25 mb-4">

                            <form class="forms-sample" method="post" action="">
                                <input type="hidden" name="form_type" value="late_punch_in">
                                <div class="row align-items-end">
                                    <?php
                                    $today_date = date("Y-m-d");
                                    $query = mysqli_query($con, "SELECT date FROM attendance WHERE userId = '{$_SESSION['userId']}' AND date = '$today_date'");
                                    $hasPunchedInToday = (mysqli_num_rows($query) > 0);
                                    $minDateAllowed = $hasPunchedInToday ? date('Y-m-d', strtotime('+1 day')) : $today_date;
                                    ?>
                                    <div class="col-md-3 mb-3 mb-md-0">
                                        <div class="form-group mb-0">
                                            <label for="date" class="fw-bold text-secondary mb-1">Intimation Date <span class="text-danger">*</span></label>
                                            <input type="date" class="form-control" id="date" name="date" min="<?= $minDateAllowed ?>" value="<?= $minDateAllowed ?>" required>
                                        </div>
                                    </div>

                                    <div class="col-md-4 mb-3 mb-md-0">
                                        <div class="form-group mb-0">
                                            <label class="fw-bold text-secondary mb-1">Expected Delayed Time <span class="text-danger">*</span></label>
                                            <div class="time-picker-group">
                                                <select name="delayed_hour" required>
                                                    <option value="">HH</option>
                                                    <?php for ($h = 1; $h <= 12; $h++): ?>
                                                        <option value="<?= $h ?>"><?= str_pad($h, 2, '0', STR_PAD_LEFT) ?></option>
                                                    <?php endfor; ?>
                                                </select>
                                                <span class="time-sep">:</span>
                                                <select name="delayed_minute" required>
                                                    <option value="">MM</option>
                                                    <?php for ($m = 0; $m < 60; $m += 5): ?>
                                                        <option value="<?= $m ?>"><?= str_pad($m, 2, '0', STR_PAD_LEFT) ?></option>
                                                    <?php endfor; ?>
                                                </select>
                                                <select name="delayed_ampm" required>
                                                    <option value="AM">AM</option>
                                                    <option value="PM">PM</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-md-3 mb-3 mb-md-0">
                                        <div class="form-group mb-0">
                                            <label for="reason" class="fw-bold text-secondary mb-1">Reason <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" id="reason" name="reason" placeholder="State reason for late arrival..." required>
                                        </div>
                                    </div>

                                    <div class="col-md-2">
                                        <button type="submit" class="btn btn-primary px-3 py-2 w-100" style="border-radius:8px; font-weight:600;" id="latePunchBtn">
                                            <i class="fa-solid fa-paper-plane me-1"></i> Submit
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- ── 3. Intimation Records Table Card ── -->
                    <div class="card">
                        <div class="card-body p-4">
                            <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                                <div>
                                    <h4 class="card-title mb-0">
                                        <i class="fa-solid fa-list-check text-primary me-2"></i> Late Punch Intimation Records
                                    </h4>
                                    <p class="text-muted small mb-0">Audit log of submitted late punch-in intimations.</p>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <button id="exportExcel" class="btn btn-success text-white btn-sm px-3 py-2" style="border-radius:6px;" title="Export to Excel" onclick="exportTableToExcel()">
                                        <i class="fa-solid fa-file-excel me-1"></i> Excel
                                    </button>
                                    <button id="downloadPdf" class="btn btn-danger text-white btn-sm px-3 py-2" style="border-radius:6px;" title="Download as PDF" onclick="exportTableToPDF()">
                                        <i class="fa-solid fa-file-pdf me-1"></i> PDF
                                    </button>
                                </div>
                            </div>
                            <hr class="text-muted opacity-25 mb-4">

                            <div class="table-responsive">
                                <table class="table table-hover align-middle w-100" id="latePunchTable" style="font-size:0.85rem;">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Intimation Date</th>
                                            <th>Delayed Arrival Time</th>
                                            <th>Reason</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $lateQuery = mysqli_query($con, "SELECT * FROM late_punch_in WHERE userId='$userId' ORDER BY date DESC");
                                        $sr = 1;
                                        if ($lateQuery && mysqli_num_rows($lateQuery) > 0) {
                                            while ($row = mysqli_fetch_assoc($lateQuery)) {
                                                $recordDate = $row['date'];
                                                $dateFormatted = date('d M Y', strtotime($recordDate));

                                                $displayTime = !empty($row['delayed_time'])
                                                    ? date('h:i A', strtotime($row['delayed_time']))
                                                    : '-';

                                                $actionBtn = ($recordDate == $today)
                                                    ? "<button class='btn btn-outline-danger btn-sm px-2 py-1 deleteBtn' data-id='{$row['id']}' style='border-radius:6px;'><i class='fa-solid fa-trash me-1'></i> Delete</button>"
                                                    : "<span class='text-muted' style='font-size:0.8rem;'>—</span>";
                                        ?>
                                                <tr>
                                                    <td class="fw-bold text-secondary"><?= $sr++ ?></td>
                                                    <td><strong class="text-dark"><i class="fa-solid fa-calendar-day me-1 text-primary"></i> <?= $dateFormatted ?></strong></td>
                                                    <td>
                                                        <span class="badge bg-light text-primary border px-2 py-1" style="font-size:0.82rem; font-weight:700;">
                                                            <i class="fa-solid fa-clock me-1"></i> <?= $displayTime ?>
                                                        </span>
                                                    </td>
                                                    <td style="max-width:300px; word-break:break-word;"><?= htmlspecialchars($row['reason'] ?? '') ?></td>
                                                    <td><?= $actionBtn ?></td>
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
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.17.0/xlsx.full.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.4.0/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.23/jspdf.plugin.autotable.min.js"></script>

    <script>
        $(document).ready(function() {
            $('#latePunchTable').DataTable({
                responsive: true,
                order: [],
                pageLength: 10,
                lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "All"]],
                language: {
                    search: "Filter Intimations:",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries",
                    emptyTable: "No late punch intimations recorded"
                }
            });

            document.querySelectorAll(".deleteBtn").forEach(btn => {
                btn.addEventListener("click", function () {
                    let id = this.getAttribute("data-id");

                    Swal.fire({
                        title: "Delete Late Punch Intimation?",
                        text: "This record will be deleted permanently!",
                        icon: "warning",
                        showCancelButton: true,
                        confirmButtonColor: "#dc3545",
                        cancelButtonColor: "#6c757d",
                        confirmButtonText: "Yes, delete it!"
                    }).then((result) => {
                        if (result.isConfirmed) {
                            window.location.href = "?delete_id=" + id;
                        }
                    });
                });
            });
        });

        function exportTableToExcel() {
            var tableClone = document.createElement("table");
            var headerRow = tableClone.insertRow();
            var headers = ["Intimation Date", "Delayed Arrival Time", "Reason"];
            headers.forEach(h => {
                var th = document.createElement("th");
                th.innerText = h;
                headerRow.appendChild(th);
            });

            var table = $('#latePunchTable').DataTable();
            table.rows().data().each(function(row) {
                var tr = tableClone.insertRow();
                tr.innerHTML = `
                    <td>${$(row[1]).text().trim()}</td>
                    <td>${$(row[2]).text().trim()}</td>
                    <td>${$(row[3]).text().trim()}</td>
                `;
            });

            var workbook = XLSX.utils.table_to_book(tableClone, { sheet: "Late Punch Intimations" });
            XLSX.writeFile(workbook, 'Late_Punch_Intimations.xlsx');
        }

        async function exportTableToPDF() {
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF('portrait');
            doc.text('Late Punch-in Intimations Report', 14, 15);

            const tableData = [];
            var table = $('#latePunchTable').DataTable();
            table.rows().data().each(function(row) {
                tableData.push([
                    $(row[1]).text().trim(),
                    $(row[2]).text().trim(),
                    $(row[3]).text().trim()
                ]);
            });

            doc.autoTable({
                head: [["Intimation Date", "Delayed Arrival Time", "Reason"]],
                body: tableData,
                startY: 25,
            });

            doc.save('Late_Punch_Intimations.pdf');
        }
    </script>

    <!-- SweetAlert Toast -->
    <?php
    if (isset($_SESSION['alert_icon'])) {
        $icon = $_SESSION['alert_icon'];
        $text = $_SESSION['alert_text'] ?? '';
        echo "<script>
            const Toast = Swal.mixin({
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 2500,
                timerProgressBar: true
            });
            Toast.fire({ icon: '$icon', title: '$text' });
        </script>";
        unset($_SESSION['alert_icon'], $_SESSION['alert_text']);
    }
    ?>
</body>
</html>