<?php
session_start();

// ✅ Session check
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}
if ($_SESSION['userRole'] !== 'admin' && ($_SESSION['designation'] ?? '') !== 'hr') {
    echo "<script>
        alert('You cannot open this page. Please log in with the correct role.');
        window.location.href = 'login.php';
    </script>";
    exit;
}

include('../include/db_config.php');
date_default_timezone_set('Asia/Kolkata');

$selectedYear = isset($_GET['year']) ? mysqli_real_escape_string($con, $_GET['year']) : date('Y');

// Fetch available years for filter
$yearsQ = mysqli_query($con, "SELECT DISTINCT YEAR(from_date) as y FROM holidays ORDER BY y DESC");
$availableYears = [];
if ($yearsQ) {
    while ($yr = mysqli_fetch_assoc($yearsQ)) {
        if ($yr['y']) $availableYears[] = $yr['y'];
    }
}
if (!in_array(date('Y'), $availableYears)) {
    $availableYears[] = date('Y');
    rsort($availableYears);
}

// Fetch holidays for selected year or all
$sql = "SELECT * FROM holidays ";
if ($selectedYear !== 'all') {
    $sql .= "WHERE YEAR(from_date) = '$selectedYear' OR YEAR(to_date) = '$selectedYear' ";
}
$sql .= "ORDER BY from_date ASC";

$holidaysResult = mysqli_query($con, $sql);
$holidaysData = [];

$totalAnnualCount = 0;
$totalDaysOffSum  = 0;
$nextHoliday      = null;
$minFutureDays    = 9999;
$todayTs          = strtotime(date('Y-m-d'));

$monthlyDistribution = array_fill(1, 12, 0);
$scopeCounts = [
    'All' => 0,
    'Kinwat' => 0,
    'Pune' => 0
];

if ($holidaysResult) {
    while ($row = mysqli_fetch_assoc($holidaysResult)) {
        $fromTs = strtotime($row['from_date']);
        $toTs   = strtotime($row['to_date']);
        $days = max(1, round(($toTs - $fromTs) / 86400) + 1);

        $row['duration_days'] = $days;
        $descLower = strtolower($row['title'] . ' ' . $row['description']);

        if (strpos($descLower, 'kinwat') !== false) {
            $row['scope'] = 'Kinwat Branch';
            $scopeCounts['Kinwat']++;
        } elseif (strpos($descLower, 'pune') !== false) {
            $row['scope'] = 'Pune Branch';
            $scopeCounts['Pune']++;
        } else {
            $row['scope'] = 'All Offices';
            $scopeCounts['All']++;
        }

        // Track next upcoming holiday
        if ($fromTs >= $todayTs) {
            $diffDays = round(($fromTs - $todayTs) / 86400);
            if ($diffDays < $minFutureDays) {
                $minFutureDays = $diffDays;
                $nextHoliday = $row;
                $nextHoliday['days_away'] = $diffDays;
            }
        }

        // Monthly distribution
        $m = (int)date('n', $fromTs);
        $monthlyDistribution[$m] += $days;

        $totalAnnualCount++;
        $totalDaysOffSum += $days;
        $holidaysData[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <title>SETTribe · Holiday Calendar &amp; Management Analytics</title>
    <?php include('../partials/_links.php'); ?>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap4.min.css">
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
            --danger-light: #fef2f2;
            --info: #06b6d4;
            --info-light: #ecfeff;
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
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #334155 100%);
            border-radius: var(--radius-lg);
            padding: 24px 28px;
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
            width: 220px;
            height: 220px;
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
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.76rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
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
        .kpi-green::before   { background: var(--success); }
        .kpi-purple::before  { background: var(--purple); }
        .kpi-amber::before   { background: var(--warning); }

        .kpi-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
        .kpi-title { font-size: 0.78rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.4px; }
        .kpi-icon-wrap { width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1rem; }
        .icon-blue   { background: var(--primary-light); color: var(--primary); }
        .icon-green  { background: var(--success-light); color: var(--success); }
        .icon-purple { background: #f3e8ff; color: var(--purple); }
        .icon-amber  { background: var(--warning-light); color: var(--warning); }

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

        #recordsTable { width: 100% !important; border-collapse: separate; border-spacing: 0; }
        #recordsTable thead th {
            background: linear-gradient(135deg, #1e293b, #334155);
            color: #ffffff;
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            padding: 12px 14px;
            border: none;
            text-align: center;
        }
        #recordsTable thead th:first-child { border-top-left-radius: 8px; }
        #recordsTable thead th:last-child { border-top-right-radius: 8px; }

        #recordsTable tbody td {
            font-size: 0.84rem;
            padding: 12px 14px;
            vertical-align: middle;
            border-bottom: 1px solid #f1f5f9;
            text-align: center;
            color: #334155;
        }
        #recordsTable tbody tr:hover { background-color: #f8fafc; }

        .badge-scope { padding: 4px 10px; border-radius: 12px; font-size: 0.72rem; font-weight: 700; }
        .scope-all { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }
        .scope-kinwat { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
        .scope-pune { background: #f3e8ff; color: #7e22ce; border: 1px solid #e9d5ff; }

        .badge-days { background: #ecfdf5; color: #047857; font-weight: 800; border: 1px solid #a7f3d0; border-radius: 12px; padding: 2px 8px; font-size: 0.75rem; }
        .badge-status { padding: 3px 8px; border-radius: 10px; font-size: 0.72rem; font-weight: 700; }
        .status-upcoming { background: #dcfce7; color: #15803d; }
        .status-today { background: #fee2e2; color: #b91c1c; font-weight: 800; animation: pulse 1.5s infinite; }
        .status-passed { background: #f1f5f9; color: #64748b; }

        .btn-gradient-primary {
            background: linear-gradient(135deg, #4f46e5 0%, #3730a3 100%);
            color: #ffffff; border: none; font-weight: 700; padding: 8px 18px; border-radius: var(--radius-md); box-shadow: 0 4px 12px rgba(79, 70, 229, 0.25); transition: all 0.2s;
        }
        .btn-gradient-primary:hover { color: #fff; transform: translateY(-1px); }

        .actions-cell { display: flex; justify-content: center; align-items: center; gap: 6px; }
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
                                    <i class="fas fa-calendar-alt"></i> Official Company Calendar
                                </div>
                                <h2 style="font-size: 1.6rem; font-weight: 800; margin-bottom: 4px;">
                                    Holiday Calendar &amp; Management Analytics
                                </h2>
                                <p style="font-size: 0.85rem; opacity: 0.88; margin: 0;">
                                    Official holiday schedule, branch-specific leaves, holiday duration metrics, and upcoming events calendar.
                                </p>
                            </div>
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <form method="GET" class="d-flex align-items-center mr-2">
                                    <select name="year" class="form-control form-control-sm border-0 font-weight-bold" onchange="this.form.submit()" style="border-radius:20px; padding:4px 14px; background:rgba(255,255,255,0.9); color:#0f172a; height:34px;">
                                        <option value="all" <?= ($selectedYear === 'all') ? 'selected' : ''; ?>>All Years</option>
                                        <?php foreach ($availableYears as $yr): ?>
                                            <option value="<?= $yr ?>" <?= ($selectedYear == $yr) ? 'selected' : ''; ?>><?= $yr ?> Calendar</option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                                <a href="add_holidays.php" class="btn btn-gradient-primary">
                                    <i class="fas fa-plus mr-1"></i> Add Holiday
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- ── KPI METRICS ROW ── -->
                    <div class="row mb-4">
                        <div class="col-xl-3 col-md-6 mb-3 mb-xl-0">
                            <div class="kpi-card kpi-blue">
                                <div class="kpi-header">
                                    <span class="kpi-title">Total Holidays</span>
                                    <div class="kpi-icon-wrap icon-blue"><i class="fas fa-umbrella-beach"></i></div>
                                </div>
                                <div>
                                    <div class="kpi-value"><?= $totalAnnualCount ?></div>
                                    <div class="kpi-sub">Events in <?= $selectedYear === 'all' ? 'All Years' : $selectedYear ?></div>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-3 col-md-6 mb-3 mb-xl-0">
                            <div class="kpi-card kpi-green">
                                <div class="kpi-header">
                                    <span class="kpi-title">Total Days Off</span>
                                    <div class="kpi-icon-wrap icon-green"><i class="fas fa-calendar-check"></i></div>
                                </div>
                                <div>
                                    <div class="kpi-value"><?= $totalDaysOffSum ?> Days</div>
                                    <div class="kpi-sub">Cumulative Paid Days Off</div>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-3 col-md-6 mb-3 mb-xl-0">
                            <div class="kpi-card kpi-purple">
                                <div class="kpi-header">
                                    <span class="kpi-title">Next Holiday</span>
                                    <div class="kpi-icon-wrap icon-purple"><i class="fas fa-hourglass-half"></i></div>
                                </div>
                                <div>
                                    <div class="kpi-value" style="font-size:1.25rem;">
                                        <?= $nextHoliday ? htmlspecialchars((string)$nextHoliday['title']) : 'No Upcoming' ?>
                                    </div>
                                    <div class="kpi-sub">
                                        <?php if ($nextHoliday): ?>
                                            <?= date('d M Y', strtotime($nextHoliday['from_date'])) ?>
                                            (<?= $nextHoliday['days_away'] == 0 ? 'Today!' : 'In ' . $nextHoliday['days_away'] . ' days' ?>)
                                        <?php else: ?>
                                            All events passed
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-3 col-md-6 mb-3 mb-xl-0">
                            <div class="kpi-card kpi-amber">
                                <div class="kpi-header">
                                    <span class="kpi-title">Office Scope Split</span>
                                    <div class="kpi-icon-wrap icon-amber"><i class="fas fa-building"></i></div>
                                </div>
                                <div>
                                    <div class="kpi-value" style="font-size:1.2rem;">
                                        <span class="text-primary"><?= $scopeCounts['All'] ?> All</span> /
                                        <span class="text-warning"><?= $scopeCounts['Kinwat'] ?> Kinwat</span> /
                                        <span class="text-purple"><?= $scopeCounts['Pune'] ?> Pune</span>
                                    </div>
                                    <div class="kpi-sub">Company-Wide vs Branch Specific</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── CHARTS ROW ── -->
                    <div class="row mb-4">
                        <!-- Monthly Distribution Bar Chart -->
                        <div class="col-lg-8 mb-3 mb-lg-0">
                            <div class="analytics-table-card h-100" style="padding:20px;">
                                <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-2">
                                    <h5 style="font-size:.95rem; font-weight:800; color:#0f172a; margin:0;">
                                        <i class="fas fa-chart-bar text-primary mr-1"></i> Monthly Holiday Distribution (<?= $selectedYear === 'all' ? 'All Years' : $selectedYear ?>)
                                    </h5>
                                    <span class="badge badge-light border" style="font-size:.72rem;">Holiday Days Per Month</span>
                                </div>
                                <div style="position: relative; height: 230px;">
                                    <canvas id="monthlyHolidayChart"></canvas>
                                </div>
                            </div>
                        </div>

                        <!-- Scope Doughnut Chart -->
                        <div class="col-lg-4">
                            <div class="analytics-table-card h-100" style="padding:20px;">
                                <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-2">
                                    <h5 style="font-size:.95rem; font-weight:800; color:#0f172a; margin:0;">
                                        <i class="fas fa-chart-pie text-purple mr-1"></i> Office Scope Split
                                    </h5>
                                    <span class="badge badge-light border" style="font-size:.72rem;">Location Categories</span>
                                </div>
                                <div style="position: relative; height: 230px;">
                                    <canvas id="scopeDoughnutChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── TABLE CARD ── -->
                    <div class="analytics-table-card">
                        <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                            <div>
                                <h5 class="mb-0 font-weight-bold" style="color:#0f172a; font-size:1.05rem;">
                                    <i class="fas fa-calendar-check text-primary mr-1"></i> Registered Holidays Master Log
                                </h5>
                                <p style="font-size:0.8rem; color:#64748b; margin:2px 0 0 0;">
                                    Showing official holidays for year: <strong><?= $selectedYear === 'all' ? 'All Years' : $selectedYear ?></strong>
                                </p>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table" id="recordsTable">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th style="text-align:left;">Holiday Title</th>
                                        <th>Date Range</th>
                                        <th>Duration</th>
                                        <th>Scope / Office</th>
                                        <th>Status / Countdown</th>
                                        <th style="text-align:left;">Description</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $sr = 1;
                                    foreach ($holidaysData as $row):
                                        $fDate = date('d M Y', strtotime($row['from_date']));
                                        $tDate = date('d M Y', strtotime($row['to_date']));
                                        $rangeStr = ($fDate === $tDate) ? $fDate : "{$fDate} → {$tDate}";

                                        $fromTs = strtotime($row['from_date']);
                                        $toTs   = strtotime($row['to_date']);

                                        if ($todayTs > $toTs) {
                                            $statusHtml = '<span class="badge-status status-passed">Passed</span>';
                                        } elseif ($todayTs >= $fromTs && $todayTs <= $toTs) {
                                            $statusHtml = '<span class="badge-status status-today"><i class="fas fa-star mr-1"></i>Today!</span>';
                                        } else {
                                            $diff = round(($fromTs - $todayTs) / 86400);
                                            $statusHtml = "<span class=\"badge-status status-upcoming\"><i class=\"fas fa-clock mr-1\"></i>In {$diff} days</span>";
                                        }

                                        $scopeClass = match ($row['scope']) {
                                            'Kinwat Branch' => 'scope-kinwat',
                                            'Pune Branch'   => 'scope-pune',
                                            default         => 'scope-all',
                                        };
                                    ?>
                                        <tr>
                                            <td class="font-weight-bold text-muted"><?= $sr++; ?></td>
                                            <td style="text-align:left;">
                                                <strong style="color:#0f172a; font-size:.9rem;"><?= htmlspecialchars((string)$row['title']); ?></strong>
                                            </td>
                                            <td style="white-space:nowrap; font-weight:600;"><?= $rangeStr ?></td>
                                            <td><span class="badge-days"><?= $row['duration_days'] ?> <?= $row['duration_days'] == 1 ? 'day' : 'days' ?></span></td>
                                            <td><span class="badge-scope <?= $scopeClass ?>"><?= $row['scope'] ?></span></td>
                                            <td><?= $statusHtml ?></td>
                                            <td style="text-align:left; color:#64748b; font-size:.82rem;">
                                                <?= htmlspecialchars((string)($row['description'] ?: 'No additional notes')) ?>
                                            </td>
                                            <td class="actions-cell">
                                                <a href="edit_holiday.php?id=<?= $row['id']; ?>" class="btn btn-sm btn-primary px-2 py-1" title="Edit Holiday">
                                                    <i class="fa fa-edit"></i>
                                                </a>
                                                <button class="btn btn-sm btn-danger px-2 py-1" onclick="deleteHoliday(<?= $row['id']; ?>)" title="Delete Holiday">
                                                    <i class="fa fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
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
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap4.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="../js/off-canvas.js"></script>
    <script src="../js/hoverable-collapse.js"></script>
    <script src="../js/template.js"></script>
    <script src="../js/settings.js"></script>

    <script>
        $(document).ready(function () {
            $('#recordsTable').DataTable({
                pageLength: 15,
                order: [[ 2, "asc" ]],
                language: { search: "<i class='fas fa-search text-muted mr-1'></i> Search Holidays:" }
            });

            // Monthly Bar Chart
            var ctxBar = document.getElementById('monthlyHolidayChart').getContext('2d');
            new Chart(ctxBar, {
                type: 'bar',
                data: {
                    labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
                    datasets: [{
                        label: 'Holiday Days Off',
                        data: <?= json_encode(array_values($monthlyDistribution)) ?>,
                        backgroundColor: '#4f46e5',
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

            // Scope Doughnut Chart
            var ctxDoughnut = document.getElementById('scopeDoughnutChart').getContext('2d');
            new Chart(ctxDoughnut, {
                type: 'doughnut',
                data: {
                    labels: ['All Offices', 'Kinwat Branch', 'Pune Branch'],
                    datasets: [{
                        data: [
                            <?= $scopeCounts['All'] ?>,
                            <?= $scopeCounts['Kinwat'] ?>,
                            <?= $scopeCounts['Pune'] ?>
                        ],
                        backgroundColor: ['#0369a1', '#b45309', '#7e22ce'],
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    legend: { position: 'right', labels: { boxWidth: 12, fontSize: 11 } }
                }
            });
        });

        <?php if(isset($_SESSION['popup'])): ?>
        Swal.fire({
            icon: '<?= $_SESSION['popup']['type'] ?>',
            title: '<?= $_SESSION['popup']['message'] ?>',
            showConfirmButton: false,
            timer: 2000
        });
        <?php unset($_SESSION['popup']); endif; ?>

        function deleteHoliday(id) {
            Swal.fire({
                title: 'Are you sure?',
                text: "This holiday will be deleted from the company calendar!",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Yes, delete it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = "delete_holiday.php?id=" + id;
                }
            });
        }
    </script>
</body>
</html>
