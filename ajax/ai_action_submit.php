<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=UTF-8');

$userId = $_SESSION['userId'] ?? $_COOKIE['userId'] ?? null;


if (empty($userId)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required. Please log in.']);
    exit;
}

require_once __DIR__ . '/../include/db_config.php';
@include_once __DIR__ . '/../include/notifications.php';


// Fetch sender details
$userRes = mysqli_query($con, "SELECT fullName, emp_id, designation, userRole FROM signup WHERE userId = '" . mysqli_real_escape_string($con, $userId) . "' LIMIT 1");
$userRow = mysqli_fetch_assoc($userRes) ?: [];
$fullName = $userRow['fullName'] ?? 'Employee';
$empId = $userRow['emp_id'] ?? $userId;
$designation = $userRow['designation'] ?? '';

// Support both JSON input and standard form POST
$inputData = json_decode(file_get_contents('php://input'), true);
if (!is_array($inputData)) {
    $inputData = $_POST;
}

$action = trim($inputData['action'] ?? '');

function sendChatbotAppNotification($con, $toUserId, $title, $msg, $type = 'general') {
    $toUserIdSafe = mysqli_real_escape_string($con, $toUserId);
    $titleSafe = mysqli_real_escape_string($con, $title);
    $msgSafe = mysqli_real_escape_string($con, $msg);
    $typeSafe = mysqli_real_escape_string($con, $type);
    mysqli_query($con, "INSERT INTO notifications (userId, title, message, type, is_read, created_at) VALUES ('$toUserIdSafe', '$titleSafe', '$msgSafe', '$typeSafe', 0, NOW())");
    
    if (function_exists('sendFCMNotification')) {
        $tokenRes = mysqli_query($con, "SELECT token FROM signup WHERE userId='$toUserIdSafe' LIMIT 1");
        if ($tokenRes && ($tRow = mysqli_fetch_assoc($tokenRes)) && !empty($tRow['token'])) {
            global $accessToken, $projectId;
            if (!empty($accessToken) && !empty($projectId)) {
                @sendFCMNotification($tRow['token'], $title, $msg, $accessToken, $projectId);
            }
        }
    }
}

// ─────────────────────────────────────────────────────────────
// 1. APPLY LEAVE
// ─────────────────────────────────────────────────────────────
if ($action === 'apply_leave') {
    $leaveType = strtoupper(trim($inputData['leave_type'] ?? 'CL'));
    $fromDate  = trim($inputData['from_date'] ?? '');
    $toDate    = trim($inputData['to_date'] ?? '');
    $reason    = trim($inputData['reason'] ?? 'Applied via AMS Assistant Form');

    if (empty($fromDate) || empty($toDate)) {
        echo json_encode(['success' => false, 'error' => 'Please provide both From and To dates.']);
        exit;
    }

    $fTs = strtotime($fromDate);
    $tTs = strtotime($toDate);
    if (!$fTs || !$tTs || $tTs < $fTs) {
        echo json_encode(['success' => false, 'error' => 'Invalid date range specified.']);
        exit;
    }

    // Sunday check & count calculation
    $cur = $fTs;
    $leaveDays = 0;
    while ($cur <= $tTs) {
        if (date('w', $cur) != 0) { // Exclude Sundays
            $leaveDays++;
        }
        $cur = strtotime('+1 day', $cur);
    }

    if ($leaveDays <= 0) {
        echo json_encode(['success' => false, 'error' => 'Selected dates only contain Sundays. Leave cannot be applied.']);
        exit;
    }

    $leaveId = 'LEV' . date('Ymd') . rand(1000, 9999);
    $safeLeaveId = mysqli_real_escape_string($con, $leaveId);
    $safeType    = mysqli_real_escape_string($con, $leaveType);
    $safeFrom    = mysqli_real_escape_string($con, $fromDate);
    $safeTo      = mysqli_real_escape_string($con, $toDate);
    $safeReason  = mysqli_real_escape_string($con, $reason);

    $leaveNameMap = [
        'CL' => 'Casual Leave',
        'SL' => 'Sick Leave',
        'PL' => 'Personal Leave',
        'TD' => 'Travel Days',
        'RO' => 'Roster Off'
    ];
    $leaveName = $leaveNameMap[$leaveType] ?? 'Leave';

    $stmt = $con->prepare("
        INSERT INTO leaves (created_date, leave_id, leave_d, leave_name, leave_type, leave_day, from_date, to_date, reason, approved_by, informed_colleagues, userId, status, updated_date)
        VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, ?, '', '', ?, 'Pending', NOW())
    ");
    $stmt->bind_param("ssssdssss", $safeLeaveId, $leaveDays, $leaveName, $safeType, $leaveDays, $safeFrom, $safeTo, $safeReason, $userId);

    if ($stmt->execute()) {
        // Notify HR and Allocators
        $hrRes = mysqli_query($con, "SELECT userId FROM signup WHERE designation IN ('hr', 'HR', 'resourceAllocator', 'admin') AND status = 'Active'");
        if ($hrRes) {
            while ($hrRow = mysqli_fetch_assoc($hrRes)) {
                sendChatbotAppNotification($con, $hrRow['userId'], "New Leave Application", "$fullName applied for $leaveDays day(s) $safeType ($fromDate to $toDate)", "leave");
            }
        }

        echo json_encode([
            'success' => true,
            'message' => "Leave application ($leaveType for $leaveDays day(s)) submitted successfully to HR and Reporting Manager.",
            'leave_id' => $leaveId,
            'details' => [
                'type' => $safeType,
                'days' => $leaveDays,
                'from' => $safeFrom,
                'to' => $safeTo,
                'status' => 'Pending'
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Database error submitting leave: ' . $con->error]);
    }
    exit;
}

// ─────────────────────────────────────────────────────────────
// 2. ALLOCATE / ASSIGN TASK
// ─────────────────────────────────────────────────────────────
if ($action === 'allocate_task') {
    $receiverId  = trim($inputData['receiver_id'] ?? '');
    $projectName = trim($inputData['project_name'] ?? '');
    $taskTitle   = trim($inputData['task_title'] ?? '');
    $taskType    = trim($inputData['task_type'] ?? 'Project work');
    $deadlineDate = trim($inputData['deadline_date'] ?? date('Y-m-d'));
    $deadlineTime = trim($inputData['deadline_time'] ?? '18:30');
    $remark      = trim($inputData['remark'] ?? 'Assigned via AMS Assistant');
    $status      = trim($inputData['status'] ?? 'In Progress');

    if (empty($receiverId) || empty($taskTitle)) {
        echo json_encode(['success' => false, 'error' => 'Assignee Employee and Task Title are required.']);
        exit;
    }

    $deadline = $deadlineDate . ' ' . $deadlineTime . ':00';

    $safeReceiverId = mysqli_real_escape_string($con, $receiverId);
    $safeProj       = !empty($projectName) ? "'" . mysqli_real_escape_string($con, $projectName) . "'" : "NULL";
    $safeTitle      = mysqli_real_escape_string($con, $taskTitle);
    $safeType       = mysqli_real_escape_string($con, $taskType);
    $safeRemark     = mysqli_real_escape_string($con, $remark);
    $safeDeadline   = mysqli_real_escape_string($con, $deadline);
    $safeStatus     = mysqli_real_escape_string($con, $status);

    $sql = "
        INSERT INTO task_allocation (allocator_id, receiver_id, task_type, project_name, task_title, remark, deadline, status, created_at, updated_at)
        VALUES ('$userId', '$safeReceiverId', '$safeType', $safeProj, '$safeTitle', '$safeRemark', '$safeDeadline', '$safeStatus', NOW(), NOW())
    ";

    if (mysqli_query($con, $sql)) {
        $taskId = mysqli_insert_id($con);
        // Notify Receiver
        sendChatbotAppNotification($con, $safeReceiverId, "New Task Assigned", "$fullName assigned you task: $taskTitle", "task");
        
        // Notify Resource Manager
        $rmRes = mysqli_query($con, "SELECT userId FROM signup WHERE designation = 'resourceAllocator' AND status = 'Active'");
        if ($rmRes) {
            while ($rm = mysqli_fetch_assoc($rmRes)) {
                sendChatbotAppNotification($con, $rm['userId'], "Task Allocated", "$fullName allocated '$taskTitle' to user ID $safeReceiverId", "task");
            }
        }

        echo json_encode([
            'success' => true,
            'message' => "Task successfully assigned to employee and logged in Task Allocation.",
            'task_id' => $taskId,
            'details' => [
                'title' => $safeTitle,
                'assignee' => $safeReceiverId,
                'deadline' => $safeDeadline,
                'status' => $safeStatus
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Database error allocating task: ' . $con->error]);
    }
    exit;
}

// ─────────────────────────────────────────────────────────────
// 3. LOG TASK (DAILY DIARY ENTRY)
// ─────────────────────────────────────────────────────────────
if ($action === 'log_task') {
    $projectName  = trim($inputData['project_name'] ?? 'General');
    $workType     = trim($inputData['work_type'] ?? 'Project Work');
    $description  = trim($inputData['task_desc'] ?? $inputData['description'] ?? '');
    $durationHours = floatval($inputData['duration_hours'] ?? 1.0);
    $remark       = trim($inputData['remark'] ?? 'Logged via AMS Assistant');

    if (empty($description)) {
        echo json_encode(['success' => false, 'error' => 'Task description is required.']);
        exit;
    }

    if ($durationHours <= 0 || $durationHours > 24) {
        $durationHours = 1.0;
    }

    $now = date('Y-m-d H:i:s');
    $startTime = date('Y-m-d H:i:s', time() - intval($durationHours * 3600));
    $durationSec = intval($durationHours * 3600);
    $manHours = gmdate('H:i:s', $durationSec);

    $safeProj    = mysqli_real_escape_string($con, $projectName);
    $safeType    = mysqli_real_escape_string($con, $workType);
    $safeTask    = mysqli_real_escape_string($con, $description);
    $safeRemark  = mysqli_real_escape_string($con, $remark);

    $sql = "
        INSERT INTO TaskEntryNew (userId, projectName, workType, task, remark, startTime, endTime, status, totalDuration)
        VALUES ('$userId', '$safeProj', '$safeType', '$safeTask', '$safeRemark', '$startTime', '$now', 'Finished', $durationSec)
    ";

    if (mysqli_query($con, $sql)) {
        $taskId = mysqli_insert_id($con);
        mysqli_query($con, "
            INSERT INTO taskStatus (taskId, resumeTime, pauseTime, manHours, status, dateTime, userId)
            VALUES ($taskId, '$startTime', '$now', '$manHours', 'Finished', '$now', '$userId')
        ");

        echo json_encode([
            'success' => true,
            'message' => "Task diary entry ($durationHours hrs on '$projectName') logged successfully.",
            'task_id' => $taskId,
            'details' => [
                'project' => $safeProj,
                'duration' => "$durationHours hrs",
                'workType' => $safeType
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Database error logging task entry: ' . $con->error]);
    }
    exit;
}

// ─────────────────────────────────────────────────────────────
// 4. APPLY TOUR
// ─────────────────────────────────────────────────────────────
if ($action === 'apply_tour') {
    $projectId = intval($inputData['project_id'] ?? 0);
    $fromDate  = trim($inputData['from_date'] ?? '');
    $toDate    = trim($inputData['to_date'] ?? '');
    $reason    = trim($inputData['reason'] ?? 'Client tour applied via AMS Assistant');

    if ($projectId <= 0 || empty($fromDate) || empty($toDate)) {
        echo json_encode(['success' => false, 'error' => 'Please select a Project and provide Tour dates.']);
        exit;
    }

    $safeFrom = mysqli_real_escape_string($con, $fromDate);
    $safeTo   = mysqli_real_escape_string($con, $toDate);
    $safeReason = mysqli_real_escape_string($con, $reason);

    // Overlapping leave check
    $chk = mysqli_query($con, "
        SELECT leave_id FROM leaves 
        WHERE userId = '$userId' AND status NOT IN ('Rejected', 'Cancelled')
          AND from_date <= '$safeTo' AND to_date >= '$safeFrom'
    ");
    if ($chk && mysqli_num_rows($chk) > 0) {
        echo json_encode(['success' => false, 'error' => 'You have an active or pending leave overlapping these tour dates. Tour cannot be applied.']);
        exit;
    }

    $now = date('Y-m-d H:i:s');
    $sql = "
        INSERT INTO tour (userID, projectId, reason, fromDate, toDate, approvedBy, status, dateTime, rejectionReason, cancelReason)
        VALUES ('$userId', $projectId, '$safeReason', '$safeFrom', '$safeTo', '', 'Approved', '$now', '', '')
    ";

    if (mysqli_query($con, $sql)) {
        $tourId = mysqli_insert_id($con);
        // Notify HR and MD
        $admins = mysqli_query($con, "SELECT userId FROM signup WHERE designation IN ('hr', 'HR', 'md', 'MD', 'admin') AND status = 'Active'");
        if ($admins) {
            while ($adm = mysqli_fetch_assoc($admins)) {
                sendChatbotAppNotification($con, $adm['userId'], "New Tour Application", "$fullName applied for Tour ($fromDate to $toDate)", "tour");
            }
        }

        echo json_encode([
            'success' => true,
            'message' => "Tour application ($fromDate to $toDate) submitted successfully and recorded.",
            'tour_id' => $tourId,
            'details' => [
                'from' => $safeFrom,
                'to' => $safeTo,
                'project_id' => $projectId,
                'status' => 'Approved'
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Database error submitting tour: ' . $con->error]);
    }
    exit;
}

// ─────────────────────────────────────────────────────────────
// 5. LATE PUNCH-IN INTIMATION
// ─────────────────────────────────────────────────────────────
if ($action === 'late_punch_in') {
    $date        = trim($inputData['date'] ?? date('Y-m-d'));
    $delayedTime = trim($inputData['delayed_time'] ?? '10:00');
    $reason      = trim($inputData['reason'] ?? 'Intimated via AMS Assistant');

    if (empty($reason)) {
        echo json_encode(['success' => false, 'error' => 'Please provide a reason for the delayed arrival.']);
        exit;
    }

    $safeDate = mysqli_real_escape_string($con, $date);
    $safeTime = mysqli_real_escape_string($con, $delayedTime);
    $safeReason = mysqli_real_escape_string($con, $reason);
    $safeEmpName = mysqli_real_escape_string($con, $fullName);
    $safeEmpId = mysqli_real_escape_string($con, $empId);

    $sql = "
        INSERT INTO late_punch_in (userId, emp_id, emp_name, delayed_time, date, reason)
        VALUES ('$userId', '$safeEmpId', '$safeEmpName', '$safeTime', '$safeDate', '$safeReason')
    ";

    if (mysqli_query($con, $sql)) {
        $intimationId = mysqli_insert_id($con);
        // Notify HR
        $hrRes = mysqli_query($con, "SELECT userId FROM signup WHERE designation IN ('hr', 'HR') AND status = 'Active'");
        if ($hrRes) {
            while ($hr = mysqli_fetch_assoc($hrRes)) {
                sendChatbotAppNotification($con, $hr['userId'], "Late Punch-In Intimation", "$fullName intimated late punch-in for $safeDate at $safeTime. Reason: $reason", "attendance");
            }
        }

        echo json_encode([
            'success' => true,
            'message' => "Late punch-in intimation submitted to HR successfully for $date at $delayedTime.",
            'id' => $intimationId,
            'details' => [
                'date' => $safeDate,
                'expected_time' => $safeTime,
                'reason' => $safeReason
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Database error saving late intimation: ' . $con->error]);
    }
    exit;
}

// ─────────────────────────────────────────────────────────────
// 6. APPLY WORK FROM HOME (WFH)
// ─────────────────────────────────────────────────────────────
if ($action === 'apply_wfh') {
    $fromDate   = trim($inputData['from_date'] ?? '');
    $toDate     = trim($inputData['to_date'] ?? '');
    $reason     = trim($inputData['reason'] ?? '');
    $approvedBy = trim($inputData['approved_by'] ?? '');

    if (empty($fromDate) || empty($toDate)) {
        echo json_encode(['success' => false, 'error' => 'Please provide both From Date and To Date.']);
        exit;
    }

    if (empty($reason)) {
        echo json_encode(['success' => false, 'error' => 'Please enter a valid reason for Work From Home.']);
        exit;
    }

    $fTs = strtotime($fromDate);
    $tTs = strtotime($toDate);
    if (!$fTs || !$tTs || $tTs < $fTs) {
        echo json_encode(['success' => false, 'error' => 'Invalid date range: To Date cannot be earlier than From Date.']);
        exit;
    }

    $todayStr = date('Y-m-d');
    if ($fromDate < $todayStr) {
        echo json_encode(['success' => false, 'error' => 'You cannot apply for Work From Home on past dates.']);
        exit;
    }

    $safeUserId = mysqli_real_escape_string($con, $userId);

    // Auto-select approver if not provided
    if (empty($approvedBy) || $approvedBy === '0') {
        $appQ = mysqli_query($con, "
            SELECT userId FROM signup 
            WHERE status = 'Active' AND userId != '$safeUserId' 
              AND (LOWER(designation) IN ('hr', 'admin') OR LOWER(userRole) = 'admin')
            ORDER BY CASE WHEN LOWER(designation) = 'hr' THEN 1 ELSE 2 END 
            LIMIT 1
        ");
        if ($appQ && ($appRow = mysqli_fetch_assoc($appQ))) {
            $approvedBy = $appRow['userId'];
        }
    }

    if (empty($approvedBy)) {
        echo json_encode(['success' => false, 'error' => 'No active HR or Admin approver is currently available. Please contact administration.']);
        exit;
    }

    $safeFromDate   = mysqli_real_escape_string($con, $fromDate);
    $safeToDate     = mysqli_real_escape_string($con, $toDate);
    $safeReason     = mysqli_real_escape_string($con, $reason);
    $safeApprovedBy = mysqli_real_escape_string($con, $approvedBy);

    // Overlap check with existing WFH requests
    $dupQ = mysqli_query($con, "
        SELECT wfh_id FROM wfhRequests 
        WHERE userid = '$safeUserId' 
          AND status NOT IN ('Rejected', 'Cancelled')
          AND fromDate <= '$safeToDate' AND toDate >= '$safeFromDate'
        LIMIT 1
    ");
    if ($dupQ && mysqli_num_rows($dupQ) > 0) {
        echo json_encode(['success' => false, 'error' => 'You already have an active or pending Work From Home request during this date range.']);
        exit;
    }

    // Overlap check with active leaves
    $leaveQ = mysqli_query($con, "
        SELECT leave_id FROM leaves 
        WHERE userId = '$safeUserId' 
          AND status NOT IN ('Rejected', 'Cancelled')
          AND leave_name NOT LIKE '%Auto Leave%'
          AND from_date <= '$safeToDate' AND to_date >= '$safeFromDate'
        LIMIT 1
    ");
    if ($leaveQ && mysqli_num_rows($leaveQ) > 0) {
        echo json_encode(['success' => false, 'error' => 'You have an active or pending Leave during this date range. WFH cannot overlap with leave.']);
        exit;
    }

    $insQuery = "
        INSERT INTO wfhRequests (
            userid, fromDate, toDate, wfhDate, reason, documentPath, status, createdDate, updatedDate, approvedBy, rejectionReason
        ) VALUES (
            '$safeUserId', '$safeFromDate', '$safeToDate', '$safeFromDate', '$safeReason', '-', 'Pending', NOW(), NOW(), '$safeApprovedBy', NULL
        )
    ";

    if (mysqli_query($con, $insQuery)) {
        $wfhId = mysqli_insert_id($con);

        // Send app notification to selected approver
        sendChatbotAppNotification(
            $con,
            $approvedBy,
            "New WFH Request: $fullName",
            "$fullName requested Work From Home from $fromDate to $toDate. Reason: $reason",
            "wfh_request"
        );

        echo json_encode([
            'success' => true,
            'message' => "Work From Home request submitted successfully ($fromDate to $toDate). Awaiting HR/Admin approval.",
            'wfh_id' => 'WFH-' . $wfhId,
            'details' => [
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'reason' => $reason,
                'status' => 'Pending'
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Database error submitting WFH request: ' . mysqli_error($con)]);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => "Action '$action' not recognized."]);
