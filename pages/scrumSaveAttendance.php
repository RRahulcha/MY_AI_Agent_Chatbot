<?php
/**
 * scrumSaveAttendance.php
 * Called via fetch() from scrumCall.php
 * Marks attendance for interns based on scrum host input.
 * 
 * POST params:
 *   date      — date string (Y-m-d)
 *   markedBy  — userId of the scrum host
 *   changes   — JSON: { "internId": "present"|"absent", ... }
 */

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['userId'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

include('../include/db_config.php');

$date     = isset($_POST['date'])     ? mysqli_real_escape_string($con, $_POST['date'])     : date('Y-m-d');
$markedBy = isset($_POST['markedBy']) ? mysqli_real_escape_string($con, $_POST['markedBy']) : '';
$changes  = isset($_POST['changes'])  ? json_decode($_POST['changes'], true) : [];

if (empty($changes)) {
    echo json_encode(['status' => 'error', 'message' => 'No changes provided']);
    exit;
}

$errors = [];

foreach ($changes as $internId => $status) {
    $internId = mysqli_real_escape_string($con, $internId);
    $status   = mysqli_real_escape_string($con, $status);

    // Resolve internId → userId (attendance table uses userId)
    $userRow = mysqli_fetch_assoc(mysqli_query($con, "SELECT userId FROM signup WHERE internId = '$internId' LIMIT 1"));
    if (!$userRow) {
        $errors[] = "Could not find userId for internId: $internId";
        continue;
    }
    $userId = mysqli_real_escape_string($con, $userRow['userId']);

    // Check if attendance record exists for today
    $existing = mysqli_fetch_assoc(mysqli_query($con, "
        SELECT id, punchInTime FROM attendance
        WHERE userId = '$userId' AND date = '$date'
        LIMIT 1
    "));

    if ($status === 'absent') {
        if ($existing) {
            // If punched in but marked absent by scrum host — add a remark (don't delete the record)
            mysqli_query($con, "
                UPDATE attendance
                SET remark = CONCAT(COALESCE(remark, ''), ' [Marked absent by Scrum Host: $markedBy]'),
                    modifiedUserId = '$markedBy'
                WHERE userId = '$userId' AND date = '$date'
            ");
        }
        // If no record exists, optionally insert an absent record
        // Uncomment below if you want an explicit absent row:
        /*
        else {
            mysqli_query($con, "
                INSERT INTO attendance (date, userId, punchInTime, punchOutTime, manHours, status, remark, dateTime, lateMark)
                VALUES ('$date', '$userId', '00:00:00', '00:00:00', '00:00:00', 'Absent', 'Marked absent by Scrum Host: $markedBy', NOW(), 'N')
            ");
        }
        */
    } elseif ($status === 'present') {
        if (!$existing) {
            // Create a punch-in record for this intern marked present by scrum host
            mysqli_query($con, "
                INSERT INTO attendance (date, userId, punchInTime, punchOutTime, manHours, status, remark, dateTime, lateMark)
                VALUES ('$date', '$userId', NOW(), '00:00:00', '00:00:00', 'Present', 'Marked present by Scrum Host: $markedBy', NOW(), 'N')
            ");
            if (mysqli_error($con)) {
                $errors[] = "Insert failed for $internId: " . mysqli_error($con);
            }
        } else {
            // Already has attendance, just update remark
            mysqli_query($con, "
                UPDATE attendance
                SET remark = CONCAT(COALESCE(remark, ''), ' [Confirmed present by Scrum Host: $markedBy]'),
                    modifiedUserId = '$markedBy'
                WHERE userId = '$userId' AND date = '$date'
            ");
        }
    }
}

if (!empty($errors)) {
    echo json_encode(['status' => 'partial', 'message' => 'Saved with some errors', 'errors' => $errors]);
} else {
    echo json_encode(['status' => 'success', 'message' => 'Attendance saved']);
}