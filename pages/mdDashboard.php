<?php
session_start();
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}

// ⭐ ADD THIS LINE - Check password status
include('../include/checkPasswordStatus.php');


if (!isset($_SESSION['userId']) && empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}
// Role based access - MD only
$userRole = $_SESSION['userRole'] ?? '';
$roleNormalized = strtolower(str_replace('_', '', $userRole));

if ($roleNormalized !== 'md') {
    echo '<script>
        alert("You are not authorized to access this page.");
        window.location.href = "login.php";
        </script>';
    die();
}
include('../include/db_config.php');
require_once('availabilityEngine.php');
$today = date('Y-m-d');

// --- Data Fetching Logic ---

// Initialize stats array
// Structure: 'LocationName' => ['active' => 0, 'present' => 0, 'absent' => 0, 'leave' => 0, 'late' => 0]
$locationStats = [];

// 1. Fetch all Active Employees and categorize by Location
// We'll treat 'Online' type as a location 'Online'.
// For 'Offline' type, we use the 'office' column.

$employees = []; // Store userId -> Location mapping
$sqlUsers = "SELECT userId, type, office, expectedPunchIn FROM signup WHERE designation NOT IN ('admin', 'developer') AND regstatus = 'Approved' AND office != ''";
$resUsers = mysqli_query($con, $sqlUsers);

while ($row = mysqli_fetch_assoc($resUsers)) {
    $loc = '';
    if (strtolower($row['type']) == 'online') {
        $loc = 'Online';
    } else {
        $loc = !empty($row['office']) ? $row['office'] : 'Unknown';
    }

    // Normalize location name (e.g., capitalize)
    $loc = ucfirst($loc);

    if (!isset($locationStats[$loc])) {
        $locationStats[$loc] = [
            'active' => 0,
            'present' => 0,
            'absent' => 0,
            'leave' => 0,
            'late' => 0,
            'tour' => 0
        ];
    }

    $locationStats[$loc]['active']++;

    // Store employee details for further processing
    $employees[$row['userId']] = [
        'location' => $loc,
        'expectedPunchIn' => $row['expectedPunchIn']
    ];
}

// 2. Fetch Attendance for Today (Present & Late)
$sqlAttendance = "SELECT userId, punchInTime FROM attendance WHERE date = '$today'";
$resAttendance = mysqli_query($con, $sqlAttendance);

$presentUserIds = [];

while ($row = mysqli_fetch_assoc($resAttendance)) {
    $uid = $row['userId'];
    if (isset($employees[$uid])) {
        $loc = $employees[$uid]['location'];
        $locationStats[$loc]['present']++;
        $presentUserIds[$uid] = true; // Optimize for O(1) lookup

        // Check Late
        $expected = $employees[$uid]['expectedPunchIn'];
        $punchIn = $row['punchInTime'];

        // Simple time comparison
        if (!empty($expected) && !empty($punchIn) && $punchIn > $expected) {
            $locationStats[$loc]['late']++;
        }
    }
}


// 3. Fetch Tour Attendance for Today (Present & Late)
$sqlTourAttendance = "SELECT userId, punchInTime FROM tourAttendance WHERE date = '$today'";
$resTourAttendance = mysqli_query($con, $sqlTourAttendance);

$tourUserIds = [];

while ($row = mysqli_fetch_assoc($resTourAttendance)) {
    $uid = $row['userId'];
    if (isset($employees[$uid])) {
        $loc = $employees[$uid]['location'];
        $locationStats[$loc]['tour']++;
        $tourUserIds[$uid] = true; // Optimize for O(1) lookup
    }
}

// 4. Fetch Leaves for Today
$sqlLeaves = "SELECT userId FROM leaves WHERE from_date <= '$today' AND to_date>= '$today' AND
                            status = 'Approved'"; // Assuming 'Approved' status is needed
$resLeaves = mysqli_query($con, $sqlLeaves);

$leaveUserIds = [];

while ($row = mysqli_fetch_assoc($resLeaves)) {
    $uid = $row['userId'];
    if (isset($employees[$uid])) {
        $loc = $employees[$uid]['location'];
        $locationStats[$loc]['leave']++;
        $leaveUserIds[$uid] = true; // Optimize for O(1) lookup
    }
}
// 5. Calculate Absent
// Absent = Active - Present - On Leave?
// Or strictly: Active users who are NOT in Present list AND NOT in Leave list.
foreach ($employees as $uid => $data) {
    $loc = $data['location'];
    $isPresent = isset($presentUserIds[$uid]);
    $isOnLeave = isset($leaveUserIds[$uid]);
    $isOnTour = isset($tourUserIds[$uid]);

    if (!$isPresent && !$isOnLeave && !$isOnTour) {
        $locationStats[$loc]['absent']++;
    }
}


// Calculate Grand Totals
$grandTotal = ['active' => 0, 'present' => 0, 'absent' => 0, 'leave' => 0, 'late' => 0, 'tour' => 0];
foreach ($locationStats as $loc => $stats) {
    $grandTotal['active'] += $stats['active'];
    $grandTotal['present'] += $stats['present'];
    $grandTotal['absent'] += $stats['absent'];
    $grandTotal['leave'] += $stats['leave'];
    $grandTotal['late'] += $stats['late'];
    $grandTotal['tour'] += $stats['tour'];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php
    include('../partials/_links.php');
    include('../include/sweetAlert.php');
    ?>
    <style>
        .stat-card {
            border-radius: 15px;
            color: white;
            padding: 20px;
            height: 100%;
            position: relative;
            overflow: hidden;
        }

        .stat-card h3 {
            font-size: 2.5rem;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .stat-card p {
            font-size: 1.1rem;
            opacity: 0.9;
        }

        .bg-gradient-primary {
            background: linear-gradient(45deg, #4b6cb7, #182848);
        }

        .bg-gradient-success {
            background: linear-gradient(45deg, #11998e, #38ef7d);
        }

        .bg-gradient-danger {
            background: linear-gradient(45deg, #cb2d3e, #ef473a);
        }

        .bg-gradient-warning {
            background: linear-gradient(45deg, #fce38a, #f38181);
        }

        .bg-gradient-info {
            background: linear-gradient(45deg, #36D1DC, #5B86E5);
        }

        /* Location Card Styling matching the image */
        .location-card {
            border: 1px solid #e3e3e3;
            border-radius: 8px;
            overflow: hidden;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            background: white;
        }

        .location-header {
            background-color: #dae8fc;
            /* Light blue from image */
            padding: 10px 15px;
            border-bottom: 1px solid #b5c7e6;
            font-weight: bold;
            color: #000;
            display: flex;
            justify-content: space-between;
        }

        .location-table {
            width: 100%;
            border-collapse: collapse;
        }

        .location-table th {
            text-align: center;
            padding: 5px;
            border-bottom: 1px solid #ddd;
            border-right: 1px solid #eee;
            background-color: #f8f9fa;
            font-weight: bold;
            font-size: 0.9rem;
        }

        .location-table td {
            text-align: center;
            padding: 10px;
            font-size: 1.2rem;
            font-weight: 600;
            border-right: 1px solid #eee;
        }

        .location-table th:last-child,
        .location-table td:last-child {
            border-right: none;
        }

        .table th,
        .table td {
            padding: 5px;
        }
    </style>
</head>

<body>
    <div class="container-scroller">
        <?php include('../partials/_navbar.php') ?>
        <div class="container-fluid page-body-wrapper">
            <?php include('../partials/_sidebar.php'); ?>

            <div class="main-panel">
                <div class="content-wrapper">

                    <div class="row mb-4">
                        <div class="col-12">
                            <h3 class="font-weight-bold">Today's Statistics <small
                                    class="text-muted">(<?php echo date('d M Y'); ?>)</small>
                            </h3>
                        </div>
                    </div>


                    <!-- Summary Cards -->
                    <div class="row justify-content-center mb-5">
                        <div class="col-xl-2 col-md-4 col-sm-6 mb-4">
                            <div class="stat-card bg-gradient-primary">
                                <a href="down_tracking.php?type=total" style="color: inherit; text-decoration: none;">
                                    <h3><?php echo $grandTotal['active']; ?></h3>
                                    <p>Total Active Employees</p>
                                </a>
                            </div>
                        </div>
                        <div class="col-xl-2 col-md-4 col-sm-6 mb-4">
                            <div class="stat-card bg-gradient-success">
                                <a href="down_tracking.php?type=present" style="color: inherit; text-decoration: none;">
                                    <h3><?php echo $grandTotal['present']; ?></h3>
                                    <p>Total Present</p>
                                </a>
                            </div>
                        </div>
                        <div class="col-xl-2 col-md-4 col-sm-6 mb-4">
                            <div class="stat-card bg-gradient-danger">
                                <a href="down_tracking.php?type=absent" style="color: inherit; text-decoration: none;">
                                    <h3><?php echo $grandTotal['absent']; ?></h3>
                                    <p>Total Absent</p>
                                </a>
                            </div>
                        </div>
                        <div class="col-xl-2 col-md-4 col-sm-6 mb-4">
                            <div class="stat-card bg-gradient-warning">
                                <a href="down_tracking.php?type=late" style="color: inherit; text-decoration: none;">
                                    <h3><?php echo $grandTotal['late']; ?></h3>
                                    <p>Total Late</p>
                                </a>
                            </div>
                        </div>
                        <div class="col-xl-2 col-md-4 col-sm-6 mb-4">
                            <div class="stat-card bg-gradient-info">
                                <a href="down_tracking.php?type=leave" style="color: inherit; text-decoration: none;">
                                    <h3><?php echo $grandTotal['leave']; ?></h3>
                                    <p>On Leave</p>
                                </a>
                            </div>
                        </div>

                        <div class="col-xl-2 col-md-4 col-sm-6 mb-4">
                            <div class="stat-card bg-gradient-success">
                                <a href="down_tracking.php?type=tour" style="color: inherit; text-decoration: none;">
                                    <h3><?php echo $grandTotal['tour']; ?></h3>
                                    <p>On Tour</p>
                                </a>
                            </div>
                        </div>
                    </div>


                    <div class="row mt-4">
                        <div class="col-12">
                            <h4 class="mb-3">Location-wise Breakdown</h4>
                        </div>

                        <?php foreach ($locationStats as $loc => $stats): ?>
                            <div class="col-md-6 col-xl-4">
                                <div class="location-card">
                                    <div class="location-header">
                                        <span>Location :</span>
                                        <span><?php echo $loc; ?></span>
                                    </div>
                                    <table class="location-table">
                                        <thead>
                                            <tr>
                                                <th>#Active</th>
                                                <th>#Present</th>
                                                <th>#Absent</th>
                                                <th>#Leave</th>
                                                <th>#Late</th>
                                                <th>#Tour</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td><a
                                                        href="down_tracking.php?type=total&location=<?php echo urlencode($loc); ?>"><?php echo $stats['active']; ?></a>
                                                </td>
                                                <td><a
                                                        href="down_tracking.php?type=present&location=<?php echo urlencode($loc); ?>"><?php echo $stats['present']; ?></a>
                                                </td>
                                                <td><a
                                                        href="down_tracking.php?type=absent&location=<?php echo urlencode($loc); ?>"><?php echo $stats['absent']; ?></a>
                                                </td>
                                                <td><a
                                                        href="down_tracking.php?type=leave&location=<?php echo urlencode($loc); ?>"><?php echo $stats['leave']; ?></a>
                                                </td>
                                                <td><a
                                                        href="down_tracking.php?type=late&location=<?php echo urlencode($loc); ?>"><?php echo $stats['late']; ?></a>
                                                </td>
                                                <td><a
                                                        href="down_tracking.php?type=tour&location=<?php echo urlencode($loc); ?>"><?php echo $stats['tour']; ?></a>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <?php if (empty($locationStats)): ?>
                            <div class="col-12">
                                <div class="alert alert-info">No active employees found.</div>
                            </div>
                        <?php endif; ?>

                    </div>


                    <!-- Charts Row -->
                    <div class="row mb-5">
                        <div class="col-lg-6 grid-margin stretch-card">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title">Attendance Overview</h4>
                                    <div style="height: 300px;">
                                        <canvas id="attendancePieChart"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6 grid-margin stretch-card">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title">Location-wise Status</h4>
                                    <div style="height: 300px;">
                                        <canvas id="locationBarChart"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>


                    <?php //include('../partials/_freeInternsWidget.php'); ?>

                </div>
                <!-- content-wrapper ends -->
                <?php include('../partials/_footer.php'); ?>
            </div>
            <!-- main-panel ends -->
        </div>
        <!-- page-body-wrapper ends -->
    </div>
    <!-- container-scroller -->

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0"></script>
    <script>
        document.addEventListener("DOMContentLoaded", function () {
            // Register ChartDataLabels plugin
            if (typeof ChartDataLabels !== 'undefined') {
                Chart.register(ChartDataLabels);
            }

            // Data from PHP
            const grandTotal = <?php echo json_encode($grandTotal); ?>;
            const locationStats = <?php echo json_encode($locationStats); ?>;

            // Pie Chart Data
            const pieData = {
                labels: ['Present', 'Absent', 'On Leave', 'Late', 'Tour'],
                datasets: [{
                    data: [grandTotal.present, grandTotal.absent, grandTotal.leave, grandTotal.late, grandTotal.tour],
                    backgroundColor: ['#11998e', '#cb2d3e', '#36D1DC', '#fce38a', '#f59842'],
                    hoverOffset: 4
                }]
            };

            // Bar Chart Data preparation
            const locations = Object.keys(locationStats);
            const presentData = locations.map(loc => locationStats[loc].present);
            const absentData = locations.map(loc => locationStats[loc].absent);
            const leaveData = locations.map(loc => locationStats[loc].leave);
            const lateData = locations.map(loc => locationStats[loc].late);
            const tourData = locations.map(loc => locationStats[loc].tour);

            const barData = {
                labels: locations,
                datasets: [{
                    label: 'Present',
                    data: presentData,
                    backgroundColor: '#11998e'
                },
                {
                    label: 'Absent',
                    data: absentData,
                    backgroundColor: '#cb2d3e'
                },
                {
                    label: 'On Leave',
                    data: leaveData,
                    backgroundColor: '#36D1DC'
                },
                {
                    label: 'Late',
                    data: lateData,
                    backgroundColor: '#fce38a'
                },
                {
                    label: 'Tour',
                    data: tourData,
                    backgroundColor: '#f59842'
                }
                ]
            };

            // Render Pie Chart
            if (document.getElementById('attendancePieChart')) {
                const pieChart = new Chart(document.getElementById('attendancePieChart'), {
                    type: 'doughnut',
                    data: pieData,
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom'
                            },
                            datalabels: {
                                color: '#ffffff',
                                font: {
                                    weight: 'bold',
                                    size: 14
                                },
                                formatter: (value) => (value > 0 ? value : '')
                            }
                        },
                        onClick: (e, activeEls) => {
                            if (activeEls.length > 0) {
                                const index = activeEls[0].index;
                                const label = pieData.labels[index];
                                let type = '';
                                switch (label) {
                                    case 'Present':
                                        type = 'present';
                                        break;
                                    case 'Absent':
                                        type = 'absent';
                                        break;
                                    case 'On Leave':
                                        type = 'leave';
                                        break;
                                    case 'Late':
                                        type = 'late';
                                        break;
                                    case 'Tour':
                                        type = 'tour';
                                        break;
                                }
                                if (type) window.location.href = `down_tracking.php?type=${type}`;
                            }
                        }
                    }
                });
            }

            // Render Bar Chart
            if (document.getElementById('locationBarChart')) {
                const barChart = new Chart(document.getElementById('locationBarChart'), {
                    type: 'bar',
                    data: barData,
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            x: {
                                stacked: true
                            },
                            y: {
                                stacked: true
                            }
                        },
                        plugins: {
                            legend: {
                                position: 'bottom'
                            },
                            datalabels: {
                                color: '#222222',
                                font: {
                                    weight: 'bold',
                                    size: 12
                                },
                                formatter: (value) => (value > 0 ? value : '')
                            }
                        },
                        onClick: (e, activeEls) => {
                            if (activeEls.length > 0) {
                                const index = activeEls[0].index; // Index of the bar (location)
                                const datasetIndex = activeEls[0].datasetIndex; // Index of the stack segment (status)

                                const location = barData.labels[index];
                                const statusLabel = barData.datasets[datasetIndex].label;

                                let type = '';
                                switch (statusLabel) {
                                    case 'Present':
                                        type = 'present';
                                        break;
                                    case 'Absent':
                                        type = 'absent';
                                        break;
                                    case 'On Leave':
                                        type = 'leave';
                                        break;
                                    case 'Late':
                                        type = 'late';
                                        break;
                                    case 'Tour':
                                        type = 'tour';
                                        break;
                                }

                                if (type && location) {
                                    window.location.href = `down_tracking.php?type=${type}&location=${encodeURIComponent(location)}`;
                                }
                            }
                        }
                    }
                });
            }
        });
    </script>

    <!-- plugins:js -->
    <script src="../vendors/js/vendor.bundle.base.js"></script>
    <script src="../js/off-canvas.js"></script>
    <script src="../js/hoverable-collapse.js"></script>
    <script src="../js/template.js"></script>

    <?php include('../include/passwordChangeAlert.php'); ?>
</body>

</html>