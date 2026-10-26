<?php
session_start();
include('../include/db_config.php');

$userId = $_SESSION['userId'] ?? '';
$id = intval($_POST['id'] ?? 0);
$rawDate = $_POST['date'] ?? '';

if (empty($userId) || !$id || empty($rawDate)) {
    echo "error";
    exit;
}

$cancelDate = date("Y-m-d", strtotime($rawDate));

$q = mysqli_query($con, "SELECT * FROM leaves WHERE id = '$id' AND userId = '$userId'");
if (!$q || mysqli_num_rows($q) === 0) {
    echo "error";
    exit;
}

$r = mysqli_fetch_assoc($q);

$from = $r['from_date']; 
$to = $r['to_date'];
$rawType = !empty($r['leave_type']) ? $r['leave_type'] : ($r['leave_name'] ?? '');
$status = $r['status'];

$columnMap = [
    'SL' => 'SL', 'Sick Leave' => 'SL',
    'CL' => 'CL', 'Casual Leave' => 'CL',
    'PL' => 'PL', 'Privilege / Earned Leave' => 'PL', 'Personal Leave' => 'PL', 'Privilege Leave' => 'PL', 'Earned Leave' => 'PL',
    'RO' => 'RO', 'Rostering Off' => 'RO',
    'TD' => 'TD', 'Tour Duty' => 'TD'
];
$signupCol = $columnMap[$rawType] ?? '';

// Refund 1 day balance if leave was approved (excluding Sundays)
if ($status === 'Approved' && !empty($signupCol)) {
    if (date('N', strtotime($cancelDate)) != 7) {
        mysqli_query($con, "UPDATE signup SET `$signupCol` = `$signupCol` + 1 WHERE userId='$userId'");
    }
}

/** CASES:
1️⃣ Cancel date = from_date (and cancelDate < to_date) -> advance from_date by 1 day
2️⃣ Cancel date = to_date (and cancelDate > from_date) -> reduce to_date by 1 day
3️⃣ Cancel date between from_date and to_date -> split into two leave entries
4️⃣ Single-day leave -> cancel the whole leave entry
*/

// CASE 1 — cancel first day
if ($cancelDate == $from && $cancelDate < $to) {
    $nextDate = date('Y-m-d', strtotime('+1 day', strtotime($from)));
    $res = mysqli_query($con, "
        UPDATE leaves 
        SET from_date = '$nextDate', updated_date = NOW()
        WHERE id = '$id' AND userId = '$userId'
    ");
    echo $res ? "success" : "error";
    exit;
}

// CASE 2 — cancel last day
if ($cancelDate == $to && $cancelDate > $from) {
    $prevDate = date('Y-m-d', strtotime('-1 day', strtotime($to)));
    $res = mysqli_query($con, "
        UPDATE leaves 
        SET to_date = '$prevDate', updated_date = NOW()
        WHERE id = '$id' AND userId = '$userId'
    ");
    echo $res ? "success" : "error";
    exit;
}

// CASE 3 — cancel middle day
if ($cancelDate > $from && $cancelDate < $to) {
    $leaveId1 = uniqid('LV_', true);
    $leaveId2 = uniqid('LV_', true);
    $leaveName = mysqli_real_escape_string($con, $r['leave_name'] ?? '');
    $leaveD = mysqli_real_escape_string($con, $r['leave_d'] ?? 'N/A');
    $leaveTypeEsc = mysqli_real_escape_string($con, $r['leave_type'] ?? '');
    $leaveDay = mysqli_real_escape_string($con, $r['leave_day'] ?? 'Full Day');
    $reason = mysqli_real_escape_string($con, $r['reason'] ?? '');
    $approvedBy = mysqli_real_escape_string($con, $r['approved_by'] ?? '');
    $informed = mysqli_real_escape_string($con, $r['informed_colleagues'] ?? '');
    $interviewLeave = !empty($r['interview_leave']) ? "'" . mysqli_real_escape_string($con, $r['interview_leave']) . "'" : "NULL";
    $docPath = !empty($r['document_path']) ? "'" . mysqli_real_escape_string($con, $r['document_path']) . "'" : "NULL";
    $currStatus = mysqli_real_escape_string($con, $r['status'] ?? 'Pending');

    $firstEnd = date('Y-m-d', strtotime('-1 day', strtotime($cancelDate)));
    $secondStart = date('Y-m-d', strtotime('+1 day', strtotime($cancelDate)));

    // First part → from → cancelDate-1
    mysqli_query($con, "
        INSERT INTO leaves (leave_id, leave_d, leave_name, leave_type, leave_day, from_date, to_date, reason, approved_by, informed_colleagues, interview_leave, document_path, userId, status, created_date, updated_date)
        VALUES ('$leaveId1', '$leaveD', '$leaveName', '$leaveTypeEsc', '$leaveDay', '$from', '$firstEnd', '$reason', '$approvedBy', '$informed', $interviewLeave, $docPath, '$userId', '$currStatus', NOW(), NOW())
    ");

    // Second part → cancelDate+1 → to
    mysqli_query($con, "
        INSERT INTO leaves (leave_id, leave_d, leave_name, leave_type, leave_day, from_date, to_date, reason, approved_by, informed_colleagues, interview_leave, document_path, userId, status, created_date, updated_date)
        VALUES ('$leaveId2', '$leaveD', '$leaveName', '$leaveTypeEsc', '$leaveDay', '$secondStart', '$to', '$reason', '$approvedBy', '$informed', $interviewLeave, $docPath, '$userId', '$currStatus', NOW(), NOW())
    ");

    // Soft delete original row
    mysqli_query($con, "UPDATE leaves SET status='Cancelled', updated_date=NOW() WHERE id='$id' AND userId='$userId'");

    echo "success";
    exit;
}

// Single-day row
$res = mysqli_query($con, "UPDATE leaves SET status='Cancelled', updated_date=NOW() WHERE id='$id' AND userId='$userId'");
echo $res ? "success" : "error";
?>
