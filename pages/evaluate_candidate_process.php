<?php
session_start();
include('../include/db_config.php');

if (!isset($_SESSION['userId'])) {
    echo "<script>window.location.href='login.php';</script>";
    exit;
}

$userId  = $_SESSION['userId'];
$getUser = mysqli_query($con, "SELECT designation FROM signup WHERE userId='$userId'");
$userData = mysqli_fetch_assoc($getUser);

if (!$userData || ($userData['designation'] != 'seniorIntern' && $userData['designation'] != 'resourceAllocator')) {
    echo "<script>alert('Unauthorized Access'); window.location.href='logout.php';</script>";
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    $candidateId    = intval($_POST['candidate_id']);
    $evaluationId   = intval($_POST['evaluation_id'] ?? 0);
    $isEdit         = ($_POST['is_edit'] ?? '0') === '1';

    $overallFeedback = mysqli_real_escape_string($con, trim($_POST['overall_feedback']));
    $recommendedFor  = mysqli_real_escape_string($con, trim($_POST['recommended_for']));

    // Collect ratings
    $languages  = $_POST['language_name']    ?? [];
    $conceptual = $_POST['conceptual_score'] ?? [];
    $practical  = $_POST['practical_score']  ?? [];
    $rowCount   = count($languages);

    // Validation
    if (empty($overallFeedback)) {
        $_SESSION['status'] = false;
        $_SESSION['msg']    = "Overall feedback is required.";
        header("Location: evaluate_candidate.php?id=$candidateId");
        exit;
    }

    if ($rowCount === 0) {
        $_SESSION['status'] = false;
        $_SESSION['msg']    = "At least one language rating is required.";
        header("Location: evaluate_candidate.php?id=$candidateId");
        exit;
    }

    // Calculate overall score
    $totalScore = 0;
    for ($i = 0; $i < $rowCount; $i++) {
        $totalScore += ((int)$conceptual[$i] + (int)$practical[$i]) / 2;
    }
    $overallScore = round($totalScore / $rowCount, 1);

    /* ====================================================
       EDIT MODE — UPDATE existing evaluation
    ==================================================== */
    if ($isEdit && $evaluationId > 0) {

        // Confirm this evaluation exists and belongs to this candidate
        $checkQuery = mysqli_query($con,
            "SELECT id, submittedToHr FROM technical_evaluations
             WHERE id='$evaluationId' AND candidateId='$candidateId' LIMIT 1"
        );
        $existingEval = mysqli_fetch_assoc($checkQuery);

        if (!$existingEval) {
            $_SESSION['status'] = false;
            $_SESSION['msg']    = "Evaluation not found.";
            header("Location: technical_evaluation.php");
            exit;
        }

        // Block edits after sending to HR
        if ($existingEval['submittedToHr'] == 1) {
            $_SESSION['status'] = false;
            $_SESSION['msg']    = "This evaluation has already been sent to HR and cannot be edited.";
            header("Location: technical_evaluation.php");
            exit;
        }

        // Update evaluation record
        mysqli_query($con, "
            UPDATE technical_evaluations SET
                overallFeedback = '$overallFeedback',
                overallScore    = '$overallScore',
                internshipMode  = '$recommendedFor'
            WHERE id = '$evaluationId'
        ");

        // Delete old language ratings and re-insert fresh ones
        mysqli_query($con, "DELETE FROM language_ratings WHERE evaluationId='$evaluationId'");

        for ($i = 0; $i < $rowCount; $i++) {
            $lang    = mysqli_real_escape_string($con, $languages[$i]);
            $concept = (int)$conceptual[$i];
            $pract   = (int)$practical[$i];

            mysqli_query($con, "
                INSERT INTO language_ratings (evaluationId, languageName, conceptualScore, practicalScore)
                VALUES ('$evaluationId', '$lang', '$concept', '$pract')
            ");
        }

        $_SESSION['status'] = true;
        $_SESSION['msg']    = "Evaluation updated successfully.";
        header("Location: technical_evaluation.php");
        exit;
    }

    /* ====================================================
       NEW MODE — INSERT evaluation
    ==================================================== */

    // Prevent duplicate evaluation
    $checkDuplicate = mysqli_query($con,
        "SELECT id FROM technical_evaluations WHERE candidateId='$candidateId'"
    );

    if (mysqli_num_rows($checkDuplicate) > 0) {
        $_SESSION['status'] = false;
        $_SESSION['msg']    = "This candidate has already been evaluated. Use the Edit button to make changes.";
        header("Location: technical_evaluation.php");
        exit;
    }

    // Insert evaluation
    $insertEval = mysqli_query($con, "
        INSERT INTO technical_evaluations
            (candidateId, overallFeedback, overallScore, internshipMode, submittedToHr, hrStatus)
        VALUES
            ('$candidateId', '$overallFeedback', '$overallScore', '$recommendedFor', 0, 'pending')
    ");

    if (!$insertEval) {
        $_SESSION['status'] = false;
        $_SESSION['msg']    = "Database Error: " . mysqli_error($con);
        header("Location: evaluate_candidate.php?id=$candidateId");
        exit;
    }

    $evaluationId = mysqli_insert_id($con);

    // Insert language ratings
    for ($i = 0; $i < $rowCount; $i++) {
        $lang    = mysqli_real_escape_string($con, $languages[$i]);
        $concept = (int)$conceptual[$i];
        $pract   = (int)$practical[$i];

        mysqli_query($con, "
            INSERT INTO language_ratings (evaluationId, languageName, conceptualScore, practicalScore)
            VALUES ('$evaluationId', '$lang', '$concept', '$pract')
        ");
    }

    $_SESSION['status'] = true;
    $_SESSION['msg']    = "Evaluation submitted successfully.";
    header("Location: technical_evaluation.php");
    exit;
}
?>