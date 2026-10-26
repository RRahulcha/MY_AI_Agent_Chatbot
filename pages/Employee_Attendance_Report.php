<?php
session_start();
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}
include('../include/db_config.php');
date_default_timezone_set('Asia/Kolkata');

if (!function_exists('count_sundays_in_range')) {
    function count_sundays_in_range($start, $end)
    {
        $total = 0;
        $current = strtotime($start);
        $endTime = strtotime($end);
        while ($current <= $endTime) {
            if (date("w", $current) == 0)
                $total++;
            $current = strtotime('+1 day', $current);
        }
        return $total;
    }
}

if (!function_exists('count_saturdays_in_range')) {
    function count_saturdays_in_range($start, $end)
    {
        $total = 0;
        $current = strtotime($start);
        $endTime = strtotime($end);
        while ($current <= $endTime) {
            if (date("w", $current) == 6)
                $total++;
            $current = strtotime('+1 day', $current);
        }
        return $total;
    }
}

if (!function_exists('renderCountCell')) {
    function renderCountCell($count, $category, $userId, $fromDate, $toDate, $fullName, $badgeClass) {
        if ($count <= 0) {
            return "<span class='att-badge-num $badgeClass'>0</span>";
        }
        $uIdEsc = htmlspecialchars((string)$userId, ENT_QUOTES);
        $fEsc   = htmlspecialchars((string)$fromDate, ENT_QUOTES);
        $tEsc   = htmlspecialchars((string)$toDate, ENT_QUOTES);
        $nEsc   = htmlspecialchars((string)$fullName, ENT_QUOTES);
        return "<button type='button' class='count-link-btn' onclick=\"openBreakdownModal('$uIdEsc', '$category', '$fEsc', '$tEsc', '$nEsc')\" title='Click to view breakdown'><span class='att-badge-num $badgeClass'>$count</span></button>";
    }
}

// ── AJAX: fetch breakdown details for popup ──────────────────────────────────
if (isset($_GET['ajax_breakdown'])) {
    while (ob_get_level()) { @ob_end_clean(); }
    ob_start();

    try {
        $empId     = isset($_GET['employee_id']) ? mysqli_real_escape_string($con, trim($_GET['employee_id'])) : '';
        $fromDate  = !empty($_GET['from_date']) ? mysqli_real_escape_string($con, trim($_GET['from_date'])) : date('Y-m-01');
        $toDate    = !empty($_GET['to_date']) ? mysqli_real_escape_string($con, trim($_GET['to_date'])) : date('Y-m-d');
        $category  = !empty($_GET['category']) ? mysqli_real_escape_string($con, trim($_GET['category'])) : '';

        if ($empId === '' || $category === '') {
            throw new Exception('Missing required employee_id or category parameter.');
        }

        $empQ   = mysqli_query($con, "SELECT userId, fullName, designation FROM signup WHERE userId='$empId' LIMIT 1");
        if (!$empQ) {
            $empQ = mysqli_query($con, "SELECT userId, fullName FROM signup WHERE userId='$empId' LIMIT 1");
        }
        $empRow = ($empQ && mysqli_num_rows($empQ) > 0) ? mysqli_fetch_assoc($empQ) : null;
        $empName = $empRow ? $empRow['fullName'] : 'Employee';
        $empDesignation = $empRow ? ($empRow['designation'] ?? '') : '';
        $empOffice = $empRow ? ($empRow['office'] ?? '') : '';
        $isKinwatOrSenior = ($empDesignation == 'seniorIntern' || $empOffice == 'Kinwat');

        $items = [];
        $title = '';
        $reportUrl = '';

        switch ($category) {
            case 'present':
                $title = 'Present Days Details';
                $reportUrl = "attendance_report.php?employee_id=" . urlencode($empId) . "&from_date=" . urlencode($fromDate) . "&to_date=" . urlencode($toDate);

                $tourDates = [];
                $tQ = mysqli_query($con, "SELECT `date` FROM tourAttendance WHERE userId='$empId' AND `date` BETWEEN '$fromDate' AND '$toDate'");
                if ($tQ) {
                    while ($t = mysqli_fetch_assoc($tQ)) {
                        if (!empty($t['date'])) $tourDates[$t['date']] = true;
                    }
                }

                $attQ = mysqli_query($con, "SELECT `date`, punchInTime, punchOutTime, `status` FROM attendance WHERE userId='$empId' AND `date` BETWEEN '$fromDate' AND '$toDate' ORDER BY `date` ASC");
                if ($attQ) {
                    while ($a = mysqli_fetch_assoc($attQ)) {
                        if (isset($tourDates[$a['date']])) continue;
                        if (!empty($a['punchInTime']) || in_array(strtolower($a['status'] ?? ''), ['present', 'online', 'present (online)'])) {
                            $pIn  = (!empty($a['punchInTime']) && $a['punchInTime'] !== '00:00:00') ? date('h:i A', strtotime($a['punchInTime'])) : '—';
                            $pOut = (!empty($a['punchOutTime']) && $a['punchOutTime'] !== '00:00:00') ? date('h:i A', strtotime($a['punchOutTime'])) : '—';
                            $modeStr = !empty($a['status']) ? $a['status'] : 'Present';
                            $items[] = [
                                'date'    => date('d M Y', strtotime($a['date'])),
                                'day'     => date('D', strtotime($a['date'])),
                                'details' => "Punch In: $pIn | Punch Out: $pOut",
                                'meta'    => "Mode: " . htmlspecialchars($modeStr),
                                'status'  => 'Present',
                                'badge'   => 'badge-p'
                            ];
                        }
                    }
                }
                break;

            case 'tour':
                $title = 'Tour Attendance Details';
                $reportUrl = "tourAttendance_report.php?employee_id=" . urlencode($empId) . "&from_date=" . urlencode($fromDate) . "&to_date=" . urlencode($toDate);

                $tQ = mysqli_query($con, "SELECT `date`, punchInTime, punchOutTime, manHours, `status` FROM tourAttendance WHERE userId='$empId' AND `date` BETWEEN '$fromDate' AND '$toDate' ORDER BY `date` ASC");
                if (!$tQ) {
                    $tQ = mysqli_query($con, "SELECT `date`, punchInTime, punchOutTime, `status` FROM tourAttendance WHERE userId='$empId' AND `date` BETWEEN '$fromDate' AND '$toDate' ORDER BY `date` ASC");
                }
                if ($tQ) {
                    while ($t = mysqli_fetch_assoc($tQ)) {
                        if (!empty($t['punchInTime']) || strtolower($t['status'] ?? '') === 'present') {
                            $pIn  = (!empty($t['punchInTime']) && $t['punchInTime'] !== '00:00:00') ? date('h:i A', strtotime($t['punchInTime'])) : '—';
                            $pOut = (!empty($t['punchOutTime']) && $t['punchOutTime'] !== '00:00:00') ? date('h:i A', strtotime($t['punchOutTime'])) : '—';
                            $mh   = !empty($t['manHours']) ? " (Hours: {$t['manHours']})" : '';
                            $items[] = [
                                'date'    => date('d M Y', strtotime($t['date'])),
                                'day'     => date('D', strtotime($t['date'])),
                                'details' => "Punch In: $pIn | Punch Out: $pOut$mh",
                                'meta'    => "Tour Duty Recorded",
                                'status'  => 'On Tour',
                                'badge'   => 'badge-tr'
                            ];
                        }
                    }
                }
                break;

            case 'leaves':
                $title = 'Approved Leave Details';
                $reportUrl = "leaveReport.php?employee_id=" . urlencode($empId) . "&from_date=" . urlencode($fromDate) . "&to_date=" . urlencode($toDate);

                $lvQ = mysqli_query($con, "
                    SELECT leave_name, leave_type, leave_day, from_date, to_date, status
                    FROM leaves
                    WHERE userId = '$empId'
                      AND status = 'Approved'
                      AND leave_name != 'Rostering Off'
                      AND (
                          (from_date BETWEEN '$fromDate' AND '$toDate')
                          OR (to_date BETWEEN '$fromDate' AND '$toDate')
                          OR ('$fromDate' BETWEEN from_date AND to_date)
                      )
                    ORDER BY from_date ASC
                ");
                if (!$lvQ) {
                    $lvQ = mysqli_query($con, "
                        SELECT leave_name, leave_type, from_date, to_date, status
                        FROM leaves
                        WHERE userId = '$empId'
                          AND status = 'Approved'
                          AND leave_name != 'Rostering Off'
                          AND (
                              (from_date BETWEEN '$fromDate' AND '$toDate')
                              OR (to_date BETWEEN '$fromDate' AND '$toDate')
                              OR ('$fromDate' BETWEEN from_date AND to_date)
                          )
                        ORDER BY from_date ASC
                    ");
                }
                if ($lvQ) {
                    while ($lv = mysqli_fetch_assoc($lvQ)) {
                        $from = max($lv['from_date'], $fromDate);
                        $to   = min($lv['to_date'], $toDate);
                        $days = (strtotime($to) - strtotime($from)) / 86400 + 1;
                        if ($days < 1) continue;

                        $items[] = [
                            'date'    => date('d M Y', strtotime($from)) . ($from !== $to ? ' → ' . date('d M Y', strtotime($to)) : ''),
                            'day'     => date('D', strtotime($from)),
                            'details' => htmlspecialchars($lv['leave_name']) . " (" . htmlspecialchars($lv['leave_type']) . ")",
                            'meta'    => ((int)$days) . " day(s)" . (!empty($lv['leave_day']) ? " · {$lv['leave_day']}" : ''),
                            'status'  => 'Approved Leave',
                            'badge'   => 'badge-lv'
                        ];
                    }
                }
                break;

            case 'late':
                $title = 'Late Marks Breakdown';
                $reportUrl = "late_punch_early_go.php?employee_id=" . urlencode($empId) . "&from_date=" . urlencode($fromDate) . "&to_date=" . urlencode($toDate);

                $attQ = mysqli_query($con, "SELECT `date`, punchInTime, punchOutTime FROM attendance WHERE userId='$empId' AND `date` BETWEEN '$fromDate' AND '$toDate' AND LOWER(lateMark)='yes' ORDER BY `date` ASC");
                if ($attQ) {
                    while ($a = mysqli_fetch_assoc($attQ)) {
                        $pIn = !empty($a['punchInTime']) ? date('h:i A', strtotime($a['punchInTime'])) : '—';
                        $items[] = [
                            'date'    => date('d M Y', strtotime($a['date'])),
                            'day'     => date('D', strtotime($a['date'])),
                            'details' => "Punched In at $pIn",
                            'meta'    => "Late Mark Recorded",
                            'status'  => 'Late',
                            'badge'   => 'badge-lt'
                        ];
                    }
                }
                break;

            case 'absent':
                $title = 'Unexcused Absent Dates';
                $reportUrl = "down_tracking.php?type=absent&employee_id=" . urlencode($empId);

                $punchedInDates = [];
                $attQ = mysqli_query($con, "SELECT `date` FROM attendance WHERE userId='$empId' AND `date` BETWEEN '$fromDate' AND '$toDate' AND (punchInTime IS NOT NULL AND punchInTime != '')");
                if ($attQ) { while ($a = mysqli_fetch_assoc($attQ)) $punchedInDates[] = $a['date']; }
                $tourQ = mysqli_query($con, "SELECT `date` FROM tourAttendance WHERE userId='$empId' AND `date` BETWEEN '$fromDate' AND '$toDate' AND (punchInTime IS NOT NULL AND punchInTime != '')");
                if ($tourQ) { while ($t = mysqli_fetch_assoc($tourQ)) $punchedInDates[] = $t['date']; }
                $punchedInDates = array_unique($punchedInDates);

                $rosterOffDateSet = [];
                $roQ = mysqli_query($con, "SELECT from_date,to_date FROM leaves WHERE userId='$empId' AND leave_name='Rostering Off' AND status='Approved' AND ((from_date BETWEEN '$fromDate' AND '$toDate') OR (to_date BETWEEN '$fromDate' AND '$toDate') OR ('$fromDate' BETWEEN from_date AND to_date))");
                if ($roQ) {
                    while ($r = mysqli_fetch_assoc($roQ)) {
                        $cur = strtotime(max($r['from_date'], $fromDate));
                        $end = strtotime(min($r['to_date'], $toDate));
                        while ($cur <= $end) { $rosterOffDateSet[] = date('Y-m-d', $cur); $cur = strtotime('+1 day', $cur); }
                    }
                }
                $rosterOffDateSet = array_values(array_diff(array_unique($rosterOffDateSet), $punchedInDates));

                $leaveDateSet = [];
                $lvQ = mysqli_query($con, "SELECT from_date,to_date FROM leaves WHERE userId='$empId' AND status='Approved' AND leave_name!='Rostering Off' AND ((from_date BETWEEN '$fromDate' AND '$toDate') OR (to_date BETWEEN '$fromDate' AND '$toDate') OR ('$fromDate' BETWEEN from_date AND to_date))");
                if ($lvQ) {
                    while ($lv = mysqli_fetch_assoc($lvQ)) {
                        $cur = strtotime(max($lv['from_date'], $fromDate));
                        $end = strtotime(min($lv['to_date'], $toDate));
                        while ($cur <= $end) {
                            $dow = (int)date('w', $cur);
                            $isOff = $isKinwatOrSenior ? ($dow == 0) : ($dow == 0 || $dow == 6);
                            if (!$isOff) $leaveDateSet[] = date('Y-m-d', $cur);
                            $cur = strtotime('+1 day', $cur);
                        }
                    }
                }
                $leaveDateSet = array_values(array_diff(array_unique($leaveDateSet), $punchedInDates));

                $holidayDates = [];
                $holQ = mysqli_query($con, "SELECT from_date,to_date,title,description FROM holidays WHERE (from_date BETWEEN '$fromDate' AND '$toDate') OR (to_date BETWEEN '$fromDate' AND '$toDate') OR ('$fromDate' BETWEEN from_date AND to_date)");
                if ($holQ) {
                    while ($hol = mysqli_fetch_assoc($holQ)) {
                        $td = strtolower(($hol['title'] ?? '') . ' ' . ($hol['description'] ?? ''));
                        $ol = strtolower($empOffice);
                        if ((strpos($td, 'kinwat') !== false && $ol != 'kinwat') || (strpos($td, 'pune') !== false && $ol != 'pune')) continue;
                        $cur = strtotime(max($hol['from_date'], $fromDate));
                        $end = strtotime(min($hol['to_date'], $toDate));
                        while ($cur <= $end) {
                            $dow = (int)date('w', $cur);
                            $isWE = $isKinwatOrSenior ? ($dow == 0) : ($dow == 0 || $dow == 6);
                            if (!$isWE) $holidayDates[] = date('Y-m-d', $cur);
                            $cur = strtotime('+1 day', $cur);
                        }
                    }
                }
                $holidayDates = array_unique($holidayDates);

                $cur = strtotime($fromDate);
                $endTs = strtotime($toDate);
                while ($cur <= $endTs) {
                    $dStr = date('Y-m-d', $cur);
                    $dow  = (int)date('w', $cur);
                    $isOff = $isKinwatOrSenior ? ($dow == 0) : ($dow == 0 || $dow == 6);

                    if (!$isOff && !in_array($dStr, $punchedInDates) && !in_array($dStr, $leaveDateSet) && !in_array($dStr, $rosterOffDateSet) && !in_array($dStr, $holidayDates)) {
                        $items[] = [
                            'date'    => date('d M Y', $cur),
                            'day'     => date('D', $cur),
                            'details' => 'No punch-in recorded & no approved leave',
                            'meta'    => 'Unexcused Absence',
                            'status'  => 'Absent',
                            'badge'   => 'badge-a'
                        ];
                    }
                    $cur = strtotime('+1 day', $cur);
                }
                break;

            case 'weekly_off':
                $title = 'Weekly Off Dates';
                $reportUrl = "myAttendance.php?userId=" . urlencode($empId);

                $punchedInDates = [];
                $attQ = mysqli_query($con, "SELECT `date` FROM attendance WHERE userId='$empId' AND `date` BETWEEN '$fromDate' AND '$toDate' AND (punchInTime IS NOT NULL AND punchInTime != '')");
                if ($attQ) { while ($a = mysqli_fetch_assoc($attQ)) $punchedInDates[] = $a['date']; }
                $tourQ = mysqli_query($con, "SELECT `date` FROM tourAttendance WHERE userId='$empId' AND `date` BETWEEN '$fromDate' AND '$toDate' AND (punchInTime IS NOT NULL AND punchInTime != '')");
                if ($tourQ) { while ($t = mysqli_fetch_assoc($tourQ)) $punchedInDates[] = $t['date']; }

                $cur = strtotime($fromDate);
                $endTs = strtotime($toDate);
                while ($cur <= $endTs) {
                    $dow = (int)date('w', $cur);
                    $dStr = date('Y-m-d', $cur);
                    $isOff = $isKinwatOrSenior ? ($dow == 0) : ($dow == 0 || $dow == 6);
                    if ($isOff && !in_array($dStr, $punchedInDates)) {
                        $items[] = [
                            'date'    => date('d M Y', $cur),
                            'day'     => date('D', $cur),
                            'details' => 'Scheduled Weekly Off',
                            'meta'    => date('l', $cur),
                            'status'  => 'Weekly Off',
                            'badge'   => 'badge-wo'
                        ];
                    }
                    $cur = strtotime('+1 day', $cur);
                }
                break;

            case 'rostering_off':
                $title = 'Rostering Off Dates';
                $reportUrl = "leave_approval.php";

                $roQ = mysqli_query($con, "SELECT from_date, to_date, leave_type FROM leaves WHERE userId='$empId' AND leave_name='Rostering Off' AND status='Approved' AND ((from_date BETWEEN '$fromDate' AND '$toDate') OR (to_date BETWEEN '$fromDate' AND '$toDate') OR ('$fromDate' BETWEEN from_date AND to_date))");
                if ($roQ) {
                    while ($r = mysqli_fetch_assoc($roQ)) {
                        $items[] = [
                            'date'    => date('d M Y', strtotime($r['from_date'])) . ($r['from_date'] !== $r['to_date'] ? ' → ' . date('d M Y', strtotime($r['to_date'])) : ''),
                            'day'     => date('D', strtotime($r['from_date'])),
                            'details' => 'Approved Rostering Off',
                            'meta'    => htmlspecialchars($r['leave_type'] ?? 'Off'),
                            'status'  => 'Rostering Off',
                            'badge'   => 'badge-ro'
                        ];
                    }
                }
                break;

            case 'holidays':
                $title = 'Applicable Official Holidays';
                $reportUrl = "view_holidays.php";

                $holQ = mysqli_query($con, "SELECT from_date, to_date, title, description FROM holidays WHERE (from_date BETWEEN '$fromDate' AND '$toDate') OR (to_date BETWEEN '$fromDate' AND '$toDate') OR ('$fromDate' BETWEEN from_date AND to_date)");
                if ($holQ) {
                    while ($hol = mysqli_fetch_assoc($holQ)) {
                        $td = strtolower(($hol['title'] ?? '') . ' ' . ($hol['description'] ?? ''));
                        $ol = strtolower($empOffice);
                        if ((strpos($td, 'kinwat') !== false && $ol != 'kinwat') || (strpos($td, 'pune') !== false && $ol != 'pune')) continue;

                        $items[] = [
                            'date'    => date('d M Y', strtotime($hol['from_date'])) . ($hol['from_date'] !== $hol['to_date'] ? ' → ' . date('d M Y', strtotime($hol['from_date'])) : ''),
                            'day'     => date('D', strtotime($hol['from_date'])),
                            'details' => htmlspecialchars($hol['title'] ?? 'Holiday'),
                            'meta'    => htmlspecialchars(($hol['description'] ?? '') ?: 'Official Holiday'),
                            'status'  => 'Holiday',
                            'badge'   => 'badge-wo'
                        ];
                    }
                }
                break;
        }

        $resPayload = [
            'success'        => true,
            'title'          => $title,
            'employee_name'  => $empName,
            'category'       => $category,
            'report_url'     => $reportUrl,
            'items'          => $items
        ];
    } catch (Throwable $e) {
        $resPayload = [
            'success' => false,
            'error'   => $e->getMessage()
        ];
    }

    $jsonOutput = json_encode($resPayload);

    while (ob_get_level()) { @ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    echo $jsonOutput;
    exit;
}

// ── AJAX: fetch leave details for popup (legacy compatibility) ──────────────────────
if (isset($_GET['ajax_leaves']) && !empty($_GET['employee_id']) && !empty($_GET['from_date']) && !empty($_GET['to_date'])) {
    $empId = mysqli_real_escape_string($con, $_GET['employee_id']);
    $fromDate = mysqli_real_escape_string($con, $_GET['from_date']);
    $toDate = mysqli_real_escape_string($con, $_GET['to_date']);

    $lvQ = mysqli_query($con, "
        SELECT leave_name, leave_type, leave_day, from_date, to_date, status
        FROM leaves
        WHERE userId = '$empId'
          AND status = 'Approved'
          AND leave_name != 'Rostering Off'
          AND (
              (from_date BETWEEN '$fromDate' AND '$toDate')
              OR (to_date   BETWEEN '$fromDate' AND '$toDate')
              OR ('$fromDate' BETWEEN from_date AND to_date)
          )
        ORDER BY from_date ASC
    ");

    $rows = [];
    if ($lvQ) {
        while ($lv = mysqli_fetch_assoc($lvQ)) {
            $from = max($lv['from_date'], $fromDate);
            $to = min($lv['to_date'], $toDate);
            $days = (strtotime($to) - strtotime($from)) / 86400 + 1;
            if ($days < 1)
                continue;

            $rows[] = [
                'leave_name' => $lv['leave_name'],
                'leave_type' => $lv['leave_type'],
                'from' => date('d M Y', strtotime($from)),
                'to' => date('d M Y', strtotime($to)),
                'days' => (int) $days,
                'leave_day' => $lv['leave_day'],
            ];
        }
    }

    header('Content-Type: application/json');
    echo json_encode($rows);
    exit;
}

// ── EXCEL EXPORT ─────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] == 'excel' && isset($_GET['employee_id'])) {
    $selectedEmployeeId = mysqli_real_escape_string($con, $_GET['employee_id']);
    $fromDate = isset($_GET['from_date']) ? mysqli_real_escape_string($con, $_GET['from_date']) : date('Y-m-01');
    $toDate = isset($_GET['to_date']) ? mysqli_real_escape_string($con, $_GET['to_date']) : date('Y-m-d');

    $empFilterSql = ($selectedEmployeeId === 'all' || empty($selectedEmployeeId)) ? "" : "AND userId = '$selectedEmployeeId'";
    $exportEmpQ = mysqli_query($con, "SELECT userId, fullName, designation FROM signup WHERE status = 'active' AND designation NOT IN ('admin', 'developer', 'md') $empFilterSql ORDER BY fullName");
    if (!$exportEmpQ) {
        $exportEmpQ = mysqli_query($con, "SELECT userId, fullName FROM signup WHERE status = 'active' $empFilterSql ORDER BY fullName");
    }

    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="employee_attendance_summary_' . $fromDate . '_to_' . $toDate . '.xls"');

    echo '<html><head><meta charset="UTF-8"><title>Employee Attendance Summary Report</title>
    <style>
        body{font-family:Arial,sans-serif;}
        table{border-collapse:collapse;width:100%;margin-bottom:20px;}
        th{background-color:#103d9e;color:white;padding:8px;text-align:center;border:1px solid #ddd;font-weight:bold;}
        td{padding:6px;border:1px solid #ddd;text-align:center;}
    </style></head><body>';
    echo '<div style="text-align:center;margin-bottom:20px;"><div style="font-size:18px;font-weight:bold;">Employee Attendance Summary Report</div>';
    echo '<div style="font-size:14px;color:#666;">Generated on: ' . date('Y-m-d H:i:s') . '</div></div>';
    echo '<div style="margin-bottom:15px;"><strong>Period:</strong> ' . date('d M Y', strtotime($fromDate)) . ' to ' . date('d M Y', strtotime($toDate)) . '</div>';
    echo '<table border="1"><thead><tr>
        <th>Sr.No</th><th>Employee Name</th><th>Designation</th><th>Office</th><th>Period</th><th>Total Days</th><th>Weekly Off</th>
        <th>Rostering Off</th><th>Present Days</th><th>Leave Days</th>
        <th>Absent Days</th><th>Holidays</th><th>Tour Days</th><th>Late Marks</th>
    </tr></thead><tbody>';

    $srExp = 1;
    while ($empInfo = mysqli_fetch_assoc($exportEmpQ)) {
        $eId = $empInfo['userId'];
        $employeeName = $empInfo['fullName'];
        $empDesignation = $empInfo['designation'];
        $empOffice = $empInfo['office'];

        $totalDays = (strtotime($toDate) - strtotime($fromDate)) / 86400 + 1;
        $isKinwatOrSenior = ($empDesignation == 'seniorIntern' || $empOffice == 'Kinwat');

        // ── STEP 1: Attendance (Present) ─────────────────────────────────────
        $presentDates = [];
        $halfDayDates = [];
        $late = 0;
        $attQ = mysqli_query($con, "SELECT `date`,punchInTime,lateMark,`status` FROM attendance WHERE userId='$eId' AND `date` BETWEEN '$fromDate' AND '$toDate'");
        while ($a = mysqli_fetch_assoc($attQ)) {
            $statusLower = strtolower($a['status']);
            if (strpos($statusLower, 'half day') !== false) {
                $halfDayDates[] = $a['date'];
            } elseif (!empty($a['punchInTime']) || in_array($statusLower, ['present', 'online', 'present (online)'])) {
                $presentDates[] = $a['date'];
            }
            if (isset($a['lateMark']) && strtolower($a['lateMark']) === 'yes')
                $late++;
        }
        $presentDates = array_unique($presentDates);
        $halfDayDates = array_unique($halfDayDates);

        // ── STEP 2: Tour ──────────────────────────────────────────────────────
        $tourDates = [];
        $tourQ = mysqli_query($con, "SELECT `date`,punchInTime,`status` FROM tourAttendance WHERE userId='$eId' AND `date` BETWEEN '$fromDate' AND '$toDate'");
        while ($t = mysqli_fetch_assoc($tourQ)) {
            if (!empty($t['punchInTime']) || strtolower($t['status']) === 'present')
                $tourDates[] = $t['date'];
        }
        $tourDates = array_unique($tourDates);

        // Remove tour dates from present and half days to avoid double-count
        $presentDates = array_values(array_diff($presentDates, $tourDates));
        $halfDayDates = array_values(array_diff($halfDayDates, $tourDates));
        $present = count($presentDates) + (count($halfDayDates) * 0.5);
        $tour    = count($tourDates);

        // All days the employee was physically present (present + half day + tour)
        $punchedInDates = array_unique(array_merge($presentDates, $halfDayDates, $tourDates));

        // ── STEP 3: Rostering Off date set ────────────────────────────────────
        $rosterOffDateSet = [];
        $roQ = mysqli_query($con, "SELECT from_date,to_date FROM leaves WHERE userId='$eId' AND leave_name='Rostering Off' AND status='Approved' AND ((from_date BETWEEN '$fromDate' AND '$toDate') OR (to_date BETWEEN '$fromDate' AND '$toDate') OR ('$fromDate' BETWEEN from_date AND to_date))");
        while ($r = mysqli_fetch_assoc($roQ)) {
            $cur = strtotime(max($r['from_date'], $fromDate));
            $end = strtotime(min($r['to_date'], $toDate));
            while ($cur <= $end) {
                $rosterOffDateSet[] = date('Y-m-d', $cur);
                $cur = strtotime('+1 day', $cur);
            }
        }
        $rosterOffDateSet = array_unique($rosterOffDateSet);
        // Punch-in wins: if employee showed up, don't count as rostering off
        $rosterOffDateSet = array_values(array_diff($rosterOffDateSet, $punchedInDates));
        $rosteringOff     = count($rosterOffDateSet);

        // ── STEP 4: Leave date set (skip weekly-off days) ────────────────────
        $leaveDateSet = [];
        $lvQ = mysqli_query($con, "SELECT from_date,to_date FROM leaves WHERE userId='$eId' AND status='Approved' AND leave_name!='Rostering Off' AND ((from_date BETWEEN '$fromDate' AND '$toDate') OR (to_date BETWEEN '$fromDate' AND '$toDate') OR ('$fromDate' BETWEEN from_date AND to_date))");
        while ($lv = mysqli_fetch_assoc($lvQ)) {
            $cur = strtotime(max($lv['from_date'], $fromDate));
            $end = strtotime(min($lv['to_date'], $toDate));
            while ($cur <= $end) {
                $dow   = (int)date('w', $cur);
                $isOff = $isKinwatOrSenior ? ($dow == 0) : ($dow == 0 || $dow == 6);
                if (!$isOff) $leaveDateSet[] = date('Y-m-d', $cur);
                $cur = strtotime('+1 day', $cur);
            }
        }
        $leaveDateSet = array_unique($leaveDateSet);
        // Punch-in wins: if employee showed up, don't count as leave
        $leaveDateSet = array_values(array_diff($leaveDateSet, $punchedInDates));
        $leaveDays    = count($leaveDateSet);

        // ── STEP 5: Smart Weekly Off ──────────────────────────────────────────
        // Count a day as Weekly Off only if: it IS a weekly-off day AND the employee
        // did NOT punch in, did NOT have a leave, did NOT have a rostering-off.
        $weeklyOff = 0;
        $cur = strtotime($fromDate);
        $endTs = strtotime($toDate);
        while ($cur <= $endTs) {
            $dow     = (int)date('w', $cur);
            $dateStr = date('Y-m-d', $cur);
            $isWeeklyOffDay = $isKinwatOrSenior ? ($dow == 0) : ($dow == 0 || $dow == 6);
            if ($isWeeklyOffDay) {
                $punchedIn   = in_array($dateStr, $punchedInDates);
                $onLeave     = in_array($dateStr, $leaveDateSet);
                $onRosterOff = in_array($dateStr, $rosterOffDateSet);
                if (!$punchedIn && !$onLeave && !$onRosterOff) {
                    $weeklyOff++;
                }
            }
            $cur = strtotime('+1 day', $cur);
        }

        // ── STEP 6: Holidays ──────────────────────────────────────────────────
        $deductibleHolidays = 0;
        $holQ = mysqli_query($con, "SELECT from_date,to_date,title,description FROM holidays WHERE (from_date BETWEEN '$fromDate' AND '$toDate') OR (to_date BETWEEN '$fromDate' AND '$toDate') OR ('$fromDate' BETWEEN from_date AND to_date)");
        while ($hol = mysqli_fetch_assoc($holQ)) {
            $titleDesc = strtolower($hol['title'] . ' ' . $hol['description']);
            $ol = strtolower($empOffice);
            if ((strpos($titleDesc, 'kinwat') !== false && $ol != 'kinwat') || (strpos($titleDesc, 'pune') !== false && $ol != 'pune'))
                continue;
            $hS = max($hol['from_date'], $fromDate);
            $hE = min($hol['to_date'], $toDate);
            $cur = $hS;
            while (strtotime($cur) <= strtotime($hE)) {
                $dow   = (int)date('w', strtotime($cur));
                $isWE  = $isKinwatOrSenior ? ($dow == 0) : ($dow == 0 || $dow == 6);
                if (!$isWE) $deductibleHolidays++;
                $cur = date('Y-m-d', strtotime($cur . ' +1 day'));
            }
        }

        // ── STEP 7: Absent ────────────────────────────────────────────────────
        $penaltyHalfDays = 0;
        if ($late >= 4) {
            $penaltyHalfDays = floor(($late - 2) / 2);
        }
        
        $absent = max(0, $totalDays - $weeklyOff - $rosteringOff - $present - $leaveDays - $tour - $deductibleHolidays);
        $absent += ($penaltyHalfDays * 0.5);

        echo "<tr>
            <td>{$srExp}</td>
            <td>" . htmlspecialchars($employeeName) . "</td>
            <td>" . htmlspecialchars($empDesignation) . "</td>
            <td>" . htmlspecialchars($empOffice) . "</td>
            <td>" . date('d M Y', strtotime($fromDate)) . " to " . date('d M Y', strtotime($toDate)) . "</td>
            <td>$totalDays</td><td>$weeklyOff</td><td>$rosteringOff</td><td>$present</td>
            <td>$leaveDays</td><td>$absent</td><td>$deductibleHolidays</td><td>$tour</td><td>$late</td>
        </tr>";
        $srExp++;
    }

    echo '</tbody></table></body></html>';
    exit;
}

// ── NORMAL PAGE PREPARATION & ANALYTICS ──────────────────────────────────────
$selectedEmployeeId = isset($_GET['employee_id']) ? mysqli_real_escape_string($con, $_GET['employee_id']) : 'all';
$fromDate = isset($_GET['from_date']) ? mysqli_real_escape_string($con, $_GET['from_date']) : date('Y-m-01');
$toDate = isset($_GET['to_date']) ? mysqli_real_escape_string($con, $_GET['to_date']) : date('Y-m-d');

$employeesQuery = mysqli_query($con, "SELECT fullName, userId, designation FROM signup WHERE status = 'active' AND designation NOT IN ('admin', 'developer', 'md') ORDER BY fullName");
if (!$employeesQuery) {
    $employeesQuery = mysqli_query($con, "SELECT fullName, userId FROM signup WHERE status = 'active' ORDER BY fullName");
}

// Gather report rows for selected employee or all employees
$reportRows = [];
$totalEmpCount = 0;
$totalPresentSum = 0;
$totalAbsentSum = 0;
$totalLeaveSum = 0;
$totalTourSum = 0;
$totalLateSum = 0;
$totalWeeklyOffSum = 0;
$totalRosterOffSum = 0;
$totalHolidaySum = 0;

$empFilterSql = ($selectedEmployeeId === 'all' || empty($selectedEmployeeId)) ? "" : "AND userId = '$selectedEmployeeId'";
$processEmpQ = mysqli_query($con, "SELECT userId, fullName, designation FROM signup WHERE status = 'active' AND designation NOT IN ('admin', 'developer', 'md') $empFilterSql ORDER BY fullName");
if (!$processEmpQ) {
    $processEmpQ = mysqli_query($con, "SELECT userId, fullName FROM signup WHERE status = 'active' $empFilterSql ORDER BY fullName");
}

if ($processEmpQ) {
    while ($empInfo = mysqli_fetch_assoc($processEmpQ)) {
        $eId = $empInfo['userId'];
        $employeeName = $empInfo['fullName'];
        $empDesignation = $empInfo['designation'] ?? '';
        $empOffice = $empInfo['office'] ?? '';

        $totalDays = (strtotime($toDate) - strtotime($fromDate)) / 86400 + 1;
        $isKinwatOrSenior = ($empDesignation == 'seniorIntern' || $empOffice == 'Kinwat');

        // ── STEP 1: Attendance (Present) ─────────────────────────────────────
        $presentDates = [];
        $halfDayDates = [];
        $late = 0;
        $attQ = mysqli_query($con, "SELECT `date`,punchInTime,lateMark,`status` FROM attendance WHERE userId='$eId' AND `date` BETWEEN '$fromDate' AND '$toDate'");
        if ($attQ) {
            while ($a = mysqli_fetch_assoc($attQ)) {
                $statusLower = strtolower($a['status']);
                if (strpos($statusLower, 'half day') !== false) {
                    $halfDayDates[] = $a['date'];
                } elseif (!empty($a['punchInTime']) || in_array($statusLower, ['present', 'online', 'present (online)'])) {
                    $presentDates[] = $a['date'];
                }
                if (isset($a['lateMark']) && strtolower($a['lateMark']) === 'yes')
                    $late++;
            }
        }
        $presentDates = array_unique($presentDates);
        $halfDayDates = array_unique($halfDayDates);

        // ── STEP 2: Tour ──────────────────────────────────────────────────────
        $tourDates = [];
        $tourQ = mysqli_query($con, "SELECT `date`,punchInTime,`status` FROM tourAttendance WHERE userId='$eId' AND `date` BETWEEN '$fromDate' AND '$toDate'");
        if ($tourQ) {
            while ($t = mysqli_fetch_assoc($tourQ)) {
                if (!empty($t['punchInTime']) || strtolower($t['status']) === 'present')
                    $tourDates[] = $t['date'];
            }
        }
        $tourDates = array_unique($tourDates);

        // Remove tour dates from present and half days to avoid double-count
        $presentDates = array_values(array_diff($presentDates, $tourDates));
        $halfDayDates = array_values(array_diff($halfDayDates, $tourDates));
        $present = count($presentDates) + (count($halfDayDates) * 0.5);
        $tour    = count($tourDates);

        // All days the employee actually showed up (for weekly-off check)
        $punchedInDates = array_unique(array_merge($presentDates, $halfDayDates, $tourDates));

        // ── STEP 3: Rostering Off date set ────────────────────────────────────
        $rosterOffDateSet = [];
        $roQ = mysqli_query($con, "SELECT from_date,to_date FROM leaves WHERE userId='$eId' AND leave_name='Rostering Off' AND status='Approved' AND ((from_date BETWEEN '$fromDate' AND '$toDate') OR (to_date BETWEEN '$fromDate' AND '$toDate') OR ('$fromDate' BETWEEN from_date AND to_date))");
        if ($roQ) {
            while ($r = mysqli_fetch_assoc($roQ)) {
                $cur = strtotime(max($r['from_date'], $fromDate));
                $end = strtotime(min($r['to_date'], $toDate));
                while ($cur <= $end) {
                    $rosterOffDateSet[] = date('Y-m-d', $cur);
                    $cur = strtotime('+1 day', $cur);
                }
            }
        }
        $rosterOffDateSet = array_unique($rosterOffDateSet);
        // Punch-in wins: if employee showed up, don't count as rostering off
        $rosterOffDateSet = array_values(array_diff($rosterOffDateSet, $punchedInDates));
        $rosteringOff     = count($rosterOffDateSet);

        // ── STEP 4: Leave date set (skip weekly-off days) ────────────────────
        $leaveDateSet = [];
        $lvQ = mysqli_query($con, "SELECT from_date,to_date FROM leaves WHERE userId='$eId' AND status='Approved' AND leave_name!='Rostering Off' AND ((from_date BETWEEN '$fromDate' AND '$toDate') OR (to_date BETWEEN '$fromDate' AND '$toDate') OR ('$fromDate' BETWEEN from_date AND to_date))");
        if ($lvQ) {
            while ($lv = mysqli_fetch_assoc($lvQ)) {
                $cur = strtotime(max($lv['from_date'], $fromDate));
                $end = strtotime(min($lv['to_date'], $toDate));
                while ($cur <= $end) {
                    $dow   = (int)date('w', $cur);
                    $isOff = $isKinwatOrSenior ? ($dow == 0) : ($dow == 0 || $dow == 6);
                    if (!$isOff) $leaveDateSet[] = date('Y-m-d', $cur);
                    $cur = strtotime('+1 day', $cur);
                }
            }
        }
        $leaveDateSet = array_unique($leaveDateSet);
        // Punch-in wins: if employee showed up, don't count as leave
        $leaveDateSet = array_values(array_diff($leaveDateSet, $punchedInDates));
        $leaveDays    = count($leaveDateSet);

        // ── STEP 5: Smart Weekly Off ──────────────────────────────────────────
        // A weekly-off day is only counted as "Weekly Off" if the employee
        // did NOT punch in, did NOT have a leave, and did NOT have a rostering-off.
        $weeklyOff = 0;
        $cur   = strtotime($fromDate);
        $endTs = strtotime($toDate);
        while ($cur <= $endTs) {
            $dow     = (int)date('w', $cur);
            $dateStr = date('Y-m-d', $cur);
            $isWeeklyOffDay = $isKinwatOrSenior ? ($dow == 0) : ($dow == 0 || $dow == 6);
            if ($isWeeklyOffDay) {
                $punchedIn   = in_array($dateStr, $punchedInDates);
                $onLeave     = in_array($dateStr, $leaveDateSet);
                $onRosterOff = in_array($dateStr, $rosterOffDateSet);
                if (!$punchedIn && !$onLeave && !$onRosterOff) {
                    $weeklyOff++;
                }
            }
            $cur = strtotime('+1 day', $cur);
        }

        // ── STEP 6: Holidays (working days only) ──────────────────────────────
        $deductibleHolidays = 0;
        $holQ = mysqli_query($con, "SELECT from_date,to_date,title,description FROM holidays WHERE (from_date BETWEEN '$fromDate' AND '$toDate') OR (to_date BETWEEN '$fromDate' AND '$toDate') OR ('$fromDate' BETWEEN from_date AND to_date)");
        if ($holQ) {
            while ($hol = mysqli_fetch_assoc($holQ)) {
                $td = strtolower($hol['title'] . ' ' . $hol['description']);
                $ol = strtolower($empOffice);
                if ((strpos($td, 'kinwat') !== false && $ol != 'kinwat') || (strpos($td, 'pune') !== false && $ol != 'pune'))
                    continue;
                $hS = max($hol['from_date'], $fromDate);
                $hE = min($hol['to_date'], $toDate);
                $cur = $hS;
                while (strtotime($cur) <= strtotime($hE)) {
                    $dow  = (int)date('w', strtotime($cur));
                    $isWE = $isKinwatOrSenior ? ($dow == 0) : ($dow == 0 || $dow == 6);
                    if (!$isWE) $deductibleHolidays++;
                    $cur = date('Y-m-d', strtotime($cur . ' +1 day'));
                }
            }
        }

        // ── STEP 7: Absent & Health Rate ─────────────────────────────────────
        $penaltyHalfDays = 0;
        if ($late >= 4) {
            $penaltyHalfDays = floor(($late - 2) / 2);
        }
        
        $absent = max(0, $totalDays - $weeklyOff - $rosteringOff - $present - $leaveDays - $tour - $deductibleHolidays);
        $absent += ($penaltyHalfDays * 0.5);

        $workingScope = max(1, $totalDays - $weeklyOff - $rosteringOff - $deductibleHolidays - $leaveDays);
        $attRate = min(100, round((($present + $tour) / $workingScope) * 100, 1));

        $reportRows[] = [
            'userId'       => $eId,
            'fullName'     => $employeeName,
            'designation'  => $empDesignation,
            'office'       => $empOffice,
            'totalDays'    => $totalDays,
            'weeklyOff'    => $weeklyOff,
            'rosteringOff' => $rosteringOff,
            'present'      => $present,
            'leaveDays'    => $leaveDays,
            'absent'       => $absent,
            'holidays'     => $deductibleHolidays,
            'tour'         => $tour,
            'late'         => $late,
            'attRate'      => $attRate
        ];

        $totalEmpCount++;
        $totalPresentSum   += $present;
        $totalAbsentSum    += $absent;
        $totalLeaveSum     += $leaveDays;
        $totalTourSum      += $tour;
        $totalLateSum      += $late;
        $totalWeeklyOffSum += $weeklyOff;
        $totalRosterOffSum += $rosteringOff;
        $totalHolidaySum   += $deductibleHolidays;
    }
}

$overallWorkingExpected = max(1, $totalPresentSum + $totalTourSum + $totalAbsentSum);
$overallAvgAttRate      = round((($totalPresentSum + $totalTourSum) / $overallWorkingExpected) * 100, 1);

// Sort top employees for bar chart leaderboard
$leaderboard = $reportRows;
usort($leaderboard, fn($a, $b) => $b['present'] <=> $a['present']);
$topEmpData = array_slice($leaderboard, 0, 8);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <title>SETTribe · Employee Attendance Summary Analytics</title>
    <?php include('../partials/_links.php') ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
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
            background: linear-gradient(135deg, #1e1b4b 0%, #312e81 50%, #4338ca 100%);
            border-radius: var(--radius-lg);
            padding: 24px 28px;
            color: #ffffff;
            margin-bottom: 24px;
            box-shadow: 0 10px 25px -5px rgba(49, 46, 129, 0.3);
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
            background: rgba(255, 255, 255, 0.06);
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

        /* ── Filter Card ── */
        .filter-card {
            background: #ffffff;
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            padding: 20px 24px;
            margin-bottom: 24px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.03);
        }
        .filter-label {
            font-size: 0.78rem;
            font-weight: 700;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin-bottom: 6px;
        }
        .preset-btn {
            font-size: 0.73rem;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 6px;
            border: 1px solid #cbd5e1;
            background: #f8fafc;
            color: #475569;
            cursor: pointer;
            transition: all 0.2s;
        }
        .preset-btn:hover {
            background: var(--primary-light);
            color: var(--primary);
            border-color: #c7d2fe;
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
        .icon-purple { background: var(--purple-light); color: var(--purple); }
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

        #empAttTable {
            width: 100% !important;
            border-collapse: separate;
            border-spacing: 0;
        }
        #empAttTable thead th {
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
        #empAttTable thead th:first-child { border-top-left-radius: 8px; }
        #empAttTable thead th:last-child { border-top-right-radius: 8px; }

        #empAttTable tbody td {
            font-size: 0.84rem;
            padding: 12px 14px;
            vertical-align: middle;
            border-bottom: 1px solid #f1f5f9;
            text-align: center;
            color: #334155;
        }
        #empAttTable tbody tr:hover { background-color: #f8fafc; }

        /* Badges */
        .att-badge-num {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 30px;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 700;
        }
        .badge-p  { background: var(--success-light); color: var(--success); border: 1px solid #a7f3d0; }
        .badge-a  { background: var(--danger-light); color: var(--danger); border: 1px solid #fecaca; }
        .badge-lv { background: var(--info-light); color: var(--info); border: 1px solid #a5f3fc; }
        .badge-tr { background: var(--warning-light); color: #d97706; border: 1px solid #fef3c7; }
        .badge-lt { background: var(--purple-light); color: var(--purple); border: 1px solid #ddd6fe; }
        .badge-wo { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
        .badge-ro { background: #fdf4ff; color: #c026d3; border: 1px solid #f5d0fe; }

        /* Health Score */
        .health-badge { padding: 2px 8px; border-radius: 12px; font-size: 0.74rem; font-weight: 800; }
        .health-high  { background: #dcfce7; color: #15803d; }
        .health-med   { background: #fef3c7; color: #b45309; }
        .health-low   { background: #fee2e2; color: #b91c1c; }

        .count-link-btn {
            background: none;
            border: none;
            padding: 0;
            cursor: pointer;
            transition: transform 0.15s ease;
        }
        .count-link-btn:hover {
            transform: scale(1.12);
        }
        .count-link-btn .att-badge-num {
            text-decoration: underline dotted;
        }

        .leave-link {
            color: var(--primary);
            font-weight: 800;
            cursor: pointer;
            text-decoration: underline dotted;
            border: none;
            background: none;
            padding: 0;
        }
        .leave-link:hover { color: var(--primary-dark); text-decoration: underline; }

        /* Modal Overlay */
        .leave-modal-overlay {
            display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.45);
            backdrop-filter: blur(4px); z-index: 1060; align-items: center; justify-content: center;
        }
        .leave-modal-overlay.active { display: flex; }
        .leave-modal {
            background: #fff; border-radius: 14px; width: 520px; max-width: 95vw; max-height: 82vh;
            display: flex; flex-direction: column; box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2); overflow: hidden;
        }
        .leave-modal-header { padding: 16px 20px; border-bottom: 1px solid #e2e8f0; display: flex; align-items: flex-start; justify-content: space-between; background: #f8fafc; }
        .leave-modal-body { padding: 20px; overflow-y: auto; }

        .btn-gradient-primary {
            background: linear-gradient(135deg, #4f46e5 0%, #3730a3 100%);
            color: #ffffff; border: none; font-weight: 700; padding: 8px 18px; border-radius: var(--radius-md); box-shadow: 0 4px 12px rgba(79, 70, 229, 0.25);
        }
        .btn-gradient-primary:hover { color: #fff; transform: translateY(-1px); }
        .btn-gradient-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: #ffffff; border: none; font-weight: 700; padding: 8px 18px; border-radius: var(--radius-md); box-shadow: 0 4px 12px rgba(16, 185, 129, 0.25);
        }
        .btn-gradient-success:hover { color: #fff; transform: translateY(-1px); }
    </style>
</head>

<body>
    <div class="container-scroller">
        <?php include('../partials/_navbar.php'); ?>

        <div class="container-fluid page-body-wrapper">
            <?php include('../partials/_sidebar.php'); ?>
            <?php include('../partials/_settings-panel.php'); ?>

            <div class="main-panel">
                <div class="content-wrapper">

                    <!-- ── HEADER BANNER ── -->
                    <div class="analytics-header-card">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                            <div>
                                <div class="header-title-badge">
                                    <i class="fas fa-id-card"></i> Employee Attendance Breakdown Report
                                </div>
                                <h2 style="font-size: 1.6rem; font-weight: 800; margin-bottom: 4px;">
                                    Employee Attendance Summary &amp; Leave Analytics
                                </h2>
                                <p style="font-size: 0.85rem; opacity: 0.88; margin: 0;">
                                    Comprehensive breakdown of present days, leave records, tour attendance, weekly offs, and punctuality scores.
                                </p>
                            </div>
                            <div class="text-right">
                                <span class="badge badge-light px-3 py-2" style="font-size:0.82rem; font-weight:700; border-radius:20px;">
                                    <i class="fas fa-calendar-day text-primary mr-1"></i>
                                    <?= date('d M Y', strtotime($fromDate)) ?> &nbsp;—&nbsp; <?= date('d M Y', strtotime($toDate)) ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- ── FILTER CARD ── -->
                    <div class="filter-card">
                        <form method="GET" id="filterForm">
                            <div class="row align-items-end">
                                <div class="col-lg-4 col-md-6 mb-3 mb-lg-0">
                                    <label class="filter-label"><i class="fas fa-user text-primary mr-1"></i> Employee Scope</label>
                                    <select name="employee_id" class="form-control select2" style="width:100%;">
                                        <option value="all" <?= ($selectedEmployeeId === 'all') ? 'selected' : ''; ?>>All Active Employees</option>
                                        <?php
                                        if ($employeesQuery) {
                                            mysqli_data_seek($employeesQuery, 0);
                                            while ($row = mysqli_fetch_assoc($employeesQuery)) {
                                                $sel = ($row['userId'] === $selectedEmployeeId) ? 'selected' : '';
                                                echo "<option value='" . htmlspecialchars($row['userId']) . "' $sel>" . htmlspecialchars($row['fullName']) . "</option>";
                                            }
                                        }
                                        ?>
                                    </select>
                                </div>

                                <div class="col-lg-4 col-md-6 mb-3 mb-lg-0">
                                    <div class="d-flex align-items-center justify-content-between mb-1">
                                        <label class="filter-label mb-0"><i class="fas fa-calendar-alt text-info mr-1"></i> Period</label>
                                        <div class="d-flex gap-1">
                                            <button type="button" class="preset-btn" onclick="setPreset('this_month')">This Month</button>
                                            <button type="button" class="preset-btn" onclick="setPreset('last_30')">30 Days</button>
                                        </div>
                                    </div>
                                    <div class="input-group">
                                        <input type="text" name="from_date" id="fromDate" class="form-control" value="<?= htmlspecialchars($fromDate) ?>" placeholder="From date" required style="border-radius:8px 0 0 8px;">
                                        <input type="text" name="to_date" id="toDate" class="form-control" value="<?= htmlspecialchars($toDate) ?>" placeholder="To date" required style="border-radius:0 8px 8px 0;">
                                    </div>
                                </div>

                                <div class="col-lg-4 col-md-12 text-right">
                                    <button type="submit" class="btn btn-gradient-primary mr-1">
                                        <i class="fas fa-chart-line mr-1"></i> Generate Report
                                    </button>
                                    <a href="?export=excel&employee_id=<?= urlencode($selectedEmployeeId) ?>&from_date=<?= urlencode($fromDate) ?>&to_date=<?= urlencode($toDate) ?>" class="btn btn-gradient-success">
                                        <i class="fas fa-file-excel mr-1"></i> Export Excel
                                    </a>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- ── KPI METRICS ROW ── -->
                    <div class="row mb-4">
                        <div class="col-xl-3 col-md-6 mb-3 mb-xl-0">
                            <div class="kpi-card kpi-blue">
                                <div class="kpi-header">
                                    <span class="kpi-title">Employees Analyzed</span>
                                    <div class="kpi-icon-wrap icon-blue"><i class="fas fa-users"></i></div>
                                </div>
                                <div>
                                    <div class="kpi-value"><?= $totalEmpCount ?></div>
                                    <div class="kpi-sub">Total Scope Included</div>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-3 col-md-6 mb-3 mb-xl-0">
                            <div class="kpi-card kpi-green">
                                <div class="kpi-header">
                                    <span class="kpi-title">Avg. Attendance Rate</span>
                                    <div class="kpi-icon-wrap icon-green"><i class="fas fa-chart-pie"></i></div>
                                </div>
                                <div>
                                    <div class="kpi-value"><?= $overallAvgAttRate ?>%</div>
                                    <div class="kpi-sub">Present: <strong><?= $totalPresentSum ?></strong> | Tour: <strong><?= $totalTourSum ?></strong></div>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-3 col-md-6 mb-3 mb-xl-0">
                            <div class="kpi-card kpi-purple">
                                <div class="kpi-header">
                                    <span class="kpi-title">Leaves &amp; Offs</span>
                                    <div class="kpi-icon-wrap icon-purple"><i class="fas fa-umbrella-beach"></i></div>
                                </div>
                                <div>
                                    <div class="kpi-value"><?= $totalLeaveSum ?></div>
                                    <div class="kpi-sub">Approved Leaves | Weekly Off: <strong><?= $totalWeeklyOffSum ?></strong></div>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-3 col-md-6 mb-3 mb-xl-0">
                            <div class="kpi-card kpi-amber">
                                <div class="kpi-header">
                                    <span class="kpi-title">Total Late Marks</span>
                                    <div class="kpi-icon-wrap icon-amber"><i class="fas fa-clock"></i></div>
                                </div>
                                <div>
                                    <div class="kpi-value"><?= $totalLateSum ?></div>
                                    <div class="kpi-sub">Unexcused Absences: <strong><?= $totalAbsentSum ?></strong></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── ANALYTICS CHARTS ── -->
                    <div class="row mb-4">
                        <!-- Doughnut Chart -->
                        <div class="col-lg-5 mb-3 mb-lg-0">
                            <div class="analytics-table-card h-100" style="padding:20px;">
                                <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-2">
                                    <h5 style="font-size:.95rem; font-weight:800; color:#0f172a; margin:0;">
                                        <i class="fas fa-chart-pie text-primary mr-1"></i> Overall Days Distribution
                                    </h5>
                                    <span class="badge badge-light border" style="font-size:.72rem;">Categorized Proportions</span>
                                </div>
                                <div style="position: relative; height: 240px;">
                                    <canvas id="attBreakdownChart"></canvas>
                                </div>
                            </div>
                        </div>

                        <!-- Leaderboard Bar Chart -->
                        <div class="col-lg-7">
                            <div class="analytics-table-card h-100" style="padding:20px;">
                                <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-2">
                                    <h5 style="font-size:.95rem; font-weight:800; color:#0f172a; margin:0;">
                                        <i class="fas fa-chart-bar text-purple mr-1"></i> Top Employee Performance Leaderboard
                                    </h5>
                                    <span class="badge badge-light border" style="font-size:.72rem;">Present vs Leaves vs Late</span>
                                </div>
                                <div style="position: relative; height: 240px;">
                                    <canvas id="empBarChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── REPORT TABLE CARD ── -->
                    <div class="analytics-table-card">
                        <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                            <div>
                                <h5 class="mb-0 font-weight-bold" style="color:#0f172a; font-size:1.05rem;">
                                    <i class="fas fa-table text-primary mr-1"></i> Employee Attendance Summary Table
                                </h5>
                                <p style="font-size:0.8rem; color:#64748b; margin:2px 0 0 0;">
                                    Period: <strong><?= date('d M Y', strtotime($fromDate)) ?></strong> to <strong><?= date('d M Y', strtotime($toDate)) ?></strong>
                                </p>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table" id="empAttTable">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th style="text-align:left;">Employee Name</th>
                                        <th>Health Rate</th>
                                        <th>Period Days</th>
                                        <th>Present</th>
                                        <th>Tour</th>
                                        <th>Leaves</th>
                                        <th>Absent</th>
                                        <th>Late Marks</th>
                                        <th>Weekly Off</th>
                                        <th>Rostering Off</th>
                                        <th>Holidays</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $sr = 1;
                                    foreach ($reportRows as $row):
                                        $rate = $row['attRate'];
                                        $healthClass = ($rate >= 90) ? 'health-high' : (($rate >= 75) ? 'health-med' : 'health-low');
                                    ?>
                                        <tr>
                                            <td class="font-weight-bold text-muted"><?= $sr++; ?></td>
                                            <td style="text-align:left;">
                                                <strong style="color:#0f172a; font-size:.88rem;"><?= htmlspecialchars((string)$row['fullName']); ?></strong>
                                                <div style="font-size:.72rem; color:#64748b;">
                                                    <?= htmlspecialchars((string)($row['designation'] ?? 'Staff')); ?>
                                                    <?php if (!empty($row['office'])): ?> · <?= htmlspecialchars((string)$row['office']); ?><?php endif; ?>
                                                </div>
                                            </td>
                                            <td><span class="health-badge <?= $healthClass ?>"><?= $rate ?>%</span></td>
                                            <td><strong><?= $row['totalDays']; ?></strong></td>
                                            <td><?= renderCountCell($row['present'], 'present', $row['userId'], $fromDate, $toDate, $row['fullName'], 'badge-p'); ?></td>
                                            <td><?= renderCountCell($row['tour'], 'tour', $row['userId'], $fromDate, $toDate, $row['fullName'], 'badge-tr'); ?></td>
                                            <td><?= renderCountCell($row['leaveDays'], 'leaves', $row['userId'], $fromDate, $toDate, $row['fullName'], 'badge-lv'); ?></td>
                                            <td><?= renderCountCell($row['absent'], 'absent', $row['userId'], $fromDate, $toDate, $row['fullName'], 'badge-a'); ?></td>
                                            <td><?= renderCountCell($row['late'], 'late', $row['userId'], $fromDate, $toDate, $row['fullName'], 'badge-lt'); ?></td>
                                            <td><?= renderCountCell($row['weeklyOff'], 'weekly_off', $row['userId'], $fromDate, $toDate, $row['fullName'], 'badge-wo'); ?></td>
                                            <td><?= renderCountCell($row['rosteringOff'], 'rostering_off', $row['userId'], $fromDate, $toDate, $row['fullName'], 'badge-ro'); ?></td>
                                            <td><?= renderCountCell($row['holidays'], 'holidays', $row['userId'], $fromDate, $toDate, $row['fullName'], 'badge-wo'); ?></td>
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

    <!-- Breakdown Detail Modal -->
    <div class="leave-modal-overlay" id="breakdownModalOverlay">
        <div class="leave-modal" style="width: 580px;">
            <div class="leave-modal-header" style="background:#f8fafc; border-bottom:1px solid #e2e8f0; padding:16px 20px;">
                <div>
                    <div class="lm-title" id="bmTitle" style="font-size:1.05rem; font-weight:800; color:#0f172a;">Attendance Breakdown</div>
                    <div class="lm-sub" id="bmSub" style="font-size:0.8rem; color:#64748b; margin-top:2px;"></div>
                </div>
                <button type="button" class="leave-modal-close" id="breakdownModalClose" onclick="closeBreakdownModal()" title="Close">&times;</button>
            </div>
            <div class="leave-modal-body" id="breakdownModalBody" style="padding:20px; max-height:420px; overflow-y:auto;">
                <div class="lm-spinner" style="text-align:center; padding:30px; color:#64748b;"><i class="fas fa-spinner fa-spin fa-2x mb-2"></i><br>Loading details…</div>
            </div>
            <div class="modal-footer" id="breakdownModalFooter" style="padding:12px 20px; border-top:1px solid #e2e8f0; background:#f8fafc; display:flex; align-items:center; justify-content:space-between;">
                <button type="button" class="btn btn-sm btn-light border font-weight-600" onclick="closeBreakdownModal()">Close</button>
                <a href="#" id="bmReportLink" target="_blank" class="btn btn-sm btn-gradient-primary">
                    <i class="fas fa-external-link-alt mr-1"></i> Open Full Detailed Report
                </a>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="../vendors/js/vendor.bundle.base.js"></script>
    <script src="../vendors/chart.js/Chart.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap4.min.js"></script>

    <script>
        $(document).ready(function () {
            $('.select2').select2({ width: '100%' });

            flatpickr("#fromDate", { dateFormat: "Y-m-d", maxDate: "today" });
            flatpickr("#toDate", { dateFormat: "Y-m-d", maxDate: "today" });

            $('#empAttTable').DataTable({
                pageLength: 25,
                order: [[ 2, "desc" ]],
                language: { search: "<i class='fas fa-search text-muted mr-1'></i> Search Employee:" }
            });

            // Doughnut Chart
            var ctxDoughnut = document.getElementById('attBreakdownChart').getContext('2d');
            new Chart(ctxDoughnut, {
                type: 'doughnut',
                data: {
                    labels: ['Present Days', 'Tour Days', 'Approved Leaves', 'Weekly Off', 'Rostering Off', 'Holidays', 'Absent Days'],
                    datasets: [{
                        data: [
                            <?= $totalPresentSum ?>,
                            <?= $totalTourSum ?>,
                            <?= $totalLeaveSum ?>,
                            <?= $totalWeeklyOffSum ?>,
                            <?= $totalRosterOffSum ?>,
                            <?= $totalHolidaySum ?>,
                            <?= $totalAbsentSum ?>
                        ],
                        backgroundColor: ['#10b981', '#f59e0b', '#06b6d4', '#64748b', '#c026d3', '#3b82f6', '#ef4444'],
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    legend: { position: 'right', labels: { boxWidth: 12, fontSize: 10 } }
                }
            });

            // Bar Chart
            <?php
            $topNames = array_map(fn($item) => $item['fullName'], $topEmpData);
            $topP = array_map(fn($item) => $item['present'], $topEmpData);
            $topL = array_map(fn($item) => $item['leaveDays'], $topEmpData);
            $topLt = array_map(fn($item) => $item['late'], $topEmpData);
            ?>
            var ctxBar = document.getElementById('empBarChart').getContext('2d');
            new Chart(ctxBar, {
                type: 'bar',
                data: {
                    labels: <?= json_encode($topNames) ?>,
                    datasets: [
                        { label: 'Present Days', data: <?= json_encode($topP) ?>, backgroundColor: '#10b981', borderRadius: 4 },
                        { label: 'Leave Days', data: <?= json_encode($topL) ?>, backgroundColor: '#06b6d4', borderRadius: 4 },
                        { label: 'Late Marks', data: <?= json_encode($topLt) ?>, backgroundColor: '#8b5cf6', borderRadius: 4 }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    legend: { position: 'top', labels: { boxWidth: 12, fontSize: 11 } },
                    scales: {
                        xAxes: [{ gridLines: { display: false } }],
                        yAxes: [{ ticks: { beginAtZero: true, stepSize: 1 } }]
                    }
                }
            });
        });

        function setPreset(type) {
            let today = new Date();
            let fromInput = document.getElementById("fromDate");
            let toInput   = document.getElementById("toDate");

            if (type === 'this_month') {
                let firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
                fromInput.value = firstDay.toISOString().split('T')[0];
                toInput.value   = today.toISOString().split('T')[0];
            } else if (type === 'last_30') {
                let past30 = new Date();
                past30.setDate(today.getDate() - 30);
                fromInput.value = past30.toISOString().split('T')[0];
                toInput.value   = today.toISOString().split('T')[0];
            }
        }

        function leaveIcon(leaveType) {
            const map = { 'CL': 'CL', 'SL': 'SL', 'EL': 'PL', 'Auto': 'Au', 'ML': 'SL', 'PL': 'PL' };
            return map[leaveType] || leaveType.substring(0, 2).toUpperCase();
        }
        function leaveIconClass(leaveType) {
            const map = { 'CL': 'CL', 'SL': 'SL', 'EL': 'PL', 'Auto': 'Auto', 'ML': 'SL', 'PL': 'PL' };
            return map[leaveType] || 'other';
        }
        function escHtml(str) {
            if (!str) return '';
            return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        function openBreakdownModal(empId, category, fromDate, toDate, empName) {
            document.getElementById('bmTitle').textContent = 'Loading Breakdown…';
            document.getElementById('bmSub').textContent = empName + ' (' + fromDate + ' to ' + toDate + ')';
            document.getElementById('breakdownModalBody').innerHTML = '<div class="lm-spinner" style="text-align:center; padding:30px; color:#64748b;"><i class="fas fa-spinner fa-spin fa-2x mb-2"></i><br>Loading records…</div>';
            document.getElementById('breakdownModalOverlay').classList.add('active');
            document.getElementById('bmReportLink').style.display = 'none';

            const url = window.location.pathname + '?ajax_breakdown=1&employee_id=' + encodeURIComponent(empId) +
                '&from_date=' + encodeURIComponent(fromDate) + '&to_date=' + encodeURIComponent(toDate) +
                '&category=' + encodeURIComponent(category);

            fetch(url)
                .then(r => {
                    if (!r.ok) throw new Error('HTTP status ' + r.status);
                    return r.text();
                })
                .then(text => {
                    try {
                        const data = JSON.parse(text);
                        if (data && data.success === false) {
                            throw new Error(data.error || 'Server error occurred');
                        }
                        renderBreakdownModal(data);
                    } catch(e) {
                        console.error('JSON parse or server error. Response was:', text);
                        document.getElementById('breakdownModalBody').innerHTML =
                            '<div style="padding:15px; color:#b91c1c; font-size:12px;">' +
                            '<strong>Server returned invalid data or error:</strong><br>' +
                            '<pre style="white-space:pre-wrap; word-break:break-all; max-height:220px; overflow:auto; background:#fff0f0; border:1px solid #fca5a5; padding:8px; border-radius:6px; margin-top:8px; color:#7f1d1d;">' + escHtml(text || e.message) + '</pre>' +
                            '</div>';
                    }
                })
                .catch(err => {
                    console.error('Fetch breakdown error:', err);
                    document.getElementById('breakdownModalBody').innerHTML =
                        '<div style="text-align:center; padding:20px; color:#ef4444;"><i class="fas fa-exclamation-circle mr-1"></i>Failed to load records (' + escHtml(err.message) + ').</div>';
                });
        }

        function renderBreakdownModal(data) {
            document.getElementById('bmTitle').textContent = data.title || 'Record Breakdown';

            const linkBtn = document.getElementById('bmReportLink');
            if (data.report_url) {
                linkBtn.href = data.report_url;
                linkBtn.style.display = 'inline-flex';
            } else {
                linkBtn.style.display = 'none';
            }

            const body = document.getElementById('breakdownModalBody');
            if (!data.items || data.items.length === 0) {
                body.innerHTML = '<div style="text-align:center; padding:30px; color:#64748b;"><i class="fas fa-calendar-times fa-2x mb-2 text-muted"></i><br>No records found for this category in the selected period.</div>';
                return;
            }

            let html = `
            <div style="display:flex; align-items:center; justify-content:space-between; background:#f1f5f9; padding:8px 14px; border-radius:8px; margin-bottom:14px; font-size:.82rem; font-weight:700; color:#334155;">
                <span>Total Records Found:</span>
                <span class="badge badge-primary px-2.5 py-1" style="font-size:.78rem;">${data.items.length}</span>
            </div>
            <div style="display:flex; flex-direction:column; gap:8px;">`;

            data.items.forEach(item => {
                html += `
                <div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:12px 14px; display:flex; align-items:center; justify-content:space-between;">
                    <div>
                        <div style="font-weight:700; font-size:.86rem; color:#0f172a;">
                            <i class="far fa-calendar-alt mr-1 text-primary"></i> ${escHtml(item.date)} <small class="text-muted">(${escHtml(item.day)})</small>
                        </div>
                        <div style="font-size:.78rem; color:#475569; margin-top:2px;">
                            ${escHtml(item.details)}
                        </div>
                        ${item.meta ? `<div style="font-size:.72rem; color:#64748b; margin-top:1px;">${escHtml(item.meta)}</div>` : ''}
                    </div>
                    <div>
                        <span class="att-badge-num ${item.badge}">${escHtml(item.status)}</span>
                    </div>
                </div>`;
            });

            html += `</div>`;
            body.innerHTML = html;
        }

        function closeBreakdownModal() {
            document.getElementById('breakdownModalOverlay').classList.remove('active');
        }

        document.getElementById('breakdownModalOverlay').addEventListener('click', function (e) {
            if (e.target === this) closeBreakdownModal();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeBreakdownModal();
        });
    </script>
</body>
</html>