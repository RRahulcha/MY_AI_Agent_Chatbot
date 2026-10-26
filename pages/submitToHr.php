<?php
session_start();
include('../include/db_config.php');
include('../include/notifications.php');
if (!isset($_SESSION['userId'])) {
    echo "<script>window.location.href='login.php';</script>";
    exit;
}

$userId   = $_SESSION['userId'];
$getUser  = mysqli_query($con, "SELECT designation FROM signup WHERE userId='$userId'");
$userData = mysqli_fetch_assoc($getUser);

if (!$userData || ($userData['designation'] != 'seniorIntern' && $userData['designation'] != 'resourceAllocator')) {
    echo "<script>alert('Unauthorized Access'); window.location.href='logout.php';</script>";
    exit;
}

$candidateId = intval($_GET['candidateId']);

$checkQuery = mysqli_query($con, "
    SELECT id FROM technical_evaluations
    WHERE candidateId = '$candidateId' AND submittedToHr = 0
");

if (mysqli_num_rows($checkQuery) > 0) {

    $evalRow = mysqli_fetch_assoc($checkQuery);

    mysqli_query($con, "
        UPDATE technical_evaluations
        SET submittedToHr = 1,
            submittedAt   = NOW(),
            hrStatus      = 'pending'
        WHERE id = '{$evalRow['id']}'
    ");

    // 🔔 SEND HR NOTIFICATION HERE

    $candidateQuery = mysqli_query($con, "
        SELECT applicantName
        FROM candidates
        WHERE id='$candidateId'
        LIMIT 1
    ");

    $candidateData = mysqli_fetch_assoc($candidateQuery);

    $candidateName = $candidateData['applicantName'] ?? 'Candidate';
    $escapedCandidateName = mysqli_real_escape_string($con, $candidateName);

    // Fetch Firebase Access Token
    $accessToken = function_exists('getFirebaseAccessToken') ? getFirebaseAccessToken() : null;

    $hrQuery = mysqli_query($con, "
        SELECT userId, token
        FROM signup
        WHERE designation='HR'
    ");

    while ($hr = mysqli_fetch_assoc($hrQuery)) {

        mysqli_query($con, "
            INSERT INTO notifications
            (userId, title, message, type, is_read, created_at)
            VALUES (
                '{$hr['userId']}',
                'Candidate Submitted to HR',
                '{$escapedCandidateName} has been submitted for HR review.',
                'hr',
                0,
                NOW()
            )
        ");

        if (!empty($hr['token'])) {

            sendFCMNotification(
                $hr['token'],
                'Candidate Submitted to HR',
                "$candidateName has been submitted for HR review.",
                $accessToken,
                $projectId
            );
        }
    }

    $_SESSION['swal_icon']    = 'success';
    $_SESSION['swal_title']   = 'Sent to HR!';
    $_SESSION['swal_message'] = 'Evaluation has been submitted to HR for final review.';

} else {

    $_SESSION['swal_icon']    = 'error';
    $_SESSION['swal_title']   = 'Failed!';
    $_SESSION['swal_message'] = 'Evaluation not found or already submitted to HR.';
}
// Redirect back — SweetAlert fires on the list page, no blank intermediate page
header("Location: technical_evaluation.php");
exit;
?>