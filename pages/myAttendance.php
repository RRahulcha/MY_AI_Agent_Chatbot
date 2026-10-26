<?php
session_start();
if (!isset($_SESSION['userId']) && empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
} elseif ($_SESSION['userRole'] === 'admin') {
    echo '<script>alert("You are not authorized to access this page.");window.location.href="login.php";</script>';
    die();
}
include('../include/db_config.php');
require_once('../include/late_mark_helper.php');

/* ═══════════════════════════════════════════════════════════════════════
   DATA FETCH
   ═══════════════════════════════════════════════════════════════════════ */
$userId          = $_SESSION['userId'];
$result          = mysqli_query($con, "SELECT * FROM signup WHERE userId = '$userId'") or die(mysqli_error($con));
$fetch_data      = mysqli_fetch_assoc($result);
$isOfflineIntern = strtolower($fetch_data['type'] ?? '') === 'offline';
$expectedPunchIn  = $fetch_data['expectedPunchIn']  ?? '09:30:00';
$expectedPunchOut = $fetch_data['expectedPunchOut'] ?? '18:30:00';
$expInDisp  = !empty($expectedPunchIn)  ? date('h:i A', strtotime('2000-01-01 ' . $expectedPunchIn))  : '09:30 AM';
$expOutDisp = !empty($expectedPunchOut) ? date('h:i A', strtotime('2000-01-01 ' . $expectedPunchOut)) : '06:30 PM';
$userName        = htmlspecialchars($fetch_data['fullName'] ?? 'User');

/* Month / Year */
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$year  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');

$firstDayOfMonth = date('Y-m-01', strtotime("$year-$month-01"));
$lastDayOfMonth  = date('Y-m-t',  strtotime($firstDayOfMonth));
$prevMonth       = date('m', strtotime("$firstDayOfMonth -1 month"));
$prevYear        = date('Y', strtotime("$firstDayOfMonth -1 month"));
$nextMonth       = date('m', strtotime("$firstDayOfMonth +1 month"));
$nextYear        = date('Y', strtotime("$firstDayOfMonth +1 month"));
$today           = date('Y-m-d');
$isCurrentMonth  = ($month == (int)date('m') && $year == (int)date('Y'));
$monthLabel      = date('F Y', mktime(0, 0, 0, $month, 1, $year));

/* Build date array */
$Dates  = [];
$period = new DatePeriod(new DateTime($firstDayOfMonth), new DateInterval('P1D'), new DateTime($lastDayOfMonth . ' +1 day'));
foreach ($period as $date) $Dates[] = $date->format('Y-m-d');

/* Approved leave dates */
$leaveDates  = [];
$leaveResult = mysqli_query($con, "SELECT from_date, to_date FROM leaves WHERE userId='$userId' AND status='Approved'");
while ($row = mysqli_fetch_assoc($leaveResult))
    for ($d = strtotime($row['from_date']); $d <= strtotime($row['to_date']); $d += 86400)
        $leaveDates[] = date('Y-m-d', $d);

/* Rostering Off dates */
$validRODatesThisMonth = [];
if ($isOfflineIntern) {
    $roFetch = mysqli_query($con, "SELECT from_date, to_date FROM leaves WHERE userId='$userId' AND leave_type='RO' AND status='Approved' ORDER BY from_date ASC");
    $allROThisMonth = [];
    while ($roRow = mysqli_fetch_assoc($roFetch))
        for ($d = strtotime($roRow['from_date']); $d <= strtotime($roRow['to_date']); $d += 86400)
            if (date('Y', $d) == $year && date('n', $d) == $month) $allROThisMonth[] = date('Y-m-d', $d);
    sort($allROThisMonth);
    $validRODatesThisMonth = array_slice($allROThisMonth, 0, 2);
}

/* Holidays */
$holidaysArr = [];
$hRes = mysqli_query($con, "SELECT * FROM holidays WHERE from_date<='$lastDayOfMonth' AND to_date>='$firstDayOfMonth'");
while ($h = mysqli_fetch_assoc($hRes))
    $holidaysArr[] = ['from' => $h['from_date'], 'to' => $h['to_date'], 'title' => $h['title']];

/* ═══════════════════════════════════════════════════════════════════════
   STAT ACCUMULATORS
   ═══════════════════════════════════════════════════════════════════════ */
$statPresent = $statAbsent = $statLeave = $statLate = $statWeekend = $statHoliday = $statRO = 0;
$totalMinutes = 0;
$attendanceData = [];
$listRows       = [];

/* ═══════════════════════════════════════════════════════════════════════
   MAIN COMPUTE LOOP — one pass, no echo
   ═══════════════════════════════════════════════════════════════════════ */
foreach ($Dates as $day) {
    $isToday   = ($day === $today);
    $dayOfWeek = date('D', strtotime($day));
    $dayLabel  = (int)date('j', strtotime($day));
    $fullDate  = date('D, d M Y', strtotime($day));

    /* Determine date-based expected shift timing */
    if ($day < '2026-08-01') {
        $dayExpectedPunchIn  = '10:00:00';
        $dayExpectedPunchOut = '18:00:00';
    } else {
        $dayExpectedPunchIn  = $fetch_data['expectedPunchIn'] ?? '09:30:00';
        $dayExpectedPunchOut = $fetch_data['expectedPunchOut'] ?? '18:30:00';
    }

    /* Holiday check */
    $isHoliday = false; $holidayTitle = '';
    foreach ($holidaysArr as $h)
        if ($day >= $h['from'] && $day <= $h['to']) { $isHoliday = true; $holidayTitle = $h['title']; break; }

    /* DB queries for this day */
    $attQ  = mysqli_query($con, "SELECT * FROM attendance WHERE punchInTime!='00:00:00' AND date='$day' AND userId='{$fetch_data['userId']}' ORDER BY id DESC LIMIT 1");
    $tourQ = mysqli_query($con, "SELECT * FROM tourAttendance WHERE punchInTime!='00:00:00' AND date='$day' AND userId='{$fetch_data['userId']}'");

    /* Day-level variables */
    $modalStatus = $cellBadge = $cellHours = $cellTimeLabel = '';
    $modalPunchIn = $modalPunchOut = $modalHours = '-';
    $modalIsLate = $modalIsRegularized = false;
    $modalLateMinutes = 0; $modalLeaveType = ''; $dayMinutes = 0;

    if ($dayOfWeek === 'Sun') {
        /* ── Sunday ─────────────────────────────────────────────────── */
        $modalStatus = 'Weekend';
        $cellBadge   = "<span class='cal-badge weekend'>Weekend</span>";
        $statWeekend++;

    } elseif ($isHoliday) {
        /* ── Holiday ────────────────────────────────────────────────── */
        $modalStatus = 'Holiday';
        $cellBadge   = "<span class='cal-badge holiday'>Holiday</span><div class='cal-holiday-name'>" . htmlspecialchars($holidayTitle) . "</div>";
        $statHoliday++;

    } elseif (mysqli_num_rows($attQ) > 0) {
        /* ── Has punch record ───────────────────────────────────────── */
        $att = mysqli_fetch_assoc($attQ);
        $pi  = $att['punchInTime'];
        $po  = $att['punchOutTime'];

        /* Evaluate Late Mark Policy */
        $lateEval      = evaluateLateMarkPolicy($pi, $dayExpectedPunchIn, $day);
        $isLate        = $lateEval['isLate'];
        $isHalfDayLate = $lateEval['isHalfDay'];
        $lateMinutes   = $lateEval['lateMinutes'];
        $isAbsentLate  = false;

        /* Late punch-in intimation override */
        if ($isLate || $isAbsentLate) {
            $liQ = mysqli_query($con, "SELECT id FROM late_punch_in WHERE userId='$userId' AND date='$day' LIMIT 1");
            if (mysqli_num_rows($liQ) > 0) { $isAbsentLate = false; $isLate = true; }
        }

        /* Regularization override */
        $regQ = mysqli_query($con, "SELECT punch_in FROM attendance_regularization WHERE user_id='$userId' AND date='$day' AND status='Approved' LIMIT 1");
        if (mysqli_num_rows($regQ) > 0) {
            $rr            = mysqli_fetch_assoc($regQ);
            $pi            = $rr['punch_in']; $modalIsRegularized = true;
            $lateEval      = evaluateLateMarkPolicy($pi, $dayExpectedPunchIn, $day);
            $isLate        = $lateEval['isLate'];
            $isHalfDayLate = $lateEval['isHalfDay'];
            $lateMinutes   = $lateEval['lateMinutes'];
        }

        $dbStatus   = $att['status'] ?? 'Present';
        $isOnline   = in_array($dbStatus, ['Present (Online)', 'Online']);
        $modalPunchIn  = $pi;
        $modalPunchOut = (!empty($po) && $po !== '00:00:00') ? $po : '-';

        if (!empty($pi) && $pi !== '00:00:00') {
            $piFormatted = date('g:i:s A', strtotime("1970-01-01 $pi"));
            if (!empty($po) && $po !== '00:00:00') {
                $poFormatted = date('g:i:s A', strtotime("1970-01-01 $po"));
                $cellTimeLabel = "<div class='cal-time'><i class='far fa-clock' style='font-size:0.65rem;'></i> {$piFormatted} - {$poFormatted}</div>";
                $diff = (new DateTime($pi))->diff(new DateTime($po));
                $modalHours = $diff->format('%h h %i m %s s');
                $cellHours  = "<div class='cal-hours'>{$modalHours}</div>";
                $dayMinutes = $diff->h * 60 + $diff->i;
            } else {
                $cellTimeLabel = "<div class='cal-time'><i class='far fa-clock' style='font-size:0.65rem;'></i> {$piFormatted}</div>";
            }
        }

        if ($isHalfDayLate || strtolower($dbStatus) === 'half day' || strtolower($dbStatus) === 'half day (online)') {
            $modalStatus = $isOnline ? 'Half Day (Online)' : 'Half Day';
            $cellBadge   = "<span class='cal-badge on-leave'>Half Day</span>";
            $statLeave  += 0.5; $statPresent += 0.5; $totalMinutes += $dayMinutes;
        } elseif ($isAbsentLate) {
            $modalStatus = 'Absent';
            $cellBadge   = "<span class='cal-badge absent'>Absent</span>";
            $statAbsent++;
        } elseif ($isLate) {
            $lbl = $isOnline ? 'Present (Online)' : 'Present';
            $modalStatus = $lbl; $modalIsLate = true; $modalLateMinutes = $lateMinutes;
            $cellBadge   = "<span class='cal-badge present-late'>{$lbl} <small style='font-size:0.6rem;opacity:0.8;'>(Late)</small></span>";
            $statPresent++; $statLate++; $totalMinutes += $dayMinutes;
        } else {
            $modalStatus = $isOnline ? 'Present (Online)' : 'Present';
            $cellBadge   = $isOnline ? "<span class='cal-badge online'>Present (Online)</span>" : "<span class='cal-badge present'>Present</span>";
            $statPresent++; $totalMinutes += $dayMinutes;
        }

    } elseif (mysqli_num_rows($tourQ) > 0) {
        /* ── Tour attendance ────────────────────────────────────────── */
        $att = mysqli_fetch_assoc($tourQ);
        $pi  = $att['punchInTime']; $po = $att['punchOutTime'];
        $modalStatus   = 'On Tour'; $modalPunchIn = $pi;
        $modalPunchOut = (!empty($po) && $po !== '00:00:00') ? $po : '-';
        if (!empty($pi) && $pi !== '00:00:00') {
            $piFormatted = date('g:i:s A', strtotime("1970-01-01 $pi"));
            if (!empty($po) && $po !== '00:00:00') {
                $poFormatted = date('g:i:s A', strtotime("1970-01-01 $po"));
                $cellTimeLabel = "<div class='cal-time'><i class='far fa-clock' style='font-size:0.65rem;'></i> {$piFormatted} - {$poFormatted}</div>";
                $diff = (new DateTime($pi))->diff(new DateTime($po));
                $modalHours = $diff->format('%h h %i m %s s');
                $cellHours  = "<div class='cal-hours'>{$modalHours}</div>";
                $dayMinutes = $diff->h * 60 + $diff->i;
            } else {
                $cellTimeLabel = "<div class='cal-time'><i class='far fa-clock' style='font-size:0.65rem;'></i> {$piFormatted}</div>";
            }
        }
        $cellBadge = "<span class='cal-badge tour'>On Tour</span>";
        $statPresent++; $totalMinutes += $dayMinutes;

    } else {
        /* ── No punch record ────────────────────────────────────────── */
        if (in_array($day, $leaveDates)) {
            $ltQ = mysqli_query($con, "SELECT leave_type FROM leaves WHERE userId='$userId' AND status='Approved' AND '$day' BETWEEN from_date AND to_date LIMIT 1");
            $approvedLT = ($ltRow = mysqli_fetch_assoc($ltQ)) ? $ltRow['leave_type'] : null;
            $modalLeaveType = $approvedLT ?? '';
            if ($approvedLT === 'RO') {
                if ($isOfflineIntern && !in_array($day, $validRODatesThisMonth)) {
                    $modalStatus = 'Absent'; $cellBadge = "<span class='cal-badge absent'>Absent</span>"; $statAbsent++;
                } else {
                    $modalStatus = 'Rostering Off'; $cellBadge = "<span class='cal-badge ro'>Rostering Off</span>"; $statRO++;
                }
            } else {
                $modalStatus = 'On Leave'; $cellBadge = "<span class='cal-badge on-leave'>On Leave</span>"; $statLeave++;
            }
        } else {
            $leaveQ = mysqli_query($con, "SELECT leave_type FROM leaves WHERE leave_day='$day' AND userId='{$fetch_data['userId']}'");
            if (mysqli_num_rows($leaveQ) > 0) {
                $lr = mysqli_fetch_assoc($leaveQ); $modalLeaveType = $lr['leave_type'];
                if ($lr['leave_type'] === 'RO') {
                    if ($isOfflineIntern && !in_array($day, $validRODatesThisMonth)) {
                        $modalStatus = 'Absent'; $cellBadge = "<span class='cal-badge absent'>Absent</span>"; $statAbsent++;
                    } else {
                        $modalStatus = 'Rostering Off'; $cellBadge = "<span class='cal-badge ro'>Rostering Off</span>"; $statRO++;
                    }
                } else {
                    $modalStatus = 'Leave'; $cellBadge = "<span class='cal-badge on-leave'>Leave</span>"; $statLeave++;
                }
            } else {
                $sd = $fetch_data['start_date'] ?? '';
                if ($dayOfWeek === 'Sat') {
                    if ($isOfflineIntern) {
                        if (!empty($sd) && $day < $sd) { $modalStatus = '-'; $cellBadge = "<span class='cal-badge dash'>-</span>"; }
                        elseif ($day < $today) { $modalStatus = 'Absent'; $cellBadge = "<span class='cal-badge absent'>Absent</span>"; $statAbsent++; }
                    } else {
                        $satRoQ = mysqli_query($con, "SELECT leave_type FROM leaves WHERE userId='$userId' AND leave_type='RO' AND '$day' BETWEEN from_date AND to_date LIMIT 1");
                        if (mysqli_num_rows($satRoQ) > 0) {
                            $modalStatus = 'Rostering Off'; $cellBadge = "<span class='cal-badge ro'>Rostering Off</span>"; $statRO++;
                        } else {
                            $modalStatus = 'Weekend'; $cellBadge = "<span class='cal-badge weekend'>Weekend</span>"; $statWeekend++;
                        }
                    }
                } else {
                    if (!empty($sd) && $day < $sd) { $modalStatus = '-'; $cellBadge = "<span class='cal-badge dash'>-</span>"; }
                    elseif ($day < $today) { $modalStatus = 'Absent'; $cellBadge = "<span class='cal-badge absent'>Absent</span>"; $statAbsent++; }
                }
            }
        }
    }

    /* Store for JS modal */
    $attendanceData[$day] = [
        'date'          => $fullDate,   'status'        => $modalStatus,
        'punchIn'       => $modalPunchIn, 'punchOut'    => $modalPunchOut,
        'hours'         => $modalHours, 'isLate'        => $modalIsLate,
        'lateMinutes'   => $modalLateMinutes, 'leaveType' => $modalLeaveType,
        'isRegularized' => $modalIsRegularized,
    ];

    /* Store for list view render */
    $listRows[] = [
        'day'           => $day,         'label'         => $dayLabel,
        'fullDate'      => $fullDate,    'dayOfWeek'     => $dayOfWeek,
        'status'        => $modalStatus, 'punchIn'       => $modalPunchIn,
        'punchOut'      => $modalPunchOut, 'hours'       => $modalHours,
        'cellBadge'     => $cellBadge,   'cellTimeLabel' => $cellTimeLabel,
        'cellHours'     => $cellHours,   'isToday'       => $isToday,
        'isLate'        => $modalIsLate, 'lateMinutes'   => $modalLateMinutes,
        'leaveType'     => $modalLeaveType, 'isRegularized' => $modalIsRegularized,
    ];
}

/* ── Derived stats ─────────────────────────────────────────────────────── */
$totalHoursH    = floor($totalMinutes / 60);
$totalHoursM    = $totalMinutes % 60;
$totalHoursDisp = "{$totalHoursH}h {$totalHoursM}m";
$workingTotal   = $statPresent + $statAbsent;
$attendancePct  = $workingTotal > 0 ? round(($statPresent / $workingTotal) * 100) : 100;
$pctColorHex    = $attendancePct >= 90 ? '#027a48' : ($attendancePct >= 75 ? '#b54708' : '#c01048');
$pctBarColor    = $attendancePct >= 90 ? '#12b76a' : ($attendancePct >= 75 ? '#f79009' : '#f04438');

/* ── Export data array (clean strings for JS) ──────────────────────────── */
$exportData = [];
$sn = 1;
foreach ($listRows as $row) {
    $pi = (!empty($row['punchIn'])  && $row['punchIn']  !== '-') ? date('h:i:s A', strtotime('2000-01-01 ' . $row['punchIn']))  : '';
    $po = (!empty($row['punchOut']) && $row['punchOut'] !== '-') ? date('h:i:s A', strtotime('2000-01-01 ' . $row['punchOut'])) : '';
    $remarks = implode(' | ', array_filter([
        ($row['isLate'] && $row['lateMinutes'] > 0) ? 'Late ' . $row['lateMinutes'] . ' min' : '',
        $row['isRegularized'] ? 'Regularized' : '',
        !empty($row['leaveType']) ? $row['leaveType'] : '',
    ]));
    $exportData[] = [
        'sn'     => $sn++,
        'date'   => date('d M Y', strtotime($row['day'])),
        'day'    => $row['dayOfWeek'],
        'status' => $row['status'],
        'pi'     => $pi, 'po' => $po,
        'hours'  => ($row['hours'] !== '-') ? $row['hours'] : '',
        'remarks'=> $remarks,
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>My Attendance – <?= $monthLabel ?></title>
    <?php include('../partials/_links.php'); ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.17.0/xlsx.full.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.4.0/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.23/jspdf.plugin.autotable.min.js"></script>
    <style>
        body { background: #f5f6f8; }

        /* ── Page header ─────────────────────────────────────────────── */
        .att-page-header {
            display: flex; align-items: flex-start; justify-content: space-between;
            flex-wrap: wrap; gap: 12px; margin-bottom: 20px;
        }
        .att-page-header h4 { font-size: 1.18rem; font-weight: 700; color: #1a2332; margin: 0; }
        .att-page-header .sub { font-size: 0.8rem; color: #667085; margin-top: 3px; }
        .att-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        .btn-att {
            display: inline-flex; align-items: center; gap: 5px; padding: 7px 14px;
            border-radius: 7px; font-size: 0.79rem; font-weight: 600; cursor: pointer;
            border: none; transition: opacity .15s, transform .1s; text-decoration: none;
        }
        .btn-att:hover { opacity: .88; transform: translateY(-1px); }
        .btn-att.toggle { background: #eef2ff; color: #4b49ac; border: 1px solid #c7d4f5; }
        .btn-att.excel  { background: #dcfce7; color: #15803d; }
        .btn-att.pdf    { background: #fee2e2; color: #b91c1c; }

        /* ── Stats strip ─────────────────────────────────────────────── */
        .stats-strip {
            display: grid; grid-template-columns: repeat(7, 1fr); gap: 10px; margin-bottom: 14px;
        }
        @media (max-width: 992px) { .stats-strip { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 520px)  { .stats-strip { grid-template-columns: repeat(2, 1fr); } }
        .stat-card {
            background: #fff; border-radius: 10px; border: 1px solid #e8eaf0;
            padding: 12px 14px; display: flex; align-items: center; gap: 10px;
            box-shadow: 0 1px 4px rgba(0,0,0,.05); transition: box-shadow .15s;
        }
        .stat-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,.09); }
        .stat-icon { width: 36px; height: 36px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: .95rem; flex-shrink: 0; }
        .stat-val  { font-size: 1.22rem; font-weight: 800; color: #1a2332; line-height: 1; }
        .stat-lbl  { font-size: .67rem; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: .04em; margin-top: 2px; }

        /* ── Attendance % bar ────────────────────────────────────────── */
        .att-pct-wrap {
            background: #fff; border: 1px solid #e8eaf0; border-radius: 10px;
            padding: 12px 16px; margin-bottom: 16px; box-shadow: 0 1px 4px rgba(0,0,0,.05);
        }
        .att-pct-hdr { display: flex; align-items: center; justify-content: space-between; margin-bottom: 7px; }
        .att-pct-lbl { font-size: .8rem; font-weight: 600; color: #374151; }
        .att-pct-val { font-size: .88rem; font-weight: 800; }
        .att-pct-bg  { height: 8px; background: #e9ecef; border-radius: 99px; overflow: hidden; }
        .att-pct-fill { height: 100%; border-radius: 99px; transition: width .7s ease; }
        .att-pct-note { font-size: .7rem; color: #9ca3af; margin-top: 5px; }

        /* ── Nav ─────────────────────────────────────────────────────── */
        .calendar-nav { display: flex; align-items: center; gap: 10px; margin-bottom: 16px; flex-wrap: wrap; }
        .nav-btn {
            display: inline-flex; align-items: center; gap: 5px; padding: 6px 14px;
            border: 1px solid #d0d5dd; border-radius: 7px; background: #fff; color: #344054;
            font-size: .82rem; font-weight: 500; cursor: pointer; text-decoration: none;
            transition: background .13s;
        }
        .nav-btn:hover { background: #f2f4f7; border-color: #b0b7c3; color: #344054; }
        .nav-btn.today-btn { background: #eef2ff; color: #4b49ac; border-color: #c7d4f5; font-weight: 600; }
        .nav-btn.today-btn:hover { background: #dde5ff; }
        .nav-selects { display: flex; gap: 8px; margin: 0 auto; }
        .calendar-nav select {
            border: 1px solid #d0d5dd; border-radius: 7px; padding: 6px 30px 6px 12px;
            font-size: .9rem; font-weight: 600; color: #344054; background: #fff;
            appearance: none; cursor: pointer;
            background-image: url("data:image/svg+xml;utf8,<svg fill='gray' height='14' viewBox='0 0 24 24' width='14' xmlns='http://www.w3.org/2000/svg'><path d='M7 10l5 5 5-5z'/></svg>");
            background-repeat: no-repeat; background-position: right 10px center; background-size: 14px;
        }
        .calendar-nav select:focus { outline: none; border-color: #4b49ac; }

        /* ── Calendar grid ───────────────────────────────────────────── */
        .calendar { width: 100%; border-radius: 10px; overflow: hidden; border: 1px solid #e4e7ec; background: #fff; }
        .calendar-header { display: grid; grid-template-columns: repeat(7,1fr); background: #f8f9fb; border-bottom: 1px solid #e4e7ec; }
        .cal-head-cell { padding: 10px 0; text-align: center; font-size: .78rem; font-weight: 700; color: #667085; letter-spacing: .8px; text-transform: uppercase; }
        .calendar-body { display: grid; grid-template-columns: repeat(7,1fr); }
        .calendar-cell {
            min-height: 100px; border-right: 1px solid #e4e7ec; border-bottom: 1px solid #e4e7ec;
            padding: 8px 6px 6px; cursor: pointer; transition: background .12s; background: #fff;
        }
        .calendar-cell:hover { background: #f5f7ff; }
        .calendar-cell.empty { background: #fafafa; cursor: default; }
        .calendar-cell:nth-child(7n) { border-right: none; }
        .date-num {
            font-size: .82rem; font-weight: 700; color: #475467;
            width: 26px; height: 26px; display: flex; align-items: center; justify-content: center;
            border-radius: 50%; margin-bottom: 5px;
        }
        .calendar-cell.today .date-num { background: #4b49ac; color: #fff; }
        .cal-badge {
            display: inline-block; padding: 2px 8px; border-radius: 4px;
            font-size: .7rem; font-weight: 600; letter-spacing: .2px; margin-bottom: 3px;
        }
        .cal-badge.present      { background:#ecfdf3; color:#027a48; border:1px solid #a9efc5; }
        .cal-badge.present-late { background:#fff7ed; color:#c2410c; border:1px solid #fed7aa; }
        .cal-badge.online       { background:#eef1ff; color:#2d5be3; border:1px solid #c7d4f5; }
        .cal-badge.absent       { background:#fff1f2; color:#c01048; border:1px solid #fecdd6; }
        .cal-badge.on-leave     { background:#fff8ec; color:#b54708; border:1px solid #fcd9a0; }
        .cal-badge.weekend      { background:#f0f4ff; color:#3d5a99; border:1px solid #c7d4f5; }
        .cal-badge.holiday      { background:#fef3f2; color:#b42318; border:1px solid #fecdca; }
        .cal-badge.tour         { background:#fdf6ec; color:#b45309; border:1px solid #fde68a; }
        .cal-badge.ro           { background:#f5f0ff; color:#6d28d9; border:1px solid #ddd6fe; }
        .cal-badge.dash         { background:#f2f4f7; color:#98a2b3; border:1px solid #e4e7ec; }
        .cal-hours { font-size:.72rem; font-weight:600; color:#027a48; margin-top:2px; }
        .cal-time  { font-size:.68rem; font-weight:600; color:#475467; margin-top:2px; line-height:1.2; }
        .cal-holiday-name { font-size:.68rem; color:#b42318; font-weight:500; margin-top:2px; line-height:1.2; }
        @media (max-width:600px) {
            .calendar { overflow-x: auto; }
            .calendar-header,.calendar-body { grid-template-columns: repeat(7,72px); width: max-content; }
            .calendar-cell { min-height: 80px; padding: 5px 3px; }
            .date-num { font-size: .75rem; width: 22px; height: 22px; }
            .cal-badge { font-size: .62rem; padding: 2px 5px; }
            .cal-hours { font-size: .65rem; }
            .cal-time  { font-size: .6rem; }
        }
        }

        /* ── List view ───────────────────────────────────────────────── */
        #listView .table thead th {
            background: #f8f9fb; color: #374151; font-size: .76rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: .04em;
            border-bottom: 2px solid #e4e7ec; vertical-align: middle; text-align: center;
        }
        #listView .table td { vertical-align: middle; font-size: .84rem; color: #374151; text-align: center; }
        #listView .table .tr-today td { background: #f0f0ff; }
        #listView .table tbody tr { cursor: pointer; transition: background .12s; }
        #listView .table tbody tr:hover td { background: #f5f7ff; }
        .lp { font-size: .8rem; font-weight: 600; }
        .lp.na { color: #9ca3af; }
        .note-chip {
            display: inline-block; font-size: .68rem; font-weight: 600;
            padding: 1px 7px; border-radius: 99px; margin: 1px 2px;
        }
        .note-chip.late  { background:#fff7ed; color:#c2410c; border:1px solid #fed7aa; }
        .note-chip.reg   { background:#ecfdf3; color:#027a48; border:1px solid #a9efc5; }
        .note-chip.leave { background:#fff8ec; color:#b54708; border:1px solid #fcd9a0; }

        /* ── Legend ──────────────────────────────────────────────────── */
        .legend-bar {
            display: flex; flex-wrap: wrap; gap: 8px 18px; margin-top: 16px;
            padding: 12px 16px; background: #f8f9fb; border-radius: 8px; border: 1px solid #e4e7ec;
        }
        .legend-item { display: flex; align-items: center; gap: 5px; font-size: .74rem; color: #374151; font-weight: 500; }
        .legend-dot  { width: 10px; height: 10px; border-radius: 3px; flex-shrink: 0; }

        /* ── Day Detail Modal ────────────────────────────────────────── */
        .day-modal-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(16,24,40,.38); z-index: 1050;
            align-items: center; justify-content: center;
        }
        .day-modal-overlay.active { display: flex; }
        .day-modal {
            background: #fff; border-radius: 14px; width: 390px; max-width: 95vw;
            box-shadow: 0 8px 40px rgba(16,24,40,.18); overflow: hidden; animation: mIn .18s ease;
        }
        @keyframes mIn { from{opacity:0;transform:translateY(-14px) scale(.97)} to{opacity:1;transform:none} }
        .day-modal-header {
            padding: 16px 20px 14px; border-bottom: 1px solid rgba(255,255,255,.15);
            background: linear-gradient(135deg,#4b49ac 0%,#6366f1 100%);
            display: flex; align-items: flex-start; justify-content: space-between;
        }
        .modal-date { font-size: .97rem; font-weight: 700; color: #fff; }
        .modal-chips { display: flex; gap: 5px; flex-wrap: wrap; margin-top: 5px; }
        .modal-chip {
            font-size: .67rem; font-weight: 700; padding: 2px 9px; border-radius: 99px;
            background: rgba(255,255,255,.2); color: #fff; display: inline-flex; align-items: center; gap: 4px;
        }
        .modal-close {
            background: rgba(255,255,255,.18); border: none; width: 28px; height: 28px;
            border-radius: 50%; color: #fff; cursor: pointer; display: flex; align-items: center;
            justify-content: center; font-size: 1.05rem; flex-shrink: 0;
        }
        .modal-close:hover { background: rgba(255,255,255,.3); }
        .day-modal-body { padding: 16px 20px 20px; }
        .modal-row {
            display: flex; align-items: center; justify-content: space-between;
            padding: 9px 0; border-bottom: 1px solid #f2f4f7; font-size: .875rem;
        }
        .modal-row:last-child { border-bottom: none; }
        .modal-row .label { color: #667085; font-weight: 500; }
        .modal-row .value { color: #1a2332; font-weight: 600; }
        .modal-row .value.green  { color: #027a48; }
        .modal-row .value.red    { color: #c01048; }
        .modal-row .value.orange { color: #c2410c; }
    </style>
</head>
<body>
<div class="container-scroller">
    <?php include('../partials/_navbar.php'); ?>
    <div class="container-fluid page-body-wrapper">
        <?php include('../partials/_sidebar.php'); include('../partials/_settings-panel.php'); ?>
        <div class="main-panel">
            <div class="content-wrapper" style="background:#f5f6f8 !important;">
                <div class="row">
                    <div class="col-12 grid-margin stretch-card">
                        <div class="card">
                            <div class="card-body">

                                <!-- ══ Page Header ══ -->
                                <div class="att-page-header">
                                    <div>
                                        <h4><i class="fas fa-calendar-check me-2" style="color:#4b49ac;"></i>My Attendance</h4>
                                        <div class="sub"><i class="fas fa-calendar-alt me-1"></i><?= $monthLabel ?> &bull; <i class="fas fa-user me-1"></i><?= $userName ?> &bull; <i class="fas fa-clock me-1" style="color:#4b49ac;"></i>Expected Shift: <strong><?= $expInDisp ?> &ndash; <?= $expOutDisp ?></strong></div>
                                    </div>
                                    <div class="att-actions">
                                        <button class="btn-att toggle" id="viewToggleBtn" onclick="toggleView()">
                                            <i class="fas fa-list" id="vIcon"></i>
                                            <span id="vLabel">List View</span>
                                        </button>
                                        <button class="btn-att excel" onclick="exportExcel()">
                                            <i class="fas fa-file-excel"></i> Excel
                                        </button>
                                        <button class="btn-att pdf" onclick="exportPDF()">
                                            <i class="fas fa-file-pdf"></i> PDF
                                        </button>
                                    </div>
                                </div>

                                <!-- ══ Stats Strip ══ -->
                                <div class="stats-strip">
                                    <div class="stat-card">
                                        <div class="stat-icon" style="background:#ecfdf3;color:#027a48;"><i class="fas fa-check-circle"></i></div>
                                        <div>
                                            <div class="stat-val" style="color:#027a48;"><?= $statPresent ?></div>
                                            <div class="stat-lbl">Present</div>
                                        </div>
                                    </div>
                                    <div class="stat-card">
                                        <div class="stat-icon" style="background:#fff1f2;color:#c01048;"><i class="fas fa-times-circle"></i></div>
                                        <div>
                                            <div class="stat-val" style="color:#c01048;"><?= $statAbsent ?></div>
                                            <div class="stat-lbl">Absent</div>
                                        </div>
                                    </div>
                                    <div class="stat-card">
                                        <div class="stat-icon" style="background:#fff8ec;color:#b54708;"><i class="fas fa-umbrella-beach"></i></div>
                                        <div>
                                            <div class="stat-val" style="color:#b54708;"><?= $statLeave ?></div>
                                            <div class="stat-lbl">Leaves</div>
                                        </div>
                                    </div>
                                    <div class="stat-card">
                                        <div class="stat-icon" style="background:#fff7ed;color:#c2410c;"><i class="fas fa-clock"></i></div>
                                        <div>
                                            <div class="stat-val" style="color:#c2410c;"><?= $statLate ?></div>
                                            <div class="stat-lbl">Late Marks</div>
                                            <?php if ($statLate >= 4): ?>
                                                <div style="font-size:0.65rem;color:#b42318;font-weight:700;margin-top:2px;">Penalty: <?= calculateMonthlyLateMarkDeductions($statLate) ?> HD leave</div>
                                            <?php elseif ($statLate > 0): ?>
                                                <div style="font-size:0.65rem;color:#b54708;font-weight:600;margin-top:2px;"><?= $statLate ?>/3 warning level</div>
                                            <?php else: ?>
                                                <div style="font-size:0.65rem;color:#027a48;font-weight:600;margin-top:2px;">No Penalty</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="stat-card">
                                        <div class="stat-icon" style="background:#f0f4ff;color:#3d5a99;"><i class="fas fa-moon"></i></div>
                                        <div>
                                            <div class="stat-val" style="color:#3d5a99;"><?= $statWeekend + $statHoliday ?></div>
                                            <div class="stat-lbl">Off Days</div>
                                        </div>
                                    </div>
                                    <div class="stat-card">
                                        <div class="stat-icon" style="background:#F5F3FF;color:#7C3AED;"><i class="fas fa-bed"></i></div>
                                        <div>
                                            <div class="stat-val" style="color:#7C3AED;"><?= $statRO ?></div>
                                            <div class="stat-lbl">Rostering Off</div>
                                        </div>
                                    </div>
                                    <div class="stat-card">
                                        <div class="stat-icon" style="background:#eef2ff;color:#4b49ac;"><i class="fas fa-hourglass-half"></i></div>
                                        <div>
                                            <div class="stat-val" style="color:#4b49ac;font-size:.95rem;"><?= $totalHoursDisp ?></div>
                                            <div class="stat-lbl">Total Hours</div>
                                        </div>
                                    </div>
                                </div>

                                <!-- ══ Attendance % Progress Bar ══ -->
                                <div class="att-pct-wrap">
                                    <div class="att-pct-hdr">
                                        <span class="att-pct-lbl"><i class="fas fa-chart-bar me-1" style="color:#4b49ac;"></i>Attendance Rate</span>
                                        <span class="att-pct-val" style="color:<?= $pctColorHex ?>;"><?= $attendancePct ?>%</span>
                                    </div>
                                    <div class="att-pct-bg">
                                        <div class="att-pct-fill" style="width:<?= $attendancePct ?>%;background:<?= $pctBarColor ?>;"></div>
                                    </div>
                                    <div class="att-pct-note">
                                        <?= $statPresent ?> present out of <?= $workingTotal ?> working day<?= $workingTotal !== 1 ? 's' : '' ?>
                                        &mdash; excludes weekends, holidays &amp; approved leaves
                                    </div>
                                </div>

                                <!-- ══ Calendar Navigation ══ -->
                                <div class="calendar-nav">
                                    <a href="?month=<?= $prevMonth ?>&year=<?= $prevYear ?>" class="nav-btn">
                                        <i class="fas fa-chevron-left" style="font-size:.7rem;"></i> Prev
                                    </a>
                                    <a href="?month=<?= date('m') ?>&year=<?= date('Y') ?>" class="nav-btn today-btn">
                                        <i class="fas fa-dot-circle" style="font-size:.7rem;"></i> Today
                                    </a>
                                    <div class="nav-selects">
                                        <form method="get" style="display:flex;gap:8px;">
                                            <select name="month" onchange="this.form.submit()">
                                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                                    <option value="<?= $m ?>" <?= ($m == $month) ? 'selected' : '' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
                                                <?php endfor; ?>
                                            </select>
                                            <select name="year" onchange="this.form.submit()">
                                                <?php for ($y = (int)date('Y') - 5; $y <= (int)date('Y') + 2; $y++): ?>
                                                    <option value="<?= $y ?>" <?= ($y == $year) ? 'selected' : '' ?>><?= $y ?></option>
                                                <?php endfor; ?>
                                            </select>
                                        </form>
                                    </div>
                                    <a href="?month=<?= $nextMonth ?>&year=<?= $nextYear ?>" class="nav-btn">
                                        Next <i class="fas fa-chevron-right" style="font-size:.7rem;"></i>
                                    </a>
                                </div>

                                <!-- ══ CALENDAR VIEW ══ -->
                                <div id="calendarView">
                                    <div class="calendar">
                                        <div class="calendar-header">
                                            <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $dl): ?>
                                                <div class="cal-head-cell"><?= $dl ?></div>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="calendar-body">
                                            <?php
                                            $startDow = (int)date('w', strtotime($firstDayOfMonth));
                                            for ($i = 0; $i < $startDow; $i++) echo '<div class="calendar-cell empty"></div>';
                                            foreach ($listRows as $row):
                                                $cls = 'calendar-cell' . ($row['isToday'] ? ' today' : '');
                                            ?>
                                            <div class="<?= $cls ?>" data-date="<?= $row['day'] ?>">
                                                <div class="date-num"><?= $row['label'] ?></div>
                                                <?= $row['cellBadge'] ?>
                                                <?= $row['cellHours'] ?>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- ══ LIST VIEW ══ -->
                                <div id="listView" style="display:none;">
                                    <div class="table-responsive">
                                        <table class="table table-bordered table-hover" id="attListTable" style="width:100%;">
                                            <thead>
                                                <tr>
                                                    <th style="width:4%;">#</th>
                                                    <th>Date</th>
                                                    <th>Day</th>
                                                    <th>Status</th>
                                                    <th>Punch In</th>
                                                    <th>Punch Out</th>
                                                    <th>Total Hours</th>
                                                    <th>Remarks</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php $sn2 = 1; foreach ($listRows as $row): ?>
                                                <tr class="<?= $row['isToday'] ? 'tr-today' : '' ?>" data-date="<?= $row['day'] ?>">
                                                    <td><?= $sn2++ ?></td>
                                                    <td style="white-space:nowrap;font-weight:600;text-align:left;"><?= date('d M Y', strtotime($row['day'])) ?></td>
                                                    <td style="color:#667085;"><?= $row['dayOfWeek'] ?></td>
                                                    <td><?= $row['cellBadge'] ?: "<span class='cal-badge dash'>—</span>" ?></td>
                                                    <td>
                                                        <?php if ($row['punchIn'] !== '-'): ?>
                                                            <span class="lp"><?= date('h:i A', strtotime('2000-01-01 ' . $row['punchIn'])) ?></span>
                                                        <?php else: ?>
                                                            <span class="lp na">—</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($row['punchOut'] !== '-'): ?>
                                                            <span class="lp"><?= date('h:i A', strtotime('2000-01-01 ' . $row['punchOut'])) ?></span>
                                                        <?php else: ?>
                                                            <span class="lp na">—</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?= ($row['hours'] !== '-') ? "<strong style='color:#4b49ac;'>{$row['hours']}</strong>" : "<span style='color:#9ca3af;'>—</span>" ?>
                                                    </td>
                                                    <td style="text-align:left;">
                                                        <?php if ($row['isLate'] && $row['lateMinutes'] > 0): ?>
                                                            <span class="note-chip late"><i class="fas fa-clock" style="font-size:.58rem;"></i> Late <?= $row['lateMinutes'] ?> min</span>
                                                        <?php endif; ?>
                                                        <?php if ($row['isRegularized']): ?>
                                                            <span class="note-chip reg"><i class="fas fa-check" style="font-size:.58rem;"></i> Regularized</span>
                                                        <?php endif; ?>
                                                        <?php if (!empty($row['leaveType'])): ?>
                                                            <span class="note-chip leave"><?= htmlspecialchars($row['leaveType']) ?></span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <!-- ══ Color Legend ══ -->
                                <div class="legend-bar">
                                    <div class="legend-item"><div class="legend-dot" style="background:#12b76a;"></div> Present</div>
                                    <div class="legend-item"><div class="legend-dot" style="background:#f79009;"></div> Present (Late)</div>
                                    <div class="legend-item"><div class="legend-dot" style="background:#2d5be3;"></div> Present (Online)</div>
                                    <div class="legend-item"><div class="legend-dot" style="background:#f04438;"></div> Absent</div>
                                    <div class="legend-item"><div class="legend-dot" style="background:#f59e0b;"></div> On Leave</div>
                                    <div class="legend-item"><div class="legend-dot" style="background:#7c3aed;"></div> Rostering Off</div>
                                    <div class="legend-item"><div class="legend-dot" style="background:#3d5a99;"></div> Weekend</div>
                                    <div class="legend-item"><div class="legend-dot" style="background:#b42318;"></div> Holiday</div>
                                    <div class="legend-item"><div class="legend-dot" style="background:#b45309;"></div> On Tour</div>
                                </div>

                            </div><!-- /card-body -->
                        </div><!-- /card -->
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ══ Day Detail Modal ══ -->
<div class="day-modal-overlay" id="dayModalOverlay">
    <div class="day-modal">
        <div class="day-modal-header">
            <div>
                <div class="modal-date" id="modalDate">—</div>
                <div class="modal-chips">
                    <span class="modal-chip" id="modalLateTag" style="display:none;">
                        <i class="fas fa-clock" style="font-size:.58rem;"></i>
                        <span id="modalLateText">Late</span>
                    </span>
                    <span class="modal-chip" id="modalRegTag" style="display:none;">
                        <i class="fas fa-check-circle" style="font-size:.58rem;"></i> Regularized
                    </span>
                </div>
            </div>
            <button class="modal-close" id="modalClose" title="Close">&times;</button>
        </div>
        <div class="day-modal-body">
            <div class="modal-row">
                <span class="label">Status</span>
                <span class="value" id="modalStatus">—</span>
            </div>
            <div class="modal-row" id="mPIRow">
                <span class="label">Punch In</span>
                <span class="value green" id="modalPunchIn">—</span>
            </div>
            <div class="modal-row" id="mPORow">
                <span class="label">Punch Out</span>
                <span class="value red" id="modalPunchOut">—</span>
            </div>
            <div class="modal-row" id="mHrRow">
                <span class="label">Total Hours</span>
                <span class="value" id="modalHours">—</span>
            </div>
            <div class="modal-row" id="mLateRow">
                <span class="label">Late By</span>
                <span class="value orange" id="modalLateBy">—</span>
            </div>
            <div class="modal-row" id="mLeaveRow">
                <span class="label">Leave Type</span>
                <span class="value" id="modalLeaveType">—</span>
            </div>
        </div>
    </div>
</div>

<script>
/* ── Data ── */
const attendanceData = <?= json_encode($attendanceData) ?>;
const exportData     = <?= json_encode($exportData) ?>;
const monthLabel     = '<?= addslashes($monthLabel) ?>';
const userName       = '<?= addslashes($userName) ?>';
const statSummary    = 'Present: <?= $statPresent ?> | Absent: <?= $statAbsent ?> | Leaves: <?= $statLeave ?> | Attendance: <?= $attendancePct ?>%';

/* ── Helpers ── */
function fmt12(t) {
    if (!t || t === '-') return '—';
    const parts = t.split(':');
    if (parts.length < 2) return t;
    const h = parts[0], m = parts[1], s = parts[2] || '00';
    let hr = parseInt(h); const ap = hr >= 12 ? 'PM' : 'AM'; hr = hr % 12 || 12;
    return `${hr.toString().padStart(2, '0')}:${m}:${s} ${ap}`;
}

/* ── View Toggle ── */
let currentView = 'calendar';
function toggleView() {
    const cal  = document.getElementById('calendarView');
    const list = document.getElementById('listView');
    const icon = document.getElementById('vIcon');
    const lbl  = document.getElementById('vLabel');
    if (currentView === 'calendar') {
        cal.style.display = 'none'; list.style.display = 'block';
        icon.className = 'fas fa-calendar-alt'; lbl.textContent = 'Calendar View';
        currentView = 'list';
    } else {
        cal.style.display = 'block'; list.style.display = 'none';
        icon.className = 'fas fa-list'; lbl.textContent = 'List View';
        currentView = 'calendar';
    }
}

/* ── Modal open ── */
function openModal(date) {
    const d = attendanceData[date]; if (!d) return;
    document.getElementById('modalDate').textContent   = d.date;
    document.getElementById('modalStatus').textContent = d.status || '—';

    /* Late tag */
    const lateTag  = document.getElementById('modalLateTag');
    const lateText = document.getElementById('modalLateText');
    if (d.isLate && d.lateMinutes > 0) {
        lateText.textContent = `Late by ${d.lateMinutes} min${d.lateMinutes !== 1 ? 's' : ''}`;
        lateTag.style.display = 'inline-flex';
    } else lateTag.style.display = 'none';

    /* Regularized tag */
    document.getElementById('modalRegTag').style.display = d.isRegularized ? 'inline-flex' : 'none';

    /* Punch rows */
    const showPunch = ['Present','Absent','On Tour','Online','Present (Online)'].includes(d.status) && d.punchIn && d.punchIn !== '-';
    document.getElementById('modalPunchIn').textContent  = fmt12(d.punchIn);
    document.getElementById('modalPunchOut').textContent = fmt12(d.punchOut);
    document.getElementById('modalHours').textContent    = (d.hours && d.hours !== '-') ? d.hours : '—';
    ['mPIRow','mPORow','mHrRow'].forEach(id => document.getElementById(id).style.display = showPunch ? 'flex' : 'none');

    /* Late by row */
    const lateRow = document.getElementById('mLateRow');
    if (d.isLate && d.lateMinutes > 0) {
        document.getElementById('modalLateBy').textContent = `${d.lateMinutes} minute${d.lateMinutes !== 1 ? 's' : ''}`;
        lateRow.style.display = 'flex';
    } else lateRow.style.display = 'none';

    /* Leave type row */
    const ltRow = document.getElementById('mLeaveRow');
    if (d.leaveType) {
        document.getElementById('modalLeaveType').textContent = d.leaveType;
        ltRow.style.display = 'flex';
    } else ltRow.style.display = 'none';

    document.getElementById('dayModalOverlay').classList.add('active');
}

/* Calendar cell clicks */
document.querySelectorAll('.calendar-cell:not(.empty)').forEach(c => c.addEventListener('click', () => openModal(c.dataset.date)));

/* List row clicks */
document.querySelectorAll('#listView tbody tr[data-date]').forEach(r => r.addEventListener('click', () => openModal(r.dataset.date)));

/* Close modal */
document.getElementById('modalClose').addEventListener('click', () => document.getElementById('dayModalOverlay').classList.remove('active'));
document.getElementById('dayModalOverlay').addEventListener('click', function(e) { if (e.target === this) this.classList.remove('active'); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') document.getElementById('dayModalOverlay').classList.remove('active'); });

/* ── Export Excel ── */
function exportExcel() {
    const head = [['#','Date','Day','Status','Punch In','Punch Out','Total Hours','Remarks']];
    const rows = exportData.map(r => [r.sn, r.date, r.day, r.status, r.pi || '—', r.po || '—', r.hours || '—', r.remarks]);
    const wb = XLSX.utils.book_new();
    const ws = XLSX.utils.aoa_to_sheet([...head, ...rows]);
    /* Column widths */
    ws['!cols'] = [{wch:4},{wch:14},{wch:6},{wch:18},{wch:10},{wch:10},{wch:12},{wch:24}];
    XLSX.utils.book_append_sheet(wb, ws, monthLabel);
    XLSX.writeFile(wb, `Attendance_${monthLabel.replace(' ','_')}.xlsx`);
}

/* ── Export PDF ── */
function exportPDF() {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ orientation: 'landscape' });
    doc.setFontSize(14);
    doc.setTextColor(75, 73, 172);
    doc.text(`My Attendance – ${monthLabel}`, 14, 14);
    doc.setFontSize(8.5);
    doc.setTextColor(100);
    doc.text(`${userName}  |  ${statSummary}`, 14, 21);
    doc.autoTable({
        startY: 26,
        head: [['#','Date','Day','Status','Punch In','Punch Out','Hours','Remarks']],
        body: exportData.map(r => [r.sn, r.date, r.day, r.status, r.pi||'—', r.po||'—', r.hours||'—', r.remarks]),
        headStyles: { fillColor: [75, 73, 172], fontSize: 8, halign: 'center' },
        bodyStyles: { fontSize: 7.5, halign: 'center' },
        columnStyles: { 1:{halign:'left'}, 7:{halign:'left'} },
        alternateRowStyles: { fillColor: [248, 249, 251] },
        styles: { cellPadding: 2.2 },
        margin: { left: 10, right: 10 }
    });
    doc.save(`Attendance_${monthLabel.replace(' ','_')}.pdf`);
}
</script>

<script src="../vendors/js/vendor.bundle.base.js"></script>
<script src="../vendors/typeahead.js/typeahead.bundle.min.js"></script>
<script src="../vendors/select2/select2.min.js"></script>
<script src="../js/template.js"></script>
<script src="../js/settings.js"></script>
<script src="../js/select2.js"></script>
<script src="../js/off-canvas.js"></script>
</body>
</html>