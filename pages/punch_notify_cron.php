<?php
include('../include/db_config.php');
include('../include/notifications.php'); // must have sendFCMNotification()

date_default_timezone_set('Asia/Kolkata');

$current_time = date('H:i:s');
$today = date('Y-m-d');

echo "✅ Script running at $current_time <br>";

// Fetch approved users with valid token + their expected punch-in time
$users = mysqli_query($con, "
    SELECT userId, username, fullName, token, expectedPunchIn
    FROM signup 
    WHERE regstatus='approved' 
      AND token IS NOT NULL 
      AND token <> ''
");

if (!$users) {
    die("User query failed: " . mysqli_error($con));
}

while ($user = mysqli_fetch_assoc($users)) {
    $userId = $user['userId'];
    $username = $user['username'];
    $fullName = $user['fullName'];
    $token = $user['token'];
    $expected_time = $user['expectedPunchIn'];

    // Check punch-in for today
    $check = mysqli_query($con, "SELECT * FROM attendance WHERE userId='$userId' AND date='$today'");
    $hasPunched = mysqli_num_rows($check) > 0;

    if ($hasPunched) {
        // 🍔 Lunch Break Check
        $lunch_diff = strtotime($current_time) - strtotime('13:00:00');
        if ($lunch_diff >= 0 && $lunch_diff < 60) {
            $checkLunch = mysqli_query($con, "SELECT id FROM notifications WHERE userId='$userId' AND title='Lunch Break' AND created_at >= NOW() - INTERVAL 2 HOUR");
            if (mysqli_num_rows($checkLunch) == 0) {
                $title = "Lunch Break";
                $body = "Your Lunch Break";
                echo "📨 Sending '$title' to $username...<br>";
                $response = sendFCMNotification($token, $title, $body, $accessToken ?? '', $projectId ?? '');
                $stmt = $con->prepare("INSERT INTO notifications (userId, title, message, status, is_read, created_at) VALUES (?, ?, ?, 'sent', 0, NOW())");
                $stmt->bind_param('sss', $userId, $title, $body);
                $stmt->execute();
                $stmt->close();
            }
        }
        continue;
    }

    $time_diff = strtotime($current_time) - strtotime($expected_time);
    $current_time_display = date('h:i A'); // human-readable format like 10:15 AM
    $title = ""; $body = "";

    // -5 mins (Pre-Punch In)
    if ($time_diff >= -300 && $time_diff < -240) {
        $title = "Punch In Reminder";
        $body = "Please punch in soon. Your shift starts in 5 minutes.";
    } 
    // +5 mins (Post-Punch In)
    elseif ($time_diff >= 300 && $time_diff < 360) {
        $title = "Punch In Overdue";
        $body = "You are 5 minutes late. Please punch in immediately.";
    } 
    // +10 mins (Post-Punch In)
    elseif ($time_diff >= 600 && $time_diff < 660) {
        $title = "Punch In Overdue";
        $body = "You are 10 minutes late. Please punch in immediately.";
    } 
    // +30 mins (Absent / Auto Leave Section)
    elseif ($time_diff >= 1800 && $time_diff < 3600) {

        if (!$hasLateMark) {  

            // Check if already marked on leave 
            $checkLeave = mysqli_query($con, "SELECT * FROM leaves WHERE userId='$userId' AND from_date='$today' AND to_date='$today'");
            if (mysqli_num_rows($checkLeave) == 0) {

                // Generate leave_id and leave_d
                $leave_id = uniqid('LID_');
                $leave_d = 'L-' . strtoupper(substr($userId, -4)) . '-' . date('dmY');

                // Insert into leaves table
                $insertLeave = $con->prepare("
                INSERT INTO leaves 
                (created_date, leave_id, leave_d, leave_name, leave_type, leave_day, from_date, to_date, reason, approved_by, informed_colleagues, userId, status, updated_date) 
                VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");

                // Default leave details
                $leave_name = 'Auto Leave - Missing Punch In';
                $leave_type = 'Auto';
                $leave_day = 'Full Day';
                $reason = 'System marked auto leave due to missing punch-in.';
                $approved_by = '';
                $informed_colleagues = '';
                $status = 'Pending'; // or 'Approved' based on your process

                $insertLeave->bind_param(
                    'ssssssssssss',
                    $leave_id,
                    $leave_d,
                    $leave_name,
                    $leave_type,
                    $leave_day,
                    $today,
                    $today,
                    $reason,
                    $approved_by,
                    $informed_colleagues,
                    $userId,
                    $status
                );

                if ($insertLeave->execute()) {
                    echo "✅ Auto leave added for $username.<br>";
                    $title = "Marked Absent";
                    $body = "Your maked as absend for not punch in";
                } else {
                    echo "⚠️ Failed to insert leave for $username: " . $insertLeave->error . "<br>";
                    continue;
                }

                $insertLeave->close();
            } else {
                echo "🟡 Leave already exists for $username today.<br>";
                continue;
            }

        } else {
            echo "🟡 $username gave late mark intimation.<br>";
            continue;
        }
    } else {
        echo "⏳ $username — No action needed at this time.<br>";
        continue;
    }

    // Check for duplicate today
    $checkDup = mysqli_query($con, "SELECT id FROM notifications WHERE userId='$userId' AND title='$title' AND created_at >= NOW() - INTERVAL 12 HOUR");
    if (mysqli_num_rows($checkDup) == 0 && !empty($title)) {
        // Send FCM notification
        echo "📨 Sending '$title' to $username (Expected In: $expected_time)...<br>";
        $response = sendFCMNotification($token, $title, $body, $accessToken ?? '', $projectId ?? '');
        echo "Response: $response <br>";

        // ✅ Insert notification record in DB
        $dateTime = date('Y-m-d H:i:s');
        $stmt = $con->prepare("
            INSERT INTO notifications (userId, title, message, status, is_read, created_at)
            VALUES (?, ?, ?, ?, 0, ?)
        ");
        $status = 'sent';
        $stmt->bind_param('sssss', $userId, $title, $body, $status, $dateTime);
        if ($stmt->execute()) {
            echo "🗂️ Notification logged in DB for $username.<br>";
        } else {
            echo "⚠️ Failed to log notification for $username: " . $stmt->error . "<br>";
        }
        $stmt->close();
    } elseif (!empty($title)) {
        echo "🟡 Duplicate prevented for $title to $username.<br>";
    }
}

echo "<br>⏭ Checking Punch-Out Reminders...<br>";

// Fetch again all approved users with valid token
$users2 = mysqli_query($con, "
    SELECT userId, username, fullName, token, expectedPunchOut
    FROM signup 
    WHERE regstatus='approved'
      AND token IS NOT NULL 
      AND token <> ''
");

while ($u = mysqli_fetch_assoc($users2)) {

    $userId = $u['userId'];
    $username = $u['username'];
    $fullName = $u['fullName'];
    $token = $u['token'];
    $expectedPunchOut = $u['expectedPunchOut'];  // DB time
    $current = strtotime($current_time);
    $expOut = strtotime($expectedPunchOut);

    if (empty($expectedPunchOut)) {
        echo "⚠️ $username has no expected punch-out time set.<br>";
        continue;
    }

    // Check if user already punched out today
    $checkOut = mysqli_query($con, "
        SELECT * FROM attendance 
        WHERE userId='$userId' AND date='$today' AND punchOut IS NOT NULL
    ");

    if (mysqli_num_rows($checkOut) > 0) {
        echo "🟢 $username already punched out today.<br>";
        continue;
    }

    $diff = $current - $expOut; // seconds past expected punch out

    // ---------------------------
    //  1️⃣ 5 MINUTES AFTER PUNCH OUT (Extend Reminder)
    // ---------------------------
    if ($diff >= 300 && $diff < 360) {

        // Prevent duplicate notifications
        $checkExt = mysqli_query($con, "
            SELECT id FROM notifications
            WHERE userId='$userId'
              AND title='Punch Out'
              AND created_at >= NOW() - INTERVAL 2 HOUR
        ");

        if (mysqli_num_rows($checkExt) == 0) {

            $title = "Punch Out";
            $body = "are you want to extend punch out";

            echo "📨 Sending 5-min post-punch-out reminder to $username<br>";
            $response = sendFCMNotification($token, $title, $body, $accessToken ?? '', $projectId ?? '');
            echo "Response: $response <br>";

            // Log Notification
            $stmt = $con->prepare("
                INSERT INTO notifications (userId, title, message, status, is_read, created_at)
                VALUES (?, ?, ?, 'sent', 0, NOW())
            ");
            $stmt->bind_param("sss", $userId, $title, $body);
            $stmt->execute();
            $stmt->close();
        }

        continue; // Do not check next condition at same time
    }

    // ---------------------------
    //  2️⃣ AT EXACT PUNCH OUT TIME
    // ---------------------------
    if ($diff >= 0 && $diff < 60) { // within 1 minute window

        // Prevent duplicate notifications
        $checkOutNow = mysqli_query($con, "
            SELECT id FROM notifications
            WHERE userId='$userId'
              AND title='Punch Out Now'
              AND created_at >= NOW() - INTERVAL 1 HOUR
        ");

        if (mysqli_num_rows($checkOutNow) == 0) {

            $title = "Punch Out Now";
            $body = "Hi $fullName 👋, it’s your punch-out time. Please Punch Out or extend your working hours.";

            echo "📨 Sending punch-out time alert to $username<br>";
            $response = sendFCMNotification($token, $title, $body, $accessToken ?? '', $projectId ?? '');
            echo "Response: $response <br>";

            // Log Notification
            $stmt = $con->prepare("
                INSERT INTO notifications (userId, title, message, status, is_read, created_at)
                VALUES (?, ?, ?, 'sent', 0, NOW())
            ");
            $stmt->bind_param("sss", $userId, $title, $body);
            $stmt->execute();
            $stmt->close();
        }

        continue;
    }

    echo "⏳ $username — No punch-out action needed.<br>";
}

echo "<br>✅ Finished at " . date('H:i:s');
?>