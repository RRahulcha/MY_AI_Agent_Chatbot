<?php
session_start();

if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href='login.php';</script>";
    exit;
}

include('../include/db_config.php');
require_once('../include/notifications.php');
$query        = mysqli_query($con, "SELECT designation, userRole, fullName FROM signup WHERE userId = '" . mysqli_real_escape_string($con, $_SESSION['userId']) . "'");
$user         = mysqli_fetch_assoc($query);
$allowedRolesClean = ['seniorintern', 'teamlead', 'resourceallocator', 'resourcehead', 'resourcemanager', 'admin', 'superadmin', 'hr'];

$desigClean = strtolower(str_replace([' ', '_'], '', $user['designation'] ?? ''));
$roleClean  = strtolower(str_replace([' ', '_'], '', $user['userRole'] ?? ''));

if (!$user || (!in_array($desigClean, $allowedRolesClean) && !in_array($roleClean, $allowedRolesClean))) {
    echo "<script>alert('Unauthorized Access'); window.location.href='logout.php';</script>";
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: intern_kra_form.php');
    exit;
}

// ── Sanitize inputs ───────────────────────────────────────────────
$internId                 = mysqli_real_escape_string($con, trim($_POST['intern_id']               ?? ''));
$internName               = mysqli_real_escape_string($con, trim($_POST['intern_name']             ?? ''));
$batch                    = mysqli_real_escape_string($con, trim($_POST['batch']                   ?? ''));
$mode                     = mysqli_real_escape_string($con, trim($_POST['mode']                    ?? ''));
$office                   = mysqli_real_escape_string($con, trim($_POST['office']                  ?? ''));
$weekNumber               = (int)($_POST['week_number']                                            ?? date('W'));
$weekYear                 = (int)($_POST['week_year']                                              ?? date('Y'));
$communication            = (int)($_POST['communication']                                          ?? 0);
$communicationRemark      = mysqli_real_escape_string($con, trim($_POST['communication_remark']    ?? ''));
$confidence               = (int)($_POST['confidence']                                             ?? 0);
$confidenceRemark         = mysqli_real_escape_string($con, trim($_POST['confidence_remark']       ?? ''));
$clarity                  = (int)($_POST['clarity']                                                ?? 0);
$clarityRemark            = mysqli_real_escape_string($con, trim($_POST['clarity_remark']          ?? ''));
$teamwork                 = (int)($_POST['teamwork']                                               ?? 0);
$teamworkRemark           = mysqli_real_escape_string($con, trim($_POST['teamwork_remark']         ?? ''));
$problemSolving           = (int)($_POST['problem_solving']                                        ?? 0);
$problemSolvingRemark     = mysqli_real_escape_string($con, trim($_POST['problem_solving_remark']  ?? ''));
$learningAttitude         = (int)($_POST['learning_attitude']                                      ?? 0);
$learningAttitudeRemark   = mysqli_real_escape_string($con, trim($_POST['learning_attitude_remark'] ?? ''));
$technicalKnowledge       = (int)($_POST['technical_knowledge']                                    ?? 0);
$technicalKnowledgeRemark = mysqli_real_escape_string($con, trim($_POST['technical_knowledge_remark'] ?? ''));
$githubActivity           = (int)($_POST['github_activity']                                        ?? 0);
$githubActivityRemark     = mysqli_real_escape_string($con, trim($_POST['github_activity_remark']     ?? ''));
$amsProcessAdherence      = (int)($_POST['ams_process_adherence']                                  ?? 0);
$amsProcessAdherenceRemark= mysqli_real_escape_string($con, trim($_POST['ams_process_adherence_remark']  ?? ''));
$averagePercentage        = (float)($_POST['average_percentage']                                   ?? 0);

$submittedBy     = mysqli_real_escape_string($con, $_SESSION['userId']);
$submittedByName = mysqli_real_escape_string($con, $user['fullName']);
$submittedAt     = date('Y-m-d H:i:s');
$status          = 'active';

// ── Validate ──────────────────────────────────────────────────────
if (empty($internId)) {
    $_SESSION['msg']    = 'Please select an intern.';
    $_SESSION['status'] = 0;
    header('Location: intern_kra_form.php');
    exit;
}

$ratings = [$communication, $confidence, $clarity, $teamwork, $problemSolving, $learningAttitude, $technicalKnowledge, $githubActivity, $amsProcessAdherence];
foreach ($ratings as $r) {
    if ($r < 1 || $r > 10) {
        $_SESSION['msg']    = 'All ratings must be between 1 and 10.';
        $_SESSION['status'] = 0;
        header('Location: intern_kra_form.php');
        exit;
    }
}

// ── Fallback intern name ──────────────────────────────────────────
if (empty($internName)) {
    $nameQ   = mysqli_query($con, "SELECT fullName FROM signup WHERE userId = '$internId' LIMIT 1");
    $nameRow = mysqli_fetch_assoc($nameQ);
    $internName = $nameRow ? mysqli_real_escape_string($con, $nameRow['fullName']) : '';
}

// ── Duplicate check ───────────────────────────────────────────────
$dupCheck = mysqli_query($con,
    "SELECT kraId FROM internKraAssignments
     WHERE internId    = '$internId'
       AND weekNumber  = '$weekNumber'
       AND weekYear    = '$weekYear'
       AND submittedBy = '$submittedBy'
       AND status      = 'active'"
);

if (mysqli_num_rows($dupCheck) > 0) {
    $_SESSION['msg']    = 'You have already submitted a KRA for this intern for Week ' . $weekNumber . ', ' . $weekYear . '.';
    $_SESSION['status'] = 0;
    header('Location: intern_kra_form.php');
    exit;
}

// ── Insert ────────────────────────────────────────────────────────
$sql = "INSERT INTO internKraAssignments
        (internId, internName, batch, mode, office,
         communication, communicationRemark,
         confidence, confidenceRemark,
         clarity, clarityRemark,
         teamwork, teamworkRemark,
         problemSolving, problemSolvingRemark,
         learningAttitude, learningAttitudeRemark,
         technicalKnowledge, technicalKnowledgeRemark,
         githubActivity, githubActivityRemark,
         amsProcessAdherence, amsProcessAdherenceRemark,
         averagePercentage, submittedBy, submittedByName,
         submittedAt, weekNumber, weekYear, status)
        VALUES
        ('$internId', '$internName', '$batch', '$mode', '$office',
         '$communication', '$communicationRemark',
         '$confidence', '$confidenceRemark',
         '$clarity', '$clarityRemark',
         '$teamwork', '$teamworkRemark',
         '$problemSolving', '$problemSolvingRemark',
         '$learningAttitude', '$learningAttitudeRemark',
         '$technicalKnowledge', '$technicalKnowledgeRemark',
         '$githubActivity', '$githubActivityRemark',
         '$amsProcessAdherence', '$amsProcessAdherenceRemark',
         '$averagePercentage', '$submittedBy', '$submittedByName',
         '$submittedAt', '$weekNumber', '$weekYear', '$status')";

$insert = mysqli_query($con, $sql);

if (!$insert) {
    $_SESSION['msg']    = 'Database error: ' . mysqli_error($con);
    $_SESSION['status'] = 0;
    header('Location: intern_kra_form.php');
    exit;
}

$newKraId = mysqli_insert_id($con);

// ── Notify Resource Allocators ────────────────────────────────────
$rhQuery = mysqli_query($con,
    "SELECT userId, fullName FROM signup
     WHERE designation = 'resourceAllocator' AND status = 'active'"
);

if ($rhQuery && mysqli_num_rows($rhQuery) > 0) {
    while ($rh = mysqli_fetch_assoc($rhQuery)) {
        $toUserId = mysqli_real_escape_string($con, $rh['userId']);

        // ── Build title & message matching sendMeetingNotification format ──
        $title = mysqli_real_escape_string($con,
            '📋 New KRA Submitted: ' . $internName
        );
        $message = mysqli_real_escape_string($con,
            "KRA submitted by {$submittedByName}.\n" .
            "Intern: {$internName}\n" .
            "📅 Week: {$weekNumber}, {$weekYear}\n" .
            "📊 Average: " . number_format($averagePercentage, 2) . "%"
        );

        // ── Link goes to KRA list — NO join meeting link ──────────────────
        $safeLink = mysqli_real_escape_string($con,
            'intern_kra_list.php?highlight=' . (int)$newKraId
        );

        // ── DB notification (same column order as meeting notifications) ──
        mysqli_query($con,
            "INSERT INTO notifications
             (userId, title, message, link, type, status, is_read, created_at)
             VALUES
             ('$toUserId', '$title', '$message', '$safeLink',
              'kra_submission', 'Unread', 0, NOW())"
        );

        // ── FCM Push Notification ─────────────────────────────────────────
        $tokenQuery = mysqli_query($con,
            "SELECT token FROM signup WHERE userId = '$toUserId' LIMIT 1"
        );
        if ($tokenQuery && mysqli_num_rows($tokenQuery) > 0) {
            $tokenRow = mysqli_fetch_assoc($tokenQuery);
            if (!empty($tokenRow['token'])) {
                sendFCMNotification(
                    $tokenRow['token'],
                    '📋 New Intern KRA Submitted',
                    "{$submittedByName} submitted KRA for {$internName} — Week {$weekNumber}, Avg: " . number_format($averagePercentage, 2) . "%",
                    $accessToken,
                    $projectId
                );
            }
        }
    }
}

$_SESSION['msg']    = 'KRA submitted successfully. Resource Allocator has been notified.';
$_SESSION['status'] = 1;
header('Location: intern_kra_form.php');
exit;