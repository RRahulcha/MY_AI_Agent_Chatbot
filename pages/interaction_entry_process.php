<?php
session_start();

if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href='login.php';</script>";
    exit;
}

include('../include/db_config.php');

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    $userId = $_SESSION['userId'];

    // ── Sanitize inputs ───────────────────────────────────────────────────────
    $applicant_name   = trim($_POST['applicant_name']);
    $email            = trim($_POST['email']);
    $mobile           = trim($_POST['mobile']);
    $application_date = $_POST['application_date'];
    $interview_date   = $_POST['interview_date']   ?? null;
    $interview_time   = $_POST['interview_time']   ?? null; // HH:MM 24h from hidden field
    $interview_mode   = trim($_POST['interview_mode']);
    $center           = trim($_POST['center']);
    $source           = trim($_POST['source']);
    $referred_by      = trim($_POST['referred_by']      ?? '');
    $resume_link      = trim($_POST['resume_link']);
    $interview_status = trim($_POST['interview_status']);
    // Now stores the intern's userId (not their name)
    $interviewed_by   = trim($_POST['interviewed_by']   ?? '');

    $today = date('Y-m-d');

    /* ── VALIDATIONS ─────────────────────────────────────────────────────── */

    if (empty($applicant_name) || empty($email) || empty($mobile) || empty($application_date)) {
        $_SESSION['status'] = false;
        $_SESSION['msg']    = "Please fill all required fields.";
        header("Location: interaction_entry.php");
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['status'] = false;
        $_SESSION['msg']    = "Invalid Email Format.";
        header("Location: interaction_entry.php");
        exit;
    }

    if (!preg_match('/^[0-9]{10}$/', $mobile)) {
        $_SESSION['status'] = false;
        $_SESSION['msg']    = "Mobile number must be exactly 10 digits.";
        header("Location: interaction_entry.php");
        exit;
    }

    if ($application_date !== $today) {
        $_SESSION['status'] = false;
        $_SESSION['msg']    = "Application date must be today.";
        header("Location: interaction_entry.php");
        exit;
    }

    if (!empty($interview_date) && $interview_date < $today) {
        $_SESSION['status'] = false;
        $_SESSION['msg']    = "Interview date cannot be in the past.";
        header("Location: interaction_entry.php");
        exit;
    }

    $allowed_sources = ['LinkedIn', 'Referral', 'Indeed', 'Instagram', 'Facebook', ''];
    if (!in_array($source, $allowed_sources)) {
        $_SESSION['status'] = false;
        $_SESSION['msg']    = "Invalid source selected.";
        header("Location: interaction_entry.php");
        exit;
    }

    if ($source === 'Referral' && empty($referred_by)) {
        $_SESSION['status'] = false;
        $_SESSION['msg']    = "Please enter the name of the person who referred.";
        header("Location: interaction_entry.php");
        exit;
    }

    /* ── SANITIZE FOR DB ─────────────────────────────────────────────────── */

    $applicant_name   = mysqli_real_escape_string($con, $applicant_name);
    $email            = mysqli_real_escape_string($con, $email);
    $mobile           = mysqli_real_escape_string($con, $mobile);
    $interview_mode   = mysqli_real_escape_string($con, $interview_mode);
    $center           = mysqli_real_escape_string($con, $center);
    $source           = mysqli_real_escape_string($con, $source);
    $referred_by      = mysqli_real_escape_string($con, $referred_by);
    $resume_link      = mysqli_real_escape_string($con, $resume_link);
    $interview_status = mysqli_real_escape_string($con, $interview_status);
    $interviewed_by   = mysqli_real_escape_string($con, $interviewed_by);

    // Handle nullable date/time fields
    $interview_date_sql = !empty($interview_date) ? "'$interview_date'" : "NULL";
    $interview_time_sql = !empty($interview_time) ? "'$interview_time'" : "NULL";

    /* ── INSERT CANDIDATE ────────────────────────────────────────────────── */

    $insertQuery = "
        INSERT INTO candidates (
            createdBy,
            applicantName,
            email,
            mobile,
            applicationDate,
            interviewDate,
            interviewTime,
            interviewMode,
            center,
            source,
            referredBy,
            resumeLink,
            interviewStatus,
            assignedInterviewer
        ) VALUES (
            '$userId',
            '$applicant_name',
            '$email',
            '$mobile',
            '$application_date',
            $interview_date_sql,
            $interview_time_sql,
            '$interview_mode',
            '$center',
            '$source',
            '$referred_by',
            '$resume_link',
            '$interview_status',
            '$interviewed_by'
        )
    ";

    if (mysqli_query($con, $insertQuery)) {
        $_SESSION['status'] = true;
        $_SESSION['msg']    = "Interaction Entry Submitted Successfully!";

        /* ── NOTIFY THE ASSIGNED INTERN ──────────────────────────────────── */
        if (!empty($interviewed_by)) {

            // 1. Fetch intern's full name for notification text
            $internRow  = mysqli_fetch_assoc(mysqli_query($con,
                "SELECT fullName FROM signup WHERE userId = '$interviewed_by' LIMIT 1"
            ));
            $internName = $internRow ? htmlspecialchars($internRow['fullName']) : 'Intern';

            $notifTitle = "New Candidate Assigned";
            $notifBody  = "Candidate '$applicant_name' has been assigned to you for technical evaluation.";

            // 2. Insert in-app notification into DB
            $escapedTitle = mysqli_real_escape_string($con, $notifTitle);
            $escapedBody  = mysqli_real_escape_string($con, $notifBody);

           mysqli_query($con, "
           INSERT INTO notifications (userId, title, message, created_at, status, is_read) 
           VALUES ('$interviewed_by', '$escapedTitle', '$escapedBody', NOW(), 'Unread', 0)
           ");

            // 3. Fetch intern's FCM token and send push notification
            $tokenRow = mysqli_fetch_assoc(mysqli_query($con,
    "SELECT token
     FROM signup
     WHERE userId = '$interviewed_by'
     LIMIT 1"
));

            if ($tokenRow && !empty($tokenRow['token'])) {
                sendFcmNotification($tokenRow['token'], $notifTitle, $notifBody);
            }
        }

    } else {
        $_SESSION['status'] = false;
        $_SESSION['msg']    = "Database Error: " . mysqli_error($con);
    }

    header("Location: interaction_entry.php");
    exit;
}

/* ── FCM PUSH NOTIFICATION HELPER ───────────────────────────────────────── */
/**
 * Sends a push notification to a single FCM device token.
 *
 * @param string $token  The recipient's FCM device token.
 * @param string $title  Notification title.
 * @param string $body   Notification body text.
 */
function sendFcmNotification(string $token, string $title, string $body): void
{
    // Replace with your actual FCM Server Key from:
    // Firebase Console → Project Settings → Cloud Messaging → Server key
    $apiKey = 'AAAATNyl-cg:APA91bHNY9rLBLSjran_NsyqE8GkmRgnDmOs9jzq0xavzV2jsOUkyItJIXzi9aLsg1JWYMsVPDSVYWgXyco7eBqa7OnQm_sI4ijokF8VnyAHZwjUZPGRYYlKi9ISqLSbVEI6EtV57bM9';

    $payload = json_encode([
        'to'           => $token,
        'notification' => [
            'title'     => $title,
            'body'      => $body,
            'sound'     => 'default',
            'smallIcon' => 'small_icon',
        ],
        'priority'          => 'high',
        'content_available' => true,
    ]);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'https://fcm.googleapis.com/fcm/send',
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: key=' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_POSTFIELDS     => $payload,
    ]);

    curl_exec($ch);
    curl_close($ch);
}
?>