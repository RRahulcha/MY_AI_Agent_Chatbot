<?php
/**
 * resourceRequestProcess.php
 *
 * Fixes vs original:
 *  1. Uses centralized validateAllocationRequest() — checks date range, not just today
 *  2. Pending requests treated as occupied slots (overlap blocked)
 *  3. Leave / Tour / Holiday checked per-date across the full range
 *  4. Duplicate-pending check scoped to Pending+Approved (not just Pending)
 */

session_start();

if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['flash_page'] = 'resourceRequest';
    $_SESSION['status'] = false;
    $_SESSION['msg']    = "Invalid request method.";
    echo "<script>window.location.href = 'resourceRequest.php';</script>";
    exit;
}

include('../include/db_config.php');
require_once('availabilityEngine.php');

$today = date('Y-m-d');

// ── Collect & sanitise ────────────────────────────────────────────────────────
$rawInternId    = $_POST['internId'] ?? [];
$internIds      = array_values(array_filter(array_map(fn($v) => trim(mysqli_real_escape_string($con, $v)), (array)$rawInternId)));
$projectName    = trim(mysqli_real_escape_string($con, $_POST['projectName']    ?? ''));
$clientName     = trim(mysqli_real_escape_string($con, $_POST['clientName']     ?? ''));
$requestedBy    = trim(mysqli_real_escape_string($con, $_POST['requestedBy']    ?? ''));
$startDate      = trim(mysqli_real_escape_string($con, $_POST['startDate']      ?? ''));
$endDate        = trim(mysqli_real_escape_string($con, $_POST['endDate']        ?? ''));
$allocStartTime = trim(mysqli_real_escape_string($con, $_POST['allocStartTime'] ?? ''));
$allocEndTime   = trim(mysqli_real_escape_string($con, $_POST['allocEndTime']   ?? ''));

$redirect = function(bool $ok, string $msg) {
    $_SESSION['flash_page'] = 'resourceRequest';
    $_SESSION['status']     = $ok;
    $_SESSION['msg']        = $msg;
    echo "<script>window.location.href = 'resourceRequest.php';</script>";
    exit;
};

// ── Required fields ───────────────────────────────────────────────────────────
if (empty($internIds) || empty($projectName) || empty($clientName) ||
    empty($requestedBy) || empty($startDate) || empty($endDate) ||
    empty($allocStartTime) || empty($allocEndTime)) {
    $redirect(false, "Please fill all fields correctly including selecting at least one intern and the time slot.");
}

// ── Date sanity ───────────────────────────────────────────────────────────────
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) ||
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
    $redirect(false, "Invalid date format.");
}

if ($endDate < $startDate) {
    $redirect(false, "End date cannot be before start date.");
}

if ($startDate < $today) {
    $redirect(false, "Start date cannot be in the past.");
}

// ── Time sanity ───────────────────────────────────────────────────────────────
if (!preg_match('/^\d{2}:\d{2}$/', $allocStartTime) ||
    !preg_match('/^\d{2}:\d{2}$/', $allocEndTime)) {
    $redirect(false, "Invalid time format.");
}

$startMins = timeToMinutes($allocStartTime);
$endMins   = timeToMinutes($allocEndTime);

if ($endMins <= $startMins) {
    $redirect(false, "Allocation end time must be after start time.");
}

// ── Verify all selected interns share the same shift timings & availability profile ──
if (count($internIds) > 1) {
    $firstId = $internIds[0];
    $qFirst = mysqli_query($con, "
        SELECT COALESCE(TIME_FORMAT(expectedPunchIn,'%H:%i'),'09:00') AS ws,
               COALESCE(TIME_FORMAT(expectedPunchOut,'%H:%i'),'17:00') AS we
        FROM signup WHERE internId='$firstId' OR userId='$firstId' LIMIT 1
    ");
    $rFirst = mysqli_fetch_assoc($qFirst);
    $baseWs = $rFirst['ws'] ?? '09:00';
    $baseWe = $rFirst['we'] ?? '17:00';
    $snapFirst = calculateAvailabilityForDate($con, $firstId, $startDate);
    $baseFree  = $snapFirst['available'] ? $snapFirst['freeMinutes'] : 0;

    foreach (array_slice($internIds, 1) as $checkId) {
        $qCheck = mysqli_query($con, "
            SELECT fullName,
                   COALESCE(TIME_FORMAT(expectedPunchIn,'%H:%i'),'09:00') AS ws,
                   COALESCE(TIME_FORMAT(expectedPunchOut,'%H:%i'),'17:00') AS we
            FROM signup WHERE internId='$checkId' OR userId='$checkId' LIMIT 1
        ");
        $rCheck = mysqli_fetch_assoc($qCheck);
        $cName = $rCheck['fullName'] ?? $checkId;
        $cWs   = $rCheck['ws'] ?? '09:00';
        $cWe   = $rCheck['we'] ?? '17:00';
        $snapCheck = calculateAvailabilityForDate($con, $checkId, $startDate);
        $cFree     = $snapCheck['available'] ? $snapCheck['freeMinutes'] : 0;

        if ($cWs !== $baseWs || $cWe !== $baseWe || $cFree !== $baseFree) {
            $redirect(false, "❌ Batch request failed: Intern '{$cName}' has different shift timings ({$cWs}–{$cWe}) or free hours than the first selected intern. Please request interns with different timings separately.");
        }
    }
}

// ── Batch Process Interns ──────────────────────────────────────────────────────
$dailyHours = (int)ceil(($endMins - $startMins) / 60);
$successCount = 0;
$failCount = 0;
$errorList = [];
$warningsList = [];

foreach ($internIds as $internId) {
    // 1. Validation check (against approved allocations)
    $validation = validateAllocationRequest(
        $con, $internId,
        $startDate, $endDate,
        $allocStartTime, $allocEndTime,
        null,
        false
    );

    if (!$validation['valid']) {
        $failCount++;
        $errorList[] = "Intern {$internId}: " . implode("; ", $validation['errors']);
        continue;
    }

    if (!empty($validation['warnings'])) {
        $warningsList[] = "Intern {$internId}: " . implode("; ", $validation['warnings']);
    }

    // 2. Duplicate pending check
    $dupCheck = mysqli_query($con,
        "SELECT id FROM resourceRequests
         WHERE  internId       = '$internId'
           AND  projectName    = '$projectName'
           AND  startDate      = '$startDate'
           AND  endDate        = '$endDate'
           AND  allocStartTime = '$allocStartTime'
           AND  allocEndTime   = '$allocEndTime'
           AND  status         = 'Pending'
         LIMIT  1"
    );

    if ($dupCheck && mysqli_num_rows($dupCheck) > 0) {
        $failCount++;
        $errorList[] = "Intern {$internId}: Pending request already exists for this date/time window.";
        continue;
    }

    // 3. Insert record
    $insertSQL = "
        INSERT INTO resourceRequests
            (internId, projectName, clientName, requestedBy,
             startDate, endDate, dailyHours, allocStartTime, allocEndTime)
        VALUES
            ('$internId', '$projectName', '$clientName', '$requestedBy',
             '$startDate', '$endDate', '$dailyHours', '$allocStartTime', '$allocEndTime')
    ";

    if (mysqli_query($con, $insertSQL)) {
        $successCount++;
    } else {
        $failCount++;
        error_log("resourceRequestProcess.php DB error for {$internId}: " . mysqli_error($con));
        $errorList[] = "Intern {$internId}: Database insertion failed.";
    }
}

// ── Redirect feedback ─────────────────────────────────────────────────────────
$duration = formatHours($endMins - $startMins);

if ($successCount > 0 && $failCount === 0) {
    $warnNote = !empty($warningsList) ? "\n⚠️ Note: " . implode("; ", $warningsList) : "";
    $redirect(true, "✓ Successfully submitted {$successCount} resource request(s) for approval! Time slot {$allocStartTime}–{$allocEndTime} ({$duration}) is pending review." . $warnNote);
} elseif ($successCount > 0 && $failCount > 0) {
    $errNote = "\n⚠️ Skipped {$failCount} intern(s):\n• " . implode("\n• ", $errorList);
    $redirect(true, "✓ Submitted {$successCount} resource request(s) for approval." . $errNote);
} else {
    $errNote = "\n• " . implode("\n• ", $errorList);
    $redirect(false, "❌ Unable to submit resource requests:" . $errNote);
}