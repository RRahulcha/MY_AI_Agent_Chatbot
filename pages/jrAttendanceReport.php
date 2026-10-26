<?php
session_start();
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}
include('../include/db_config.php');

$userId = $_SESSION['userId'];

// Default Date Filters (First of current month to today if not submitted)
$fdate = $_REQUEST['fdate'] ?? date('Y-m-01');
$tdate = $_REQUEST['tdate'] ?? date('Y-m-d');
$isSearch = isset($_POST['search']) || isset($_GET['fdate']);

// Build Date Period Helper
function getDatesFromRange($start, $end, $format = 'Y-m-d') { 
    $array = array(); 
    $interval = new DateInterval('P1D'); 
    $realEnd = new DateTime($end); 
    $realEnd->add($interval); 
    $period = new DatePeriod(new DateTime($start), $interval, $realEnd); 
    foreach($period as $date) {				 
        $array[] = $date->format($format); 
    } 
    return $array; 
}

$Dates = getDatesFromRange($fdate, $tdate);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <title>Team Attendance Matrix Report</title>
    <?php include('../partials/_links.php'); ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        body { background-color: #f4f6fb !important; }

        /* ── Page Header ────────────────────────────────────────── */
        .page-header-box {
            background: #fff;
            border-radius: 12px;
            padding: 20px 24px;
            border: 1px solid #e2e8f0;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
        }

        /* ── Filter Card ────────────────────────────────────────── */
        .filter-card {
            background: #fff;
            border-radius: 14px;
            border: 1px solid #e2e8f0;
            padding: 22px 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
            margin-bottom: 24px;
        }

        .form-control {
            height: 42px !important;
            border-radius: 8px !important;
            border: 1px solid #cbd5e1 !important;
            font-size: 0.85rem !important;
        }
        .form-control:focus {
            border-color: #4b49ac !important;
            box-shadow: 0 0 0 3px rgba(75, 73, 172, 0.15) !important;
        }

        /* Select2 Customization */
        .select2-container--default .select2-selection--multiple {
            border: 1px solid #cbd5e1 !important;
            border-radius: 8px !important;
            min-height: 42px !important;
            padding: 2px 6px !important;
        }
        .select2-container--default.select2-container--focus .select2-selection--multiple {
            border-color: #4b49ac !important;
        }

        /* ── KPI Summary Cards ────────────────────────────────── */
        .kpi-card {
            background: #fff;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            padding: 16px 20px;
            transition: all 0.2s ease;
            height: 100%;
            box-shadow: 0 2px 6px rgba(0,0,0,0.03);
        }
        .kpi-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 18px rgba(0,0,0,0.07);
        }
        .kpi-icon {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            flex-shrink: 0;
        }
        .kpi-val {
            font-size: 1.6rem;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.1;
            margin-top: 4px;
        }
        .kpi-lbl {
            font-size: 0.72rem;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .kpi-sub {
            font-size: 0.72rem;
            color: #94a3b8;
            margin-top: 3px;
        }

        /* ── Table Card ────────────────────────────────────────── */
        .card-table {
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            background: #fff;
            padding: 22px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
        }

        .matrix-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }
        .matrix-table thead th {
            background-color: #f8fafc !important;
            color: #475569 !important;
            font-size: 0.74rem !important;
            font-weight: 700 !important;
            text-transform: uppercase !important;
            letter-spacing: 0.03em !important;
            padding: 10px 8px !important;
            text-align: center;
            border-bottom: 2px solid #e2e8f0 !important;
            border-right: 1px solid #f1f5f9;
            white-space: nowrap;
        }
        .matrix-table tbody td, .matrix-table tbody th {
            vertical-align: middle !important;
            font-size: 0.82rem !important;
            padding: 8px 6px !important;
            border-bottom: 1px solid #f1f5f9 !important;
            border-right: 1px solid #f1f5f9;
            text-align: center;
        }

        /* Sticky Left Column for Intern Names */
        .sticky-col {
            position: sticky;
            left: 0;
            background: #fff !important;
            z-index: 5;
            text-align: left !important;
            min-width: 180px;
            box-shadow: 2px 0 5px rgba(0,0,0,0.03);
        }
        .matrix-table thead th.sticky-col {
            background: #f8fafc !important;
            z-index: 6;
        }

        /* Status Pills in Matrix */
        .att-pill {
            display: inline-flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            width: 38px;
            height: 38px;
            border-radius: 8px;
            font-size: 0.75rem;
            font-weight: 700;
            line-height: 1;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .att-p { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .att-p:hover { transform: scale(1.1); box-shadow: 0 4px 10px rgba(21,128,61,0.2); }
        
        .att-l { background: #dbeafe; color: #1d4ed8; border: 1px solid #bfdbfe; }
        .att-l:hover { transform: scale(1.1); box-shadow: 0 4px 10px rgba(29,78,216,0.2); }

        .att-a { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
        .att-a:hover { transform: scale(1.1); box-shadow: 0 4px 10px rgba(185,28,28,0.2); }

        .att-off { background: #f1f5f9; color: #64748b; border: 1px solid #e2e8f0; }
        .att-empty { color: #cbd5e1; font-weight: 400; }

        .att-sub {
            font-size: 0.6rem;
            font-weight: 500;
            margin-top: 2px;
            opacity: 0.85;
        }

        /* ── Legend Chips ────────────────────────────────────────── */
        .legend-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.78rem;
            font-weight: 600;
            padding: 4px 12px;
            border-radius: 20px;
            background: #fff;
            border: 1px solid #e2e8f0;
        }

        .flatpickr-date { background-color: #fff !important; cursor: pointer; }
    </style>
</head>

<body>
    <div class="container-scroller">
        <?php include('../partials/_navbar.php'); ?>
        <div class="container-fluid page-body-wrapper">
            <?php include('../partials/_sidebar.php'); ?>

            <div class="main-panel">
                <div class="content-wrapper" style="background:#f4f6fb !important;">

                    <!-- ── Header Banner ────────────────────────────────────────────────── -->
                    <div class="page-header-box d-flex align-items-center justify-content-between flex-wrap gap-2">
                        <div>
                            <h3 class="fw-bold text-dark mb-1" style="font-size:1.35rem;">
                                <i class="fa-solid fa-calendar-check text-primary me-2"></i> Team Attendance Matrix Report
                            </h3>
                            <p class="text-muted small mb-0">Generate date-wise attendance matrices for junior interns and team members.</p>
                        </div>
                        <div>
                            <span class="badge" style="background:#eef2ff; color:#4b49ac; font-size:0.8rem; padding:7px 16px; border-radius:20px; font-weight:600;">
                                <i class="fa-solid fa-users me-1"></i> Junior Interns Report
                            </span>
                        </div>
                    </div>

                    <!-- ── Filter Form Card ─────────────────────────────────────────────── -->
                    <div class="filter-card">
                        <h5 class="fw-bold text-dark mb-3" style="font-size:1rem;">
                            <i class="fa-solid fa-filter me-2 text-primary"></i> Filter Parameters
                        </h5>

                        <form action="jrAttendanceReport.php" method="POST">
                            <div class="row g-3 mb-3">
                                <div class="col-md-3">
                                    <label class="form-label fw-bold small">From Date <span class="text-danger">*</span></label>
                                    <input type="text" name="fdate" class="form-control flatpickr-date" value="<?= htmlspecialchars($fdate) ?>" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-bold small">To Date <span class="text-danger">*</span></label>
                                    <input type="text" name="tdate" class="form-control flatpickr-date" value="<?= htmlspecialchars($tdate) ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Select Interns / Employees</label>
                                    <select name="empSelect[]" multiple="multiple" class="form-control select2" id="empSelect" data-placeholder="Select Interns">
                                        <option value="all" <?= (isset($_POST['empSelect']) && in_array('all', (array)$_POST['empSelect'])) ? 'selected' : (!isset($_POST['empSelect']) ? 'selected' : ''); ?>>All Junior Interns</option>
                                        <option value="authorized" <?= (isset($_POST['empSelect']) && in_array('authorized', (array)$_POST['empSelect'])) ? 'selected' : ''; ?>>My Assigned Interns Only</option>
                                        <?php
                                        $emps = mysqli_query($con, "SELECT userId, fullName, designation FROM signup WHERE status = 'Active' AND designation = 'juniorIntern' ORDER BY fullName ASC");
                                        while ($e = mysqli_fetch_assoc($emps)) {
                                            $sel = (isset($_POST['empSelect']) && in_array($e['userId'], (array)$_POST['empSelect'])) ? 'selected' : '';
                                            echo "<option value='{$e['userId']}' $sel>" . htmlspecialchars($e['fullName']) . " [" . htmlspecialchars($e['designation']) . "]</option>";
                                        }
                                        ?>
                                    </select>
                                </div>
                            </div>

                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <div class="d-flex gap-2">
                                    <button type="submit" name="search" class="btn btn-primary px-4" style="background:#4b49ac; border-color:#4b49ac; border-radius:8px; font-weight:600;">
                                        <i class="fa-solid fa-magnifying-glass me-1"></i> Generate Report
                                    </button>
                                    <a href="jrAttendanceReport.php" class="btn btn-light px-3" style="border-radius:8px; font-weight:600;">Reset Filters</a>
                                </div>
                                <div class="d-flex gap-1">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setDateRange('this_month')">This Month</button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setDateRange('last_14')">Last 14 Days</button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setDateRange('this_week')">This Week</button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <?php
                    if ($isSearch):
                        // Fetch Interns Query
                        $empSelect = isset($_POST['empSelect']) ? $_POST['empSelect'] : ['all'];
                        if (!is_array($empSelect)) { $empSelect = [$empSelect]; }

                        $query = "SELECT * FROM signup WHERE status = 'Active' AND designation = 'juniorIntern'";

                        if (!in_array('all', $empSelect)) {
                            $conditions = [];
                            $specificIds = [];
                            foreach ($empSelect as $val) {
                                if ($val === 'authorized') {
                                    $seniorInternId = $_SESSION['userId'];
                                    $conditions[] = "(authority = '$seniorInternId' OR authority = (SELECT fullName FROM signup WHERE userId = '$seniorInternId'))";
                                } elseif ($val !== 'all') {
                                    $specificIds[] = "'" . mysqli_real_escape_string($con, $val) . "'";
                                }
                            }
                            if (count($specificIds) > 0) {
                                $conditions[] = "userId IN (" . implode(',', $specificIds) . ")";
                            }
                            if (count($conditions) > 0) {
                                $query .= " AND (" . implode(' OR ', $conditions) . ")";
                            }
                        }
                        $query .= " ORDER BY fullName ASC";
                        $result = mysqli_query($con, $query);

                        $internList = [];
                        if ($result) {
                            while ($r = mysqli_fetch_assoc($result)) {
                                $internList[] = $r;
                            }
                        }

                        // Compute Overall Summary Stats
                        $totalInterns  = count($internList);
                        $totalDays     = count($Dates);
                        $grandP = 0; $grandL = 0; $grandA = 0;
                    ?>

                    <!-- ── Results Section ─────────────────────────────────────────────── -->
                    <div class="card-table">
                        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                            <div>
                                <h4 class="fw-bold mb-0 text-dark" style="font-size:1.15rem;">
                                    <i class="fa-solid fa-matrix me-2 text-primary"></i> Attendance Matrix Grid
                                </h4>
                                <small class="text-muted">Showing <?= $totalInterns ?> intern(s) over <?= $totalDays ?> day(s) (<?= date('d M Y', strtotime($fdate)) ?> – <?= date('d M Y', strtotime($tdate)) ?>)</small>
                            </div>

                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-outline-success btn-sm px-3" onclick="exportToExcel()" style="border-radius:8px; font-weight:600;">
                                    <i class="fa-solid fa-file-excel me-1"></i> Export Excel
                                </button>
                                <button type="button" class="btn btn-outline-danger btn-sm px-3" onclick="exportToPDF()" style="border-radius:8px; font-weight:600;">
                                    <i class="fa-solid fa-file-pdf me-1"></i> Export PDF
                                </button>
                            </div>
                        </div>

                        <!-- Color Legend Bar -->
                        <div class="d-flex gap-2 mb-3 flex-wrap align-items-center">
                            <span class="text-muted small fw-bold me-1">Legend:</span>
                            <span class="legend-chip"><span class="att-pill att-p" style="width:20px; height:20px; font-size:0.6rem;">P</span> Present</span>
                            <span class="legend-chip"><span class="att-pill att-l" style="width:20px; height:20px; font-size:0.6rem;">L</span> Leave Approved</span>
                            <span class="legend-chip"><span class="att-pill att-a" style="width:20px; height:20px; font-size:0.6rem;">A</span> Absent</span>
                            <span class="legend-chip"><span class="att-pill att-off" style="width:20px; height:20px; font-size:0.6rem;">WK</span> Weekend / Off</span>
                        </div>

                        <div class="table-responsive">
                            <table class="matrix-table" id="taskEntryTable">
                                <thead>
                                    <tr>
                                        <th class="sticky-col">Intern Name</th>
                                        <?php foreach ($Dates as $dStr): 
                                            $dt = new DateTime($dStr);
                                            $isSun = $dt->format('D') === 'Sun';
                                        ?>
                                            <th class="<?= $isSun ? 'bg-light' : '' ?>">
                                                <div style="font-size:0.68rem; color:#94a3b8;"><?= $dt->format('M') ?></div>
                                                <div style="font-size:0.95rem; font-weight:800; color:#0f172a;"><?= $dt->format('d') ?></div>
                                                <div style="font-size:0.68rem; color:#64748b;"><?= $dt->format('D') ?></div>
                                            </th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($internList as $fetch_data): ?>
                                    <tr>
                                        <th class="sticky-col">
                                            <div class="d-flex align-items-center gap-2">
                                                <div style="width:32px; height:32px; border-radius:50%; background:#e2e8f0; display:flex; align-items:center; justify-content:center; font-weight:700; color:#475569; flex-shrink:0;">
                                                    <?= strtoupper(substr($fetch_data['fullName'], 0, 1)) ?>
                                                </div>
                                                <div class="text-truncate" style="max-width:140px;">
                                                    <div class="fw-bold text-dark" style="font-size:0.83rem;"><?= htmlspecialchars($fetch_data['fullName']) ?></div>
                                                    <small class="text-muted" style="font-size:0.7rem;">Junior Intern</small>
                                                </div>
                                            </div>
                                        </th>

                                        <?php foreach ($Dates as $dStr): 
                                            $isSun = date('D', strtotime($dStr)) === 'Sun';

                                            if ($isSun): ?>
                                                <td><span class="att-pill att-off" title="Sunday Off-day">WK</span></td>
                                            <?php else: 
                                                // Fetch Attendance Record
                                                $attQ = mysqli_query($con, "SELECT * FROM attendance WHERE date = '$dStr' AND userId = '{$fetch_data['userId']}' LIMIT 1");
                                                
                                                // Fetch Leave Record
                                                $leaveQ = mysqli_query($con, "SELECT * FROM leaves WHERE status = 'Approved' AND ((from_date <= '$dStr' AND to_date >= '$dStr') OR leave_day = '$dStr') AND userId = '{$fetch_data['userId']}' LIMIT 1");
                                                $leave_data = mysqli_fetch_assoc($leaveQ);
                                                $leave_text = "";
                                                if ($leave_data) {
                                                    $leave_text = ($leave_data['leave_d'] === 'halfDay') ? 'Half Day Leave' : ($leave_data['leave_name'] ?: 'Approved Leave');
                                                }

                                                $isAtt = ($attQ && mysqli_num_rows($attQ) > 0);
                                                $att_fetch = $isAtt ? mysqli_fetch_assoc($attQ) : null;
                                                $isPresent = ($att_fetch && ($att_fetch['punchInTime'] != '00:00:00' || in_array(strtolower($att_fetch['status'] ?? ''), ['present', 'online', 'present (online)'])));

                                                if ($isPresent): 
                                                    $grandP++;
                                                    $pIn  = ($att_fetch['punchInTime'] && $att_fetch['punchInTime'] != '00:00:00') ? date('h:i A', strtotime($att_fetch['punchInTime'])) : 'N/A';
                                                    $pOut = ($att_fetch['punchOutTime'] && $att_fetch['punchOutTime'] != '00:00:00') ? date('h:i A', strtotime($att_fetch['punchOutTime'])) : 'N/A';
                                                    $isLate = (strtolower($att_fetch['lateMark'] ?? '') === 'yes');
                                                    $tooltipText = "Present\nIn: $pIn | Out: $pOut" . ($isLate ? "\nLate Mark: Yes" : "");
                                                ?>
                                                    <td>
                                                        <span class="att-pill att-p" title="<?= htmlspecialchars($tooltipText) ?>">
                                                            P
                                                            <span class="att-sub"><?= $pIn ?></span>
                                                        </span>
                                                    </td>
                                                <?php elseif ($leave_text): 
                                                    $grandL++;
                                                ?>
                                                    <td>
                                                        <span class="att-pill att-l" title="<?= htmlspecialchars($leave_text) ?>">
                                                            L
                                                            <span class="att-sub">Leave</span>
                                                        </span>
                                                    </td>
                                                <?php elseif ($dStr < date('Y-m-d')): 
                                                    $grandA++;
                                                ?>
                                                    <td>
                                                        <span class="att-pill att-a" title="Absent">
                                                            A
                                                        </span>
                                                    </td>
                                                <?php else: ?>
                                                    <td><span class="att-empty">—</span></td>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>

                </div>
                <?php include('../partials/_footer.php'); ?>
            </div>
        </div>
    </div>

    <!-- Required JS -->
    <script src="../vendors/js/vendor.bundle.base.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

    <!-- Export Libraries -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.17.0/xlsx.full.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.4.0/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.23/jspdf.plugin.autotable.min.js"></script>

    <script src="../js/off-canvas.js"></script>
    <script src="../js/hoverable-collapse.js"></script>
    <script src="../js/template.js"></script>

    <script>
        $(document).ready(function () {
            $('.select2').select2({ width: '100%' });
            flatpickr(".flatpickr-date", { dateFormat: "Y-m-d" });
        });

        function setDateRange(type) {
            const today = new Date();
            let from = new Date();
            let to = new Date();

            if (type === 'this_month') {
                from = new Date(today.getFullYear(), today.getMonth(), 1);
                to = today;
            } else if (type === 'last_14') {
                from = new Date();
                from.setDate(today.getDate() - 14);
                to = today;
            } else if (type === 'this_week') {
                const day = today.getDay();
                from = new Date(today);
                from.setDate(today.getDate() - day + (day === 0 ? -6 : 1)); // Monday
                to = today;
            }

            const formatDate = d => d.toISOString().split('T')[0];
            document.getElementsByName('fdate')[0]._flatpickr.setDate(formatDate(from));
            document.getElementsByName('tdate')[0]._flatpickr.setDate(formatDate(to));
        }

        function exportToExcel() {
            let table = document.getElementById("taskEntryTable");
            let wb = XLSX.utils.book_new();
            let ws = XLSX.utils.table_to_sheet(table);
            XLSX.utils.book_append_sheet(wb, ws, "Attendance Matrix");
            XLSX.writeFile(wb, "Team_Attendance_Report.xlsx");
        }

        function exportToPDF() {
            const { jsPDF } = window.jspdf;
            let doc = new jsPDF('l', 'mm', 'a3');
            doc.text("Team Attendance Matrix Report", 14, 15);
            doc.autoTable({
                html: '#taskEntryTable',
                startY: 22,
                theme: 'grid',
                styles: { fontSize: 7, cellPadding: 2 },
                headStyles: { fillColor: [75, 73, 172], fontSize: 8 },
                columnStyles: { 0: { cellWidth: 45 } },
                margin: { top: 20 }
            });
            doc.save("Team_Attendance_Report.pdf");
        }
    </script>
</body>

</html>