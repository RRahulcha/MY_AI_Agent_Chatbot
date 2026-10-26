<?php
session_start();
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}

require_once('../include/sweetAlert.php');
include('../include/db_config.php');
include('../include/r2_config.php');
require_once('../include/notification_helper.php');

$userId = $_SESSION['userId'];

// ── Punch-in check ───────────────────────────────────────────────────────────
$today = date("Y-m-d");

$checkPunch = mysqli_query($con, "
    SELECT * FROM attendance
    WHERE  userId      = '$userId'
      AND  date        = '$today'
      AND  punchInTime IS NOT NULL
");
$hasPunchedIn = mysqli_num_rows($checkPunch) > 0;


// ================= APPLY LEAVE =================
if (isset($_POST['apply'])) {

    $created_date = date("Y-m-d H:i:s");

    // ── Sanitize inputs ──────────────────────────────────────────────────────
    $leave_type = mysqli_real_escape_string($con, $_POST['leave_type']);

    $interview_leave = NULL;
    if ($leave_type === 'CL') {
        if (!isset($_POST['interview_leave']) || empty($_POST['interview_leave'])) {
            setSession(false, "Interview Leave selection is required for Casual Leave.");
            echo "<script>window.location.href='apply_leave.php';</script>";
            exit;
        }
        $interview_leave = mysqli_real_escape_string($con, $_POST['interview_leave']);
    }

    $from_date = date('Y-m-d', strtotime($_POST['from_date']));
    $to_date = date('Y-m-d', strtotime($_POST['to_date']));
    $reason = mysqli_real_escape_string($con, $_POST['reason']);

    // ── approved_by: not required for RO (auto-approved) ────────────────────
    $approved_by = ($leave_type === 'RO')
        ? ''
        : mysqli_real_escape_string($con, $_POST['approved_by'] ?? '');

    $informed_colleague = mysqli_real_escape_string($con, $_POST['informed_colleagues']);

    // RO is excluded — it needs no balance column, it auto-approves by roster rule
    $balanceBasedLeaves = ['SL', 'CL', 'PL', 'TD'];

    // ── Passed dates restriction ─────────────────────────────────────────────
    if ($from_date < $today) {
        setSession(false, "You cannot apply for leave on passed dates.");
        echo "<script>window.location.href='apply_leave.php';</script>";
        exit;
    }

    // ── No Sundays allowed on start or end date ──────────────────────────────
    if (date('N', strtotime($from_date)) == 7 || date('N', strtotime($to_date)) == 7) {
        setSession(false, "You cannot apply for leave on Sundays.");
        echo "<script>window.location.href='apply_leave.php';</script>";
        exit;
    }

    // ── Punch-in restriction ─────────────────────────────────────────────────
    if ($hasPunchedIn && $from_date == $today) {
        setSession(false, "You have already PUNCHED IN today. You cannot apply leave for today.");
        echo "<script>window.location.href='apply_leave.php';</script>";
        exit;
    }

    // ── RO must be on Saturday(s) only ───────────────────────────────────────
    if ($leave_type === 'RO') {
        $s = strtotime($from_date);
        $e = strtotime($to_date);
        for ($d = $s; $d <= $e; $d += 86400) {
            if (date('N', $d) != 6) { // ISO: 6 = Saturday
                setSession(false, "Rostering Off can only be applied on Saturdays.");
                echo "<script>window.location.href='apply_leave.php';</script>";
                exit;
            }
        }
    }
    

    // ── Leave name map ───────────────────────────────────────────────────────
    $leave_names = [
        'SL' => 'Sick Leave',
        'PL' => 'Privilege / Earned Leave',
        'CL' => 'Casual Leave',
        'TD' => 'Tour Day',
        'RO' => 'Rostering Off'
    ];
    $leave_name = $leave_names[$leave_type] ?? 'Other Leave';

    // ── Date span (excluding Sundays) ────────────────────────────────────────
    $start_date_obj = new DateTime($from_date);
    $end_date_obj = new DateTime($to_date);

    $date_diff_days = 0;
    $s_time = strtotime($from_date);
    $e_time = strtotime($to_date);
    for ($d = $s_time; $d <= $e_time; $d += 86400) {
        if (date('N', $d) != 7) { // 7 = Sunday
            $date_diff_days++;
        }
    }

    // ── Overlap check (excluding system auto-leaves) ─────────────────────────
   
    $check_query = mysqli_query($con, "
        SELECT id FROM leaves
        WHERE  userId  = '$userId'
          AND  status NOT IN ('Cancelled', 'Rejected')
          AND  leave_name NOT LIKE '%Auto Leave%'
          AND  leave_type != 'Auto'
          AND  from_date <= '$to_date'
          AND  to_date   >= '$from_date'
    ");
    if (mysqli_num_rows($check_query) > 0) {
        setSession(false, "You already have leave applied during this period.");
        echo "<script>window.location.href='apply_leave.php';</script>";
        exit;
    }

    // ── Check WFH overlap ────────────────────────────────────────────────────
    $wfh_check = mysqli_query($con, "
        SELECT wfh_id FROM wfhRequests
        WHERE userid = '$userId'
          AND status NOT IN ('Rejected', 'Cancelled')
          AND fromDate <= '$to_date' AND toDate >= '$from_date'
    ");
    if (mysqli_num_rows($wfh_check) > 0) {
        setSession(false, "You have already applied for WFH during this date range. Cannot apply for leave.");
        echo "<script>window.location.href='apply_leave.php';</script>";
        exit;
    }

    // ── Check Tour overlap ───────────────────────────────────────────────────
    $tour_check = mysqli_query($con, "
        SELECT id FROM tour
        WHERE userId = '$userId'
          AND status NOT IN ('Rejected', 'Cancelled')
          AND fromDate <= '$to_date' AND toDate >= '$from_date'
    ");
    if (mysqli_num_rows($tour_check) > 0) {
        setSession(false, "You have already applied for a Tour during this date range. Cannot apply for leave.");
        echo "<script>window.location.href='apply_leave.php';</script>";
        exit;
    }

    // ── RO monthly cap — offline interns only (max 2 days per month) ─────────
    if ($leave_type === 'RO') {
        $typeQ = mysqli_query($con, "SELECT type FROM signup WHERE userId = '$userId'");
        $typeR = mysqli_fetch_assoc($typeQ);
        $empType = strtolower($typeR['type'] ?? '');

        if ($empType === 'offline') {
            $roMonths = [];
            $s = strtotime($from_date);
            $e = strtotime($to_date);
            for ($d = $s; $d <= $e; $d += 86400) {
                $mk = date('Y-m', $d);
                $roMonths[$mk] = ($roMonths[$mk] ?? 0) + 1;
            }

            foreach ($roMonths as $mk => $applyingDays) {
                $monthStart = $mk . '-01';
                $monthEnd = date('Y-m-t', strtotime($monthStart));

                $existingRoQ = mysqli_query($con, "
                    SELECT from_date, to_date FROM leaves
                    WHERE  userId     = '$userId'
                      AND  leave_type = 'RO'
                      AND  status    NOT IN ('Cancelled', 'Rejected')
                      AND  from_date <= '$monthEnd'
                      AND  to_date   >= '$monthStart'
                ");

                $usedDaysInMonth = 0;
                while ($roRow = mysqli_fetch_assoc($existingRoQ)) {
                    $rs = strtotime(max($roRow['from_date'], $monthStart));
                    $re = strtotime(min($roRow['to_date'], $monthEnd));
                    for ($rd = $rs; $rd <= $re; $rd += 86400) {
                        $usedDaysInMonth++;
                    }
                }

                if ($usedDaysInMonth + $applyingDays > 2) {
                    $monthLabel = date('F Y', strtotime($monthStart));
                    setSession(false, "Rostering Off limit reached for $monthLabel. Only 2 RO days are allowed per month for offline interns.");
                    echo "<script>window.location.href='apply_leave.php';</script>";
                    exit;
                }
            }
        }
    }
    // ── End RO cap ───────────────────────────────────────────────────────────

    // ── Fetch balance ────────────────────────────────────────────────────────
    $available_leave = 0;
    if (in_array($leave_type, $balanceBasedLeaves)) {
        $balance_query = mysqli_query($con, "SELECT `$leave_type` FROM signup WHERE userId='$userId'");
        $balance_data = mysqli_fetch_assoc($balance_query);
        $available_leave = (int) ($balance_data[$leave_type] ?? 0);
    }

    // ── Balance validation considering pending requests ──────────────────────
    if (in_array($leave_type, $balanceBasedLeaves)) {
        // Calculate pending days for this leave type
        $pending_query = mysqli_query($con, "
            SELECT from_date, to_date FROM leaves
            WHERE userId = '$userId'
              AND leave_type = '$leave_type'
              AND status = 'Pending'
        ");
        $pending_days = 0;
        if ($pending_query) {
            while ($pRow = mysqli_fetch_assoc($pending_query)) {
                $ps = strtotime($pRow['from_date']);
                $pe = strtotime($pRow['to_date']);
                for ($pd = $ps; $pd <= $pe; $pd += 86400) {
                    if (date('N', $pd) != 7) $pending_days++;
                }
            }
        }
        $effective_available = max(0, $available_leave - $pending_days);

        if ($effective_available <= 0) {
            setSession(false, "You have no $leave_name balance remaining (0 available, $pending_days pending approval).");
            echo "<script>window.location.href='apply_leave.php';</script>";
            exit;
        }
        if ($date_diff_days > $effective_available) {
            setSession(false, "Insufficient $leave_name balance. Available: $effective_available day(s).");
            echo "<script>window.location.href='apply_leave.php';</script>";
            exit;
        }
    }

    // ── File upload (SL only) ────────────────────────────────────────────────
    $document_path = null;
    if ($leave_type == "SL" && !empty($_FILES['leave_document']['name'])) {

        // ---------------- File Validation & Upload ----------------
        $file = $_FILES['leave_document']['name'];
        $size = $_FILES['leave_document']['size'];

        // Validate file (max size 5 MB, allowed extensions: pdf, jpg, jpeg, png)
        $fileExt = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $allowedExts = ['pdf', 'jpg', 'jpeg', 'png'];
        $val = (in_array($fileExt, $allowedExts) && $size <= 5 * 1024 * 1024);

        $masterPath = "ams/";

        if ($val) {

            // Sanitize filename to prevent URL encoding issues (spaces, special chars)
            $sanitized_basename = preg_replace('/[^a-zA-Z0-9.\-]/', '_', basename($file));
            $target_file = date('Y-m-d_H-i-s') . "_" . $sanitized_basename;
            $fileTmp = $_FILES["leave_document"]["tmp_name"];
            $path = $masterPath . "leave_docs/" . $target_file;

            try {
                $result = $s3->putObject([
                    'Bucket' => $bucket,
                    'Key' => $path,
                    'SourceFile' => $fileTmp,
                    'ACL' => 'public-read'
                ]);

                $publicBaseURL = "https://pub-1824248a30f04ad182514fd981b598c1.r2.dev/";
                $fileURL = $publicBaseURL . $path;
                $document_path = $fileURL; // Store URL in database instead of just filename
            } catch (Exception $e) {
                setSession(false, "Cloud upload failed: " . $e->getMessage());
                echo '<script>window.location.href = "apply_leave.php";</script>';
                exit();
            }

        } else {
            setSession(false, "File validation failed. Please check the file type and size.");
            echo '<script>
                window.location.href = "apply_leave.php";
            </script>';
            exit();
        }
    }

    // ── RO is auto-approved; all other leave types start as Pending ──────────
    $leaveStatus = ($leave_type === 'RO') ? 'Approved' : 'Pending';

    // ── Remove any system Auto Leaves for these dates so user's applied leave replaces it ──
    mysqli_query($con, "
        DELETE FROM leaves
        WHERE  userId  = '$userId'
          AND  (leave_name LIKE '%Auto Leave%' OR leave_type = 'Auto')
          AND  from_date <= '$to_date'
          AND  to_date   >= '$from_date'
    ");

    // ── Insert leave record ──────────────────────────────────────────────────
    $leaveId = uniqid('LV_', true);

    $insert_query = mysqli_query($con, "
        INSERT INTO leaves (
            leave_id, leave_d, leave_name, leave_type, leave_day,
            from_date, to_date, reason,
            approved_by, informed_colleagues, interview_leave,
            document_path,
            userId, status, created_date
        ) VALUES (
            '$leaveId', 'N/A', '$leave_name', '$leave_type', 'Full Day',
            '$from_date', '$to_date', '$reason',
            '$approved_by', '$informed_colleague',
            " . ($interview_leave ? "'$interview_leave'" : "NULL") . ",
            " . ($document_path ? "'$document_path'" : "NULL") . ",
            '$userId', '$leaveStatus', '$created_date'
        )
    ");

$userQuery = mysqli_query($con, "
    SELECT fullName
    FROM signup
    WHERE userId = '$userId'
    LIMIT 1
");

$userData = mysqli_fetch_assoc($userQuery);

$employeeName = $userData['fullName'] ?? 'Employee';

notifyAdmins(
    $con,
    $employeeName,
    $leaveId
);

    if ($insert_query) {
        // Only deduct balance immediately if leave is auto-approved (e.g. RO)
        if ($leave_type === 'RO') {
            $new_balance = max(0, $available_leave - $date_diff_days);
            mysqli_query($con, "UPDATE signup SET `$leave_type`='$new_balance' WHERE userId='$userId'");
        }
        setSession(true, "Leave applied successfully.");
    } else {
        setSession(false, "Sorry, leave could not be applied.");
    }
} else {
    setSession(false, "Invalid request.");
}

echo "<script>window.location.href='apply_leave.php';</script>";
exit;
