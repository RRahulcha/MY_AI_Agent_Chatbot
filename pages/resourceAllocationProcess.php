<?php
session_start();

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
       || (!empty($_POST['isAjax']) && $_POST['isAjax'] == '1');

function respond(bool $status, string $msg, array $extra = []) {
    global $isAjax;
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(array_merge([
            'status' => $status,
            'msg'    => $msg
        ], $extra));
        exit;
    } else {
        $_SESSION['status'] = $status;
        $_SESSION['msg']    = $msg;
        echo "<script>window.location.href = 'resourceApprovals.php';</script>";
        exit;
    }
}

// Auth check
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    respond(false, "Unauthorized: Please log in.");
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, "Invalid request method.");
}

include('../include/db_config.php');
require_once('availabilityEngine.php');

$userRole  = strtolower(trim($_SESSION['userRole'] ?? ''));
$userDesig = strtolower(trim($_SESSION['designation'] ?? ''));
$allowedAllocProc = ['resourceallocator', 'resource_allocator', 'resource allocator', 'resourcemanager', 'resource_manager', 'resourcehead', 'resource_head', 'admin', 'superadmin'];
if (!in_array($userRole, $allowedAllocProc) && !in_array($userDesig, $allowedAllocProc)) {
    respond(false, "Unauthorized: Only Resource Managers can approve or reject requests.");
}

// Collect inputs
$requestId  = intval($_POST['requestId']  ?? 0);
$action     = trim($_POST['action']       ?? '');
$approvedBy = trim(mysqli_real_escape_string($con, $_POST['approvedBy'] ?? $_SESSION['userId']));

// Basic validation
if ($requestId <= 0 || !in_array($action, ['approve', 'reject'])) {
    respond(false, "Invalid request parameters. Please try again.");
}

// Fetch the request — must exist and still be Pending
$fetchResult = mysqli_query($con,
    "SELECT * FROM resourceRequests WHERE id='$requestId' AND status='Pending'"
);

if (!$fetchResult || mysqli_num_rows($fetchResult) === 0) {
    respond(false, "Request not found or already processed.");
}

$request = mysqli_fetch_assoc($fetchResult);

// Get approver's name for notification
$approverRow = mysqli_fetch_assoc(mysqli_query($con, "SELECT fullName FROM signup WHERE userId='$approvedBy'"));
$approverName = $approverRow ? $approverRow['fullName'] : 'Admin';

// Get intern name for notification
$internId = mysqli_real_escape_string($con, $request['internId']);
$internNameRow = mysqli_fetch_assoc(mysqli_query($con,
    "SELECT fullName FROM interns WHERE internId='$internId'
     UNION
     SELECT fullName FROM signup WHERE internId='$internId' LIMIT 1"
));
$internName = $internNameRow ? $internNameRow['fullName'] : $request['internId'];

// ══════════════════════════════════════════════════════════════════════════
//  APPROVE
// ══════════════════════════════════════════════════════════════════════════
if ($action === 'approve') {

    // RM may have adjusted dates and time slot in the approval dashboard
    $newStartDate = trim(mysqli_real_escape_string($con, $_POST['newStartDate'] ?? ''));
    $newEndDate   = trim(mysqli_real_escape_string($con, $_POST['newEndDate']   ?? ''));
    $newAllocST   = trim(mysqli_real_escape_string($con, $_POST['newAllocST']   ?? ''));
    $newAllocET   = trim(mysqli_real_escape_string($con, $_POST['newAllocET']   ?? ''));

    // Fall back to original requested dates & times if RM didn't override
    $finalStartDate = (preg_match('/^\d{4}-\d{2}-\d{2}$/', $newStartDate)) ? $newStartDate : $request['startDate'];
    $finalEndDate   = (preg_match('/^\d{4}-\d{2}-\d{2}$/', $newEndDate))   ? $newEndDate   : $request['endDate'];
    $finalAllocST   = ($newAllocST !== '') ? $newAllocST : ($request['allocStartTime'] ?? '');
    $finalAllocET   = ($newAllocET !== '') ? $newAllocET : ($request['allocEndTime']   ?? '');

    // Date & Time sanity guards
    if ($finalEndDate < $finalStartDate) {
        respond(false, "❌ Cannot approve: End date cannot be before start date.");
    }

    if (!$finalAllocST || !$finalAllocET) {
        respond(false, "❌ Cannot approve: Allocation time slot is required.");
    }
    $stMins = timeToMinutes($finalAllocST);
    $etMins = timeToMinutes($finalAllocET);
    if ($stMins === false || $etMins === false || $etMins <= $stMins) {
        respond(false, "❌ Cannot approve: End time must be after start time.");
    }

    // ✅ Full pre-approval validation: check against approved allocations, leaves, holidays, Sundays.
    $validation = validateAllocationRequest(
        $con,
        $internId,
        $finalStartDate,
        $finalEndDate,
        $finalAllocST,
        $finalAllocET,
        null,  // no excludeAllocationId
        false  // includePending = false
    );

    if (!$validation['valid']) {
        respond(false, "❌ Cannot approve — conflicts detected: " . implode(" | ", $validation['errors']));
    }

    // Insert into internAllocations with the final (possibly RM-adjusted) dates & time slot
    $safeProject   = mysqli_real_escape_string($con, $request['projectName']);
    $safeClient    = mysqli_real_escape_string($con, $request['clientName']);
    $safeStartDate = mysqli_real_escape_string($con, $finalStartDate);
    $safeEndDate   = mysqli_real_escape_string($con, $finalEndDate);
    $safeST        = mysqli_real_escape_string($con, $finalAllocST);
    $safeET        = mysqli_real_escape_string($con, $finalAllocET);

    // Recalculate dailyHours from final time
    $finalDailyHours = (int)ceil(($etMins - $stMins) / 60);

    $insertAlloc = "
        INSERT INTO internAllocations
            (internId, projectName, clientName, startDate, endDate, dailyHours, allocStartTime, allocEndTime, allocatedBy)
        VALUES (
            '$internId',
            '$safeProject',
            '$safeClient',
            '$safeStartDate',
            '$safeEndDate',
            '$finalDailyHours',
            '$safeST',
            '$safeET',
            '$approvedBy'
        )
    ";

    $allocResult = mysqli_query($con, $insertAlloc);

    if (!$allocResult) {
        error_log('resourceAllocationProcess.php ALLOC error: ' . mysqli_error($con));
        respond(false, "Failed to allocate intern. Please try again.");
    }

    // Update resourceRequests status → Approved, and persist final dates & time slot
    $updateReq = mysqli_query($con,
        "UPDATE resourceRequests
         SET status='Approved',
             startDate='$safeStartDate',
             endDate='$safeEndDate',
             allocStartTime='$safeST',
             allocEndTime='$safeET',
             dailyHours='$finalDailyHours'
         WHERE id='$requestId'"
    );

    if ($updateReq) {
        $warningMsg = '';
        if (!empty($validation['warnings'])) {
            $warningMsg = " Note: " . implode("; ", $validation['warnings']);
        }

        $approvedSlot = to12hr(substr($finalAllocST, 0, 5)) . ' – ' . to12hr(substr($finalAllocET, 0, 5));
        $dateStr = date('d M Y', strtotime($finalStartDate)) . ' to ' . date('d M Y', strtotime($finalEndDate));
        $successMsg = "✅ Request approved. {$internName} allocated to '{$request['projectName']}' ({$dateStr}, {$approvedSlot}, {$finalDailyHours} hr/day)." . $warningMsg;

        // Notify requester
        $notifyUserId = mysqli_real_escape_string($con, $request['requestedBy']);
        $notifyTitle  = "Resource Request Approved";
        $notifyMsg    = mysqli_real_escape_string($con,
            "Your resource request for $internName on project '{$request['projectName']}' ({$finalStartDate} to {$finalEndDate}, {$finalAllocST}–{$finalAllocET}) has been approved by $approverName."
        );
        mysqli_query($con,
            "INSERT INTO notifications (userId, title, message, type, is_read, created_at)
             VALUES ('$notifyUserId', '$notifyTitle', '$notifyMsg', 'resource', 0, NOW())"
        );

        respond(true, $successMsg, ['action' => 'approve', 'requestId' => $requestId]);
    } else {
        error_log('resourceAllocationProcess.php UPDATE error: ' . mysqli_error($con));
        respond(false, "Allocation saved but status update failed. Please check manually.");
    }


// ══════════════════════════════════════════════════════════════════════════
//  REJECT
// ══════════════════════════════════════════════════════════════════════════
} elseif ($action === 'reject') {

    $updateReq = mysqli_query($con,
        "UPDATE resourceRequests SET status='Rejected' WHERE id='$requestId'"
    );

    if ($updateReq) {
        // Send notification to the requester
        $notifyUserId = mysqli_real_escape_string($con, $request['requestedBy']);
        $notifyTitle  = "Resource Request Rejected";
        $notifyMsg    = mysqli_real_escape_string($con,
            "Your resource request for $internName on project '{$request['projectName']}' ({$request['startDate']} to {$request['endDate']}) has been rejected by $approverName."
        );
        mysqli_query($con,
            "INSERT INTO notifications (userId, title, message, type, is_read, created_at)
             VALUES ('$notifyUserId', '$notifyTitle', '$notifyMsg', 'resource', 0, NOW())"
        );

        respond(true, "Request has been rejected.", ['action' => 'reject', 'requestId' => $requestId]);
    } else {
        error_log('resourceAllocationProcess.php REJECT error: ' . mysqli_error($con));
        respond(false, "Failed to reject request. Please try again.");
    }
}

respond(false, "Unknown action.");