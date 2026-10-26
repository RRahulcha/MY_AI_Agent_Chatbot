<?php
session_start();
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}
include('../include/db_config.php');

$type = isset($_REQUEST['type']) ? $_REQUEST['type'] : '';
$date = date('Y-m-d');

// ── Intern type filter (matches dashboard) ──
$intType = $_SESSION['int_type'] ?? null;
$hasFilter = (!empty($intType) && $intType !== 'Reset');

// For queries with signup alias 's'
$typeCondition = '';
if ($hasFilter) {
    $typeCondition = " AND s.type = '" . mysqli_real_escape_string($con, $intType) . "'";
}
// For queries directly on signup (no alias)
$typeConditionPlain = '';
if ($hasFilter) {
    $typeConditionPlain = " AND type = '" . mysqli_real_escape_string($con, $intType) . "'";
}

// ── Location filter ──
$locationFilter = '';  // for queries with alias s
$locationFilterPlain = '';  // for queries without alias
if (!empty($_REQUEST['location'])) {
    $locParam = mysqli_real_escape_string($con, $_REQUEST['location']);
    if ($locParam === 'Online') {
        $locationFilter = " AND s.type = 'Online'";
        $locationFilterPlain = " AND type = 'Online'";
    } else {
        $locationFilter = " AND s.office = '$locParam' AND s.type = 'Offline'";
        $locationFilterPlain = " AND office = '$locParam' AND type = 'Offline'";
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <title>Employees Report</title>
    <?php include('../partials/_links.php'); ?>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        :root {
            --brand: #1977e1;
            --brand-dark: #1254b0;
            --green: #059669;
            --red: #dc2626;
            --amber: #d97706;
            --ink-2: #374151;
            --ink-3: #6b7280;
            --border: #e5e7eb;
            --surface: #f9fafb;
            --radius: 10px;
            --radius-lg: 14px;
            --shadow: 0 2px 12px rgba(25, 119, 225, 0.07), 0 1px 3px rgba(0, 0, 0, 0.04);
        }

        body {
            background: #f0f2f7;
        }

        .dash-card {
            background: #fff;
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            padding: 20px 22px;
        }

        .card-accent {
            height: 3px;
            border-radius: 99px;
            margin-bottom: 14px;
            background: linear-gradient(135deg, var(--brand), var(--brand-dark));
        }

        .page-title {
            font-size: 16px;
            font-weight: 600;
            color: var(--brand);
            margin: 0;
        }

        .btn-back {
            font-size: 12px;
            font-weight: 600;
            padding: 7px 18px;
            border-radius: 8px;
            border: 1px solid var(--brand);
            color: var(--brand);
            background: transparent;
            text-decoration: none;
            transition: background .15s, color .15s;
        }

        .btn-back:hover {
            background: var(--brand);
            color: #fff;
            text-decoration: none;
        }

        #dataTable thead th {
            background: linear-gradient(135deg, var(--brand), var(--brand-dark));
            color: #fff;
            font-size: 12px;
            font-weight: 600;
            border: none;
            padding: 10px 12px;
            white-space: nowrap;
        }

        #dataTable tbody td {
            font-size: 12px;
            padding: 9px 12px;
            vertical-align: middle;
            color: var(--ink-2);
            border-color: var(--border);
        }

        #dataTable tbody tr:hover {
            background: #eef4fd;
        }

        .badge-mode {
            display: inline-block;
            font-size: 10px;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 99px;
        }

        .badge-online {
            background: #eff6ff;
            color: var(--brand);
            border: 1px solid #bfdbfe;
        }

        .badge-offline {
            background: var(--surface);
            color: var(--ink-3);
            border: 1px solid var(--border);
        }

        .badge-inactive {
            background: #fef2f2;
            color: var(--red);
            border: 1px solid #fecaca;
        }

        .late-name {
            color: var(--red);
            font-weight: 600;
        }

        .filter-bar {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 10px 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 14px;
        }

        .filter-pill {
            font-size: 11px;
            font-weight: 600;
            padding: 4px 12px;
            border-radius: 99px;
            background: #eff6ff;
            color: var(--brand);
            border: 1px solid #bfdbfe;
        }

        .mh-pill {
            display: inline-block;
            font-size: 11px;
            padding: 2px 8px;
            border-radius: 99px;
            background: #ecfdf5;
            color: var(--green);
            border: 1px solid #a7f3d0;
            font-weight: 600;
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
                <div class="content-wrapper" style="background:#f0f2f7;">
                    <div class="dash-card">
                        <div class="card-accent"></div>

                        <!-- Header -->
                        <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap" style="gap:10px;">
                            <div class="d-flex align-items-center flex-wrap" style="gap:8px;">
                                <h4 class="page-title">
                                    <?php
                                    $titles = [
                                        'total' => 'Total Employees',
                                        'present' => 'Present Employees',
                                        'absent' => 'Absent Employees',
                                        'leave' => 'Employees on Leave',
                                        'late' => 'Late Employees',
                                        'tour' => 'Employees on Tour',
                                    ];
                                    echo $titles[$type] ?? 'Employees Report';
                                    ?>
                                </h4>
                                <?php if ($hasFilter): ?>
                                    <span class="filter-pill"><?= htmlspecialchars($intType) ?></span>
                                <?php endif; ?>
                            </div>
                            <a href="javascript:history.back()" class="btn-back">
                                <i class="bi bi-arrow-left"></i> Back
                            </a>
                        </div>

                        <div class="table-responsive">
                            <table id="dataTable" class="table table-borderless" style="width:100%">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Name</th>
                                        <?php if ($type === 'present' || $type === 'late'): ?>
                                            <th>Punch In</th>
                                            <th>Punch Out</th>
                                            <th>Man Hours</th>
                                        <?php endif; ?>
                                        <th>Designation</th>
                                        <th>Email</th>
                                        <th>Mobile</th>
                                        <th>Mode</th>
                                        <?php if ($type === 'total' && isset($_REQUEST['all'])): ?>
                                            <th>Status</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php

                                    // ════════════════════════════════
                                    // TOTAL
                                    // ════════════════════════════════
                                    if ($type === 'total') {
                                        $i = 1;
                                        $sql = "SELECT * FROM signup s WHERE s.designation != 'admin' AND s.designation != 'md' AND s.regstatus = 'Approved' $typeCondition $locationFilter";
                                        $q1 = mysqli_query($con, $sql) or die($con->error);
                                        while ($ret = mysqli_fetch_assoc($q1)) {
                                            $modeClass = strtolower($ret['type']) === 'online' ? 'badge-online' : 'badge-offline';
                                            ?>
                                            <tr>
                                                <td><?= $i++ ?></td>
                                                <td style="font-weight:500;"><?= htmlspecialchars($ret['fullName']) ?></td>
                                                <td><?= htmlspecialchars($ret['designation']) ?></td>
                                                <td><?= htmlspecialchars($ret['email']) ?></td>
                                                <td><?= htmlspecialchars($ret['mobileNo']) ?></td>
                                                <td><span
                                                        class="badge-mode <?= $modeClass ?>"><?= htmlspecialchars($ret['type']) ?></span>
                                                </td>
                                            </tr>
                                            <?php
                                        }

                                        if (isset($_REQUEST['all'])) {
                                            $colCheck = mysqli_query($con, "SHOW COLUMNS FROM delete_data LIKE 'regstatus'");
                                            $hasRegstatus = mysqli_num_rows($colCheck) > 0;
                                            $sql2 = "SELECT * FROM delete_data WHERE designation != 'admin'";
                                            if ($hasRegstatus)
                                                $sql2 .= " AND regstatus = 'Approved'";
                                            if ($hasFilter)
                                                $sql2 .= " AND type = '" . mysqli_real_escape_string($con, $intType) . "'";
                                            if (!empty($_REQUEST['location'])) {
                                                $locParam = mysqli_real_escape_string($con, $_REQUEST['location']);
                                                if ($locParam === 'Online') {
                                                    $sql2 .= " AND type = 'Online'";
                                                } else {
                                                    $sql2 .= " AND office = '$locParam' AND type = 'Offline'";
                                                }
                                            }
                                            $q2 = mysqli_query($con, $sql2) or die($con->error);
                                            while ($ret = mysqli_fetch_assoc($q2)) {
                                                ?>
                                                <tr>
                                                    <td><?= $i++ ?></td>
                                                    <td style="font-weight:500;"><?= htmlspecialchars($ret['fullName']) ?></td>
                                                    <td><?= htmlspecialchars($ret['designation']) ?></td>
                                                    <td><?= htmlspecialchars($ret['email']) ?></td>
                                                    <td><?= htmlspecialchars($ret['mobileNo']) ?></td>
                                                    <td><span
                                                            class="badge-mode badge-offline"><?= htmlspecialchars($ret['type'] ?? '-') ?></span>
                                                    </td>
                                                    <td><span class="badge-mode badge-inactive">Inactive</span></td>
                                                </tr>
                                                <?php
                                            }
                                        }

                                        // ════════════════════════════════
                                        // PRESENT — mirrors dashboard logic
                                        // ════════════════════════════════
                                    } elseif ($type === 'present') {
                                        $i = 1;
                                        // Get all distinct users who attended today and are approved
                                        $q2 = mysqli_query($con, "SELECT DISTINCT a.userId
                                    FROM attendance a
                                    JOIN signup s ON a.userId = s.userId
                                    WHERE a.date = '$date'
                                      AND s.designation != 'admin'
                                      AND s.regstatus = 'Approved'
                                      $typeCondition $locationFilter");
                                        while ($res = mysqli_fetch_assoc($q2)) {
                                            $uid = mysqli_real_escape_string($con, $res['userId']);
                                            // First punch-in record of the day
                                            $q21 = mysqli_query($con, "SELECT * FROM attendance WHERE date='$date' AND userId='$uid' ORDER BY id ASC LIMIT 1");
                                            $get = mysqli_fetch_assoc($q21);

                                            $q1 = mysqli_query($con, "SELECT * FROM signup s WHERE s.userId='$uid' AND s.regstatus='Approved' $typeCondition $locationFilter");
                                            while ($ret = mysqli_fetch_assoc($q1)) {
                                                $isLate = ($get['lateMark'] ?? '') === 'yes'
                                                    || (!empty($ret['expectedPunchIn']) && !empty($get['punchInTime']) && $get['punchInTime'] > $ret['expectedPunchIn']);
                                                $modeClass = strtolower($ret['type']) === 'online' ? 'badge-online' : 'badge-offline';
                                                $pIn = (!empty($get['punchInTime']) && $get['punchInTime'] !== '00:00:00') ? date('h:i A', strtotime($get['punchInTime'])) : '-';
                                                $pOut = (!empty($get['punchOutTime']) && $get['punchOutTime'] !== '00:00:00') ? date('h:i A', strtotime($get['punchOutTime'])) : '-';
                                                $mh = (!empty($get['manHours']) && $get['manHours'] !== '00:00:00') ? $get['manHours'] : '-';
                                                ?>
                                                <tr>
                                                    <td><?= $i++ ?></td>
                                                    <td class="<?= $isLate ? 'late-name' : '' ?>" style="font-weight:500;">
                                                        <?= htmlspecialchars($ret['fullName']) ?>
                                                        <?php if ($isLate): ?><small
                                                                style="font-size:10px;">(late)</small><?php endif; ?>
                                                    </td>
                                                    <td><?= $pIn ?></td>
                                                    <td><?= $pOut ?></td>
                                                    <td><?= $mh !== '-' ? '<span class="mh-pill">' . $mh . '</span>' : '-' ?></td>
                                                    <td><?= htmlspecialchars($ret['designation']) ?></td>
                                                    <td><?= htmlspecialchars($ret['email']) ?></td>
                                                    <td><?= htmlspecialchars($ret['mobileNo']) ?></td>
                                                    <td><span
                                                            class="badge-mode <?= $modeClass ?>"><?= htmlspecialchars($ret['type']) ?></span>
                                                    </td>
                                                </tr>
                                                <?php
                                            }
                                        }

                                        // ════════════════════════════════
                                        // ABSENT — mirrors dashboard logic exactly
                                        // ════════════════════════════════
                                    } elseif ($type === 'absent') {
                                        $i = 1;
                                        // All approved non-admin employees
                                        $sql = "SELECT s.userId, s.fullName, s.designation, s.email, s.mobileNo, s.type
                                        FROM signup s
                                        WHERE s.designation != 'admin'
                                          AND s.designation != 'md'
                                          AND s.regstatus = 'Approved'
                                          $typeCondition $locationFilter";
                                        $q1 = mysqli_query($con, $sql) or die($con->error);
                                        while ($ret = mysqli_fetch_assoc($q1)) {
                                            $uid = mysqli_real_escape_string($con, $ret['userId']);

                                            // Skip if present today
                                            $presQ = mysqli_query($con, "SELECT id FROM attendance WHERE date='$date' AND userId='$uid' LIMIT 1");
                                            if (mysqli_num_rows($presQ) > 0)
                                                continue;

                                            // Skip if on tour
                                            $tourQ = mysqli_query($con, "SELECT userId FROM tourAttendance WHERE date='$date' AND userId='$uid' LIMIT 1");
                                            if (mysqli_num_rows($tourQ) > 0)
                                                continue;

                                            // Skip if on non-Auto approved leave
                                            $leaveQ = mysqli_query($con, "SELECT leave_type FROM leaves WHERE userId='$uid' AND '$date' BETWEEN from_date AND to_date AND status='Approved' LIMIT 1");
                                            if (mysqli_num_rows($leaveQ) > 0) {
                                                $lr = mysqli_fetch_assoc($leaveQ);
                                                if ($lr['leave_type'] !== 'Auto')
                                                    continue;
                                            }

                                            $modeClass = strtolower($ret['type']) === 'online' ? 'badge-online' : 'badge-offline';
                                            ?>
                                            <tr>
                                                <td><?= $i++ ?></td>
                                                <td style="font-weight:500;"><?= htmlspecialchars($ret['fullName']) ?></td>
                                                <td><?= htmlspecialchars($ret['designation']) ?></td>
                                                <td><?= htmlspecialchars($ret['email']) ?></td>
                                                <td><?= htmlspecialchars($ret['mobileNo']) ?></td>
                                                <td><span
                                                        class="badge-mode <?= $modeClass ?>"><?= htmlspecialchars($ret['type']) ?></span>
                                                </td>
                                            </tr>
                                            <?php
                                        }

                                        // ════════════════════════════════
                                        // LEAVE
                                        // ════════════════════════════════
                                    } elseif ($type === 'leave') {
                                        $i = 1;
                                        $q2 = mysqli_query($con, "SELECT DISTINCT l.userId
                                    FROM leaves l
                                    JOIN signup s ON l.userId = s.userId
                                    WHERE l.leave_type != 'Auto'
                                      AND '$date' BETWEEN l.from_date AND l.to_date
                                      AND l.status = 'Approved'
                                      AND s.designation != 'admin'
                                      AND s.regstatus = 'Approved'
                                      $typeCondition $locationFilter");
                                        while ($res = mysqli_fetch_assoc($q2)) {
                                            $uid = mysqli_real_escape_string($con, $res['userId']);
                                            $q1 = mysqli_query($con, "SELECT * FROM signup s WHERE s.userId='$uid' $typeCondition $locationFilter");
                                            while ($ret = mysqli_fetch_assoc($q1)) {
                                                $modeClass = strtolower($ret['type']) === 'online' ? 'badge-online' : 'badge-offline';
                                                ?>
                                                <tr>
                                                    <td><?= $i++ ?></td>
                                                    <td style="font-weight:500;"><?= htmlspecialchars($ret['fullName']) ?></td>
                                                    <td><?= htmlspecialchars($ret['designation']) ?></td>
                                                    <td><?= htmlspecialchars($ret['email']) ?></td>
                                                    <td><?= htmlspecialchars($ret['mobileNo']) ?></td>
                                                    <td><span
                                                            class="badge-mode <?= $modeClass ?>"><?= htmlspecialchars($ret['type']) ?></span>
                                                    </td>
                                                </tr>
                                                <?php
                                            }
                                        }

                                        // ════════════════════════════════
                                        // LATE — mirrors dashboard logic exactly
                                        // ════════════════════════════════
                                    } elseif ($type === 'late') {
                                        $i = 1;
                                        // Get all users who punched in today
                                        $q2 = mysqli_query($con, "SELECT DISTINCT a.userId
                                    FROM attendance a
                                    JOIN signup s ON a.userId = s.userId
                                    WHERE a.date = '$date'
                                      AND s.designation != 'admin'
                                      AND s.regstatus = 'Approved'
                                      $typeCondition $locationFilter");
                                        while ($res = mysqli_fetch_assoc($q2)) {
                                            $uid = mysqli_real_escape_string($con, $res['userId']);
                                            $qAtt = mysqli_query($con, "SELECT * FROM attendance WHERE date='$date' AND userId='$uid' ORDER BY id ASC LIMIT 1");
                                            $get = mysqli_fetch_assoc($qAtt);

                                            $q1 = mysqli_query($con, "SELECT * FROM signup s WHERE s.userId='$uid' AND s.regstatus='Approved' $typeCondition $locationFilter");
                                            while ($ret = mysqli_fetch_assoc($q1)) {
                                                // ── Same late check as dashboard ──
                                                $expected = $ret['expectedPunchIn'] ?? '';
                                                $punchIn = $get['punchInTime'] ?? '';
                                                if (empty($expected) || empty($punchIn) || $punchIn <= $expected)
                                                    continue;

                                                $modeClass = strtolower($ret['type']) === 'online' ? 'badge-online' : 'badge-offline';
                                                $pIn = date('h:i A', strtotime($punchIn));
                                                $pOut = (!empty($get['punchOutTime']) && $get['punchOutTime'] !== '00:00:00') ? date('h:i A', strtotime($get['punchOutTime'])) : '-';
                                                $mh = (!empty($get['manHours']) && $get['manHours'] !== '00:00:00') ? $get['manHours'] : '-';
                                                ?>
                                                <tr>
                                                    <td><?= $i++ ?></td>
                                                    <td class="late-name" style="font-weight:500;">
                                                        <?= htmlspecialchars($ret['fullName']) ?>
                                                    </td>
                                                    <td><?= $pIn ?></td>
                                                    <td><?= $pOut ?></td>
                                                    <td><?= $mh !== '-' ? '<span class="mh-pill">' . $mh . '</span>' : '-' ?></td>
                                                    <td><?= htmlspecialchars($ret['designation']) ?></td>
                                                    <td><?= htmlspecialchars($ret['email']) ?></td>
                                                    <td><?= htmlspecialchars($ret['mobileNo']) ?></td>
                                                    <td><span
                                                            class="badge-mode <?= $modeClass ?>"><?= htmlspecialchars($ret['type']) ?></span>
                                                    </td>
                                                </tr>
                                                <?php
                                            }
                                        }

                                        // ════════════════════════════════
                                        // TOUR
                                        // ════════════════════════════════
                                    } elseif ($type === 'tour') {
                                        $i = 1;
                                        $q2 = mysqli_query($con, "SELECT DISTINCT ta.userId
                                    FROM tourAttendance ta
                                    JOIN signup s ON ta.userId = s.userId
                                    WHERE ta.date = '$date'
                                      AND s.designation != 'admin'
                                      AND s.regstatus = 'Approved'
                                      $typeCondition $locationFilter");
                                        while ($res = mysqli_fetch_assoc($q2)) {
                                            $uid = mysqli_real_escape_string($con, $res['userId']);
                                            $q1 = mysqli_query($con, "SELECT * FROM signup s WHERE s.userId='$uid' AND s.regstatus='Approved' $typeCondition $locationFilter");
                                            while ($ret = mysqli_fetch_assoc($q1)) {
                                                $modeClass = strtolower($ret['type']) === 'online' ? 'badge-online' : 'badge-offline';
                                                ?>
                                                <tr>
                                                    <td><?= $i++ ?></td>
                                                    <td style="font-weight:500;"><?= htmlspecialchars($ret['fullName']) ?></td>
                                                    <td><?= htmlspecialchars($ret['designation']) ?></td>
                                                    <td><?= htmlspecialchars($ret['email']) ?></td>
                                                    <td><?= htmlspecialchars($ret['mobileNo']) ?></td>
                                                    <td><span
                                                            class="badge-mode <?= $modeClass ?>"><?= htmlspecialchars($ret['type']) ?></span>
                                                    </td>
                                                </tr>
                                                <?php
                                            }
                                        }
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

    <!-- JS -->
    <script src="../vendors/js/vendor.bundle.base.js"></script>
    <script src="../vendors/chart.js/Chart.min.js"></script>
    <script src="../vendors/datatables.net/jquery.dataTables.js"></script>
    <script src="../vendors/datatables.net-bs4/dataTables.bootstrap4.js"></script>
    <script src="../js/dataTables.select.min.js"></script>
    <script src="../js/off-canvas.js"></script>
    <script src="../js/hoverable-collapse.js"></script>
    <script src="../js/template.js"></script>
    <script src="../js/settings.js"></script>
    <script src="../js/dashboard.js?v=1.0.2"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        $(document).ready(function () {
            $('#dataTable').DataTable({
                paging: true,
                searching: true,
                ordering: true,
                info: true,
                lengthMenu: [[10, 25, 50, -1], [10, 25, 50, 'All']],
                language: { search: 'Search: ' }
            });
        });
    </script>

    <?php
    require_once('../include/sweetAlert.php');
    if (isset($_SESSION['status'])) {
        $status = $_SESSION['status'];
        $msg = $_SESSION['msg'];
        if ($status != true) {
            toastMsg('error', $msg);
        } else {
            toastMsg('success', $msg);
        }
        unset($_SESSION['status'], $_SESSION['msg']);
    }
    ?>
</body>

</html>