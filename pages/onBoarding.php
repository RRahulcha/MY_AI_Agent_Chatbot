<?php
session_start();
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}

// ── Clear draft when user explicitly requests a fresh start ──────
if (isset($_GET['clear_draft'])) {
    unset($_SESSION['onboarding_draft']);
    unset($_SESSION['preGenPassword']);
    unset($_SESSION['last_onboarded']);
    header("Location: onBoarding.php");
    exit;
}

include('../include/db_config.php');

$userId  = (int)$_SESSION['userId'];
$userRow = mysqli_fetch_assoc(mysqli_query($con, "SELECT fullName, employment_type FROM signup WHERE userId='$userId'"));
$userName = $userRow ? htmlspecialchars($userRow['fullName']) : 'Unknown';

$roleStack = [
    'Java Developer'    => 'Java, Spring, Hibernate',
    'PHP Developer'     => 'PHP, Laravel, MySQL',
    'UI Developer'      => 'HTML, CSS, JavaScript, React',
    'Python Developer'  => 'Python, Django, Pandas',
    'Android Developer' => 'Kotlin, Java, Android SDK',
    'Data Analyst'      => 'Python, SQL, Tableau, Excel',
    '.Net Developer'    => 'C#, ASP.NET, SQL Server',
    'Tester'            => 'Selenium, JUnit, Manual Testing',
    'Staff'             => 'General Support & Office Operations'
];
$roles = array_keys($roleStack);

$batches = [];
$batchQuery = mysqli_query($con, "SELECT batchName, batchCode FROM batches WHERE status='Active' ORDER BY batchName");
while ($b = mysqli_fetch_assoc($batchQuery)) $batches[] = $b;

$mentorQuery = mysqli_query($con, "
    SELECT userId, fullName, designation, employment_type
    FROM signup
    WHERE REPLACE(LOWER(designation), ' ', '') IN (
        'seniorintern','resourceallocator','teamlead','juniorintern'
    )
    ORDER BY
        CASE
            WHEN REPLACE(LOWER(designation), ' ', '') = 'seniorintern' THEN 1
            WHEN REPLACE(LOWER(designation), ' ', '') = 'resourceallocator' THEN 2
            WHEN REPLACE(LOWER(designation), ' ', '') = 'teamlead' THEN 3
            WHEN REPLACE(LOWER(designation), ' ', '') = 'juniorintern' THEN 4
            ELSE 5
        END, fullName ASC
");
$mentors = [];
while ($row = mysqli_fetch_assoc($mentorQuery)) $mentors[] = $row;

// ── Fetch HR-approved candidates not yet onboarded ────────────────
$approvedCandidates = [];
$approvedQuery = mysqli_query($con, "
    SELECT c.id, c.applicantName, c.email
    FROM candidates c
    INNER JOIN technical_evaluations te ON c.id = te.candidateId
    WHERE te.submittedToHr = 1
      AND LOWER(TRIM(te.hrStatus)) = 'approved'
      AND c.email IS NOT NULL
      AND TRIM(c.email) != ''
    ORDER BY c.applicantName ASC
");
if ($approvedQuery && mysqli_num_rows($approvedQuery) > 0) {
    $onboardedEmails = [];
    $obQ = mysqli_query($con, "SELECT LOWER(TRIM(email)) as em FROM interns WHERE email IS NOT NULL AND TRIM(email) != ''");
    if ($obQ) {
        while ($ob = mysqli_fetch_assoc($obQ)) $onboardedEmails[] = $ob['em'];
    }
    $seenEmails = [];
    while ($ac = mysqli_fetch_assoc($approvedQuery)) {
        $emailLower = strtolower(trim($ac['email']));
        if (!in_array($emailLower, $onboardedEmails) && !in_array($emailLower, $seenEmails)) {
            $approvedCandidates[] = $ac;
            $seenEmails[] = $emailLower;
        }
    }
}

function generateStrongPassword($length = 16) {
    $upper   = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $lower   = 'abcdefghjkmnpqrstuvwxyz';
    $digits  = '23456789';
    $special = '@#$!%*?&^~+=-';
    $password = '';
    for ($i = 0; $i < 3; $i++) $password .= $upper[random_int(0, strlen($upper)-1)];
    for ($i = 0; $i < 3; $i++) $password .= $lower[random_int(0, strlen($lower)-1)];
    for ($i = 0; $i < 3; $i++) $password .= $digits[random_int(0, strlen($digits)-1)];
    for ($i = 0; $i < 3; $i++) $password .= $special[random_int(0, strlen($special)-1)];
    $all = $upper . $lower . $digits . $special;
    for ($i = strlen($password); $i < $length; $i++) $password .= $all[random_int(0, strlen($all)-1)];
    return str_shuffle($password);
}

// ── Pre-generate password ONCE per onboarding session ────────────
// Only generate a new one if there is no existing draft OR the draft
// already has a committed password (i.e. account was just created).
if (!isset($_SESSION['preGenPassword'])) {
    $_SESSION['preGenPassword'] = generateStrongPassword();
}
$displayPassword = $_SESSION['preGenPassword'];

// ── Restore draft if it exists ────────────────────────────────────
$draft = $_SESSION['onboarding_draft'] ?? [];

$error = '';

// ════════════════════════════════════════════════════════════════════
// STEP 1 — CREATE ACCOUNT (new primary action)
// ════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_account'])) {

    $fullname       = mysqli_real_escape_string($con, trim($_POST['fullname']));
    if (!preg_match('/^[a-zA-Z\s.-]+$/', trim($_POST['fullname']))) {
        $error = "Full Name must contain only letters, spaces, dots, or hyphens. Numbers are not allowed.";
    }
    $emailRaw       = trim($_POST['email'] ?? '');
    $email          = mysqli_real_escape_string($con, $emailRaw);
    $customInternId = mysqli_real_escape_string($con, trim($_POST['intern_id']));
    $role           = mysqli_real_escape_string($con, $_POST['role']);
    $doj            = mysqli_real_escape_string($con, $_POST['doj']);
    $end_date       = mysqli_real_escape_string($con, $_POST['end_date']);
    $punch_in       = mysqli_real_escape_string($con, $_POST['punch_in']);
    $punch_out      = mysqli_real_escape_string($con, $_POST['punch_out']);
    $working_slots  = $punch_in . ' - ' . $punch_out;
    $batch          = mysqli_real_escape_string($con, $_POST['batch']);
    $tech_stack     = mysqli_real_escape_string($con, $_POST['tech_stack']);
    $mentor_id      = mysqli_real_escape_string($con, $_POST['approved_by']);
    $mode           = mysqli_real_escape_string($con, $_POST['mode']);
    $stipend        = mysqli_real_escape_string($con, $_POST['stipend']);
    $branch         = mysqli_real_escape_string($con, $_POST['branch'] ?? 'Pune');
    $stipendAmount  = ($stipend === 'yes') ? (float)$_POST['stipend_amount'] : 0;

    // ── Persist draft to session immediately so back-nav restores it ──
    $_SESSION['onboarding_draft'] = [
        'fullname'       => $_POST['fullname'],
        'email'          => $emailRaw,
        'intern_id'      => $_POST['intern_id'],
        'role'           => $_POST['role'],
        'doj'            => $_POST['doj'],
        'end_date'       => $_POST['end_date'],
        'punch_in'       => $_POST['punch_in'],
        'punch_in_disp'  => $_POST['punch_in_disp'] ?? '',
        'punch_out'      => $_POST['punch_out'],
        'punch_out_disp' => $_POST['punch_out_disp'] ?? '',
        'batch'          => $_POST['batch'],
        'tech_stack'     => $_POST['tech_stack'],
        'approved_by'    => $_POST['approved_by'],
        'mode'           => $_POST['mode'],
        'stipend'        => $_POST['stipend'],
        'stipend_amount' => $_POST['stipend_amount'] ?? '',
        'branch'         => $_POST['branch'] ?? 'Pune',
    ];

    // Resolve mentor name for storing in colleague field
    $mentorName = '';
    if (!empty($mentor_id)) {
        $mRow = mysqli_fetch_assoc(mysqli_query($con, "SELECT fullName FROM signup WHERE userId='$mentor_id' LIMIT 1"));
        if ($mRow) $mentorName = mysqli_real_escape_string($con, $mRow['fullName']);
    }

    $trainingDays    = ($stipend === 'yes') ? 8 : 15;
    $trainingEndDate = date('Y-m-d', strtotime($doj . ' + ' . $trainingDays . ' days'));

    $rawPassword  = $_SESSION['preGenPassword'];
    $safePassword = mysqli_real_escape_string($con, $rawPassword);
    $username     = $email;
    $safeUsername = mysqli_real_escape_string($con, $username);

    // ── Duplicate email check (interns table) ──
    $dupIntern = mysqli_query($con, "SELECT internId FROM interns WHERE LOWER(TRIM(email)) = LOWER(TRIM('$email')) LIMIT 1");
    if ($dupIntern && mysqli_num_rows($dupIntern) > 0) {
        $existing = mysqli_fetch_assoc($dupIntern);
        $error = "This email (" . htmlspecialchars($emailRaw) . ") is already onboarded"
               . (!empty($existing['internId']) ? " as " . $existing['internId'] : "") . ".";
    }

    // ── Duplicate email check (signup table) ──
    if (empty($error)) {
        $dupSignup = mysqli_query($con, "SELECT userId FROM signup WHERE LOWER(TRIM(email)) = LOWER(TRIM('$email')) LIMIT 1");
        if ($dupSignup && mysqli_num_rows($dupSignup) > 0) {
            $error = "An account for this email (" . htmlspecialchars($emailRaw) . ") already exists in the system.";
        }
    }

    if (empty($error)) {
        // ── Resolve or generate Intern ID ──
        if (!empty($customInternId)) {
            $idCheck = mysqli_query($con, "SELECT internId FROM interns WHERE internId = '$customInternId' LIMIT 1");
            if ($idCheck && mysqli_num_rows($idCheck) > 0) {
                $error = "Intern ID '$customInternId' is already in use. Please use a different ID.";
            } else {
                $internId = $customInternId;
            }
        } else {
            $year   = date('Y');
            $result = mysqli_query($con, "SELECT MAX(id) as maxId FROM interns");
            $row    = mysqli_fetch_assoc($result);
            $nextId = ($row['maxId'] ?? 0) + 1;
            $internId = 'INT' . $year . str_pad($nextId, 3, '0', STR_PAD_LEFT);
        }
    }

    if (empty($error)) {
        $safeInternId = mysqli_real_escape_string($con, $internId);

        // ── Generate unique userId ──
        $userIdResult = mysqli_query($con, "SELECT MAX(CAST(userId AS UNSIGNED)) as maxUid FROM signup WHERE userId REGEXP '^[0-9]+$'");
        $userIdRow    = mysqli_fetch_assoc($userIdResult);
        $newUserId    = ($userIdRow['maxUid'] ?? 1000) + 1;
        $safeNewUserId = (int)$newUserId;

        // ── Ensure optional columns exist ──
        $hasBranchCol = (bool)mysqli_num_rows(mysqli_query($con, "SHOW COLUMNS FROM interns LIKE 'branch'"));

        $hasLocationCol = (bool)mysqli_num_rows(mysqli_query($con, "SHOW COLUMNS FROM interns LIKE 'intern_location'"));
        if (!$hasLocationCol) {
            mysqli_query($con, "ALTER TABLE interns ADD COLUMN intern_location VARCHAR(200) DEFAULT '' AFTER branch");
            $hasLocationCol = true;
        }
        $hasTrainingEndCol = (bool)mysqli_num_rows(mysqli_query($con, "SHOW COLUMNS FROM interns LIKE 'trainingEndDate'"));
        if (!$hasTrainingEndCol) {
            mysqli_query($con, "ALTER TABLE interns ADD COLUMN trainingEndDate DATE DEFAULT NULL AFTER intern_location");
            $hasTrainingEndCol = true;
        }

        $locationPart    = $hasLocationCol    ? ", intern_location"  : "";
        $locationVal     = $hasLocationCol    ? ", '$branch'"        : "";
        $trainingEndPart = $hasTrainingEndCol ? ", trainingEndDate"  : "";
        $trainingEndVal  = $hasTrainingEndCol ? ", '$trainingEndDate'" : "";
        $branchPart      = $hasBranchCol      ? ", branch"           : "";
        $branchVal       = $hasBranchCol      ? ", '$branch'"        : "";

        // ── Insert into interns table ──
        $insertIntern = "INSERT INTO interns
            (internId, fullName, email, role, doj, expectedEndDate, workingSlots, batch, techStack,
             approvalStatus, phase, entryType, approvedBy, mode, stipend, stipendAmount, password
             $branchPart $locationPart $trainingEndPart)
            VALUES
            ('$safeInternId','$fullname','$email','$role','$doj','$end_date','$working_slots','$batch',
             '$tech_stack','approved','Training','standard','$mentor_id','$mode','$stipend',
             '$stipendAmount','$safePassword'
             $branchVal $locationVal $trainingEndVal)";

        if (mysqli_query($con, $insertIntern)) {
            $now = date('Y-m-d H:i:s');

            // ── Insert into signup table ──
            $accInsert = "INSERT INTO signup
                (userId, emp_id, internId, fullName, employment_type, email, mobileNo,
                 designation, department, branch, mode, username, password,
                 authority, profilePhoto, createdDate, registration_date,
                 level, SL, PL, CL, TD, RO, totalWorkingHours,
                 expectedPunchIn, expectedPunchOut,
                 type, status, office, userRole, dob, regstatus,
                 start_date, end_date,
                 shift_in, shift_out,
                 remarks, token, medical_leaves, casual_leaves, fcm_token,
                 batch_number, colleague,
                 passwordLocked, passwordChangeRequired)
                VALUES
                ('$safeNewUserId', '', '$safeInternId', '$fullname', 'Intern', '$email', 0,
                 'juniorIntern', 'Office', '$branch', '$mode', '$safeUsername', '$safePassword',
                 'user', '', '$now', '$now',
                 1, 4, 5, 4, 2, 2, '00:00:00',
                 '$punch_in', '$punch_out',
                 '$mode', 'Active', '$branch', 'junior_intern', NULL, 'Approved',
                 '$doj', '$end_date',
                 '$punch_in', '$punch_out',
                 '', '', 0, 0, '',
                 '$batch', '$mentorName',
                 0, 1)";

            if (mysqli_query($con, $accInsert)) {
                // ── Clear draft & pre-gen password now that account is committed ──
                unset($_SESSION['preGenPassword']);
                unset($_SESSION['onboarding_draft']);

                // ── Store full credentials in session for display & email ──
                $_SESSION['last_onboarded'] = [
                    'internId'    => $internId,
                    'userId'      => $newUserId,
                    'fullName'    => $fullname,
                    'email'       => $emailRaw,
                    'username'    => $username,
                    'password'    => $rawPassword,
                    'role'        => $_POST['role'],
                    'doj'         => $doj,
                    'end_date'    => $end_date,
                    'batch'       => $_POST['batch'],
                    'mode'        => $_POST['mode'],
                    'branch'      => $_POST['branch'] ?? 'Pune',
                    'mentor'      => $mentorName,
                    'punch_in'    => $punch_in,
                    'punch_out'   => $punch_out,
                    'trainingEnd' => $trainingEndDate,
                    'trainingDays'=> $trainingDays,
                ];

                $_SESSION['swal'] = [
                    'icon'  => 'success',
                    'title' => 'Account Created!',
                    'text'  => 'Intern ' . $internId . ' has been onboarded successfully. Credentials are ready to send.'
                ];
                header("Location: onBoarding.php?step=done");
                exit();
            } else {
                // Rollback interns insert
                mysqli_query($con, "DELETE FROM interns WHERE internId='$safeInternId'");
                $error = "Account creation in signup table failed: " . mysqli_error($con);
            }
        } else {
            $error = "Error inserting intern record: " . mysqli_error($con);
        }
    }

    if (!empty($error)) {
        // Keep the same pre-gen password so HR doesn't lose it on validation error
        $displayPassword = $_SESSION['preGenPassword'];
        // Update draft with corrected values (minus the failed submit)
        $draft = $_SESSION['onboarding_draft'] ?? [];
    }
}

// ── After successful creation, load last_onboarded from session ──
$lastOnboarded = $_SESSION['last_onboarded'] ?? null;
$stepDone      = isset($_GET['step']) && $_GET['step'] === 'done' && $lastOnboarded;

// ── Hydrate draft defaults ─────────────────────────────────────────
$draftFullname    = htmlspecialchars($draft['fullname']       ?? '');
$draftEmail       = htmlspecialchars($draft['email']          ?? '');
$draftInternId    = htmlspecialchars($draft['intern_id']      ?? '');
$draftRole        = htmlspecialchars($draft['role']           ?? '');
$draftDoj         = htmlspecialchars($draft['doj']            ?? '');
$draftEndDate     = htmlspecialchars($draft['end_date']       ?? '');
$draftPunchIn     = htmlspecialchars($draft['punch_in']       ?? '');
$draftPunchInDisp = htmlspecialchars($draft['punch_in_disp']  ?? '');
$draftPunchOut    = htmlspecialchars($draft['punch_out']      ?? '');
$draftPunchOutDisp= htmlspecialchars($draft['punch_out_disp'] ?? '');
$draftBatch       = htmlspecialchars($draft['batch']          ?? '');
$draftTechStack   = htmlspecialchars($draft['tech_stack']     ?? '');
$draftMentorId    = htmlspecialchars($draft['approved_by']    ?? '');
$draftMode        = htmlspecialchars($draft['mode']           ?? 'Online');
$draftStipend     = htmlspecialchars($draft['stipend']        ?? '');
$draftStipendAmt  = htmlspecialchars($draft['stipend_amount'] ?? '');
$draftBranch      = htmlspecialchars($draft['branch']         ?? 'Pune');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Onboarding Process</title>
    <?php include('../partials/_links.php'); ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary: #2f55ff;
            --primary-light: #eef1ff;
            --success: #28a745;
            --border: #e0e4ef;
            --radius: 8px;
        }
        .section-title {
            font-size: 1.05rem; font-weight: 700;
            border-left: 4px solid var(--primary);
            padding-left: 12px; margin: 28px 0 18px; color: #222;
        }
        .required { color: #e53935; }
        .form-control {
            border-radius: var(--radius); border: 1.5px solid var(--border);
            padding: 9px 13px; font-size: 0.93rem; color: #222 !important;
            transition: border 0.2s, box-shadow 0.2s;
        }
        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(47,85,255,0.1); outline: none;
        }

        /* ── Draft-restored banner ── */
        .draft-banner {
            display: flex; align-items: center; gap: 10px;
            background: #fffde7; border: 1.5px solid #ffe082;
            border-radius: var(--radius); padding: 11px 16px;
            margin-bottom: 20px; font-size: 0.87rem; color: #7b5b00;
        }
        .draft-banner i { color: #f59f00; font-size: 1rem; flex-shrink: 0; }
        .draft-banner a { color: #e53935; font-weight: 700; margin-left: auto; text-decoration: none; }
        .draft-banner a:hover { text-decoration: underline; }

        /* ── Workflow Steps Banner ── */
        .workflow-steps {
            display: flex; align-items: center; gap: 0;
            background: #f8f9fd; border: 1.5px solid var(--border);
            border-radius: 12px; padding: 16px 24px; margin-bottom: 28px;
        }
        .wf-step { display: flex; align-items: center; gap: 10px; flex: 1; }
        .wf-step-icon {
            width: 40px; height: 40px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 1rem; font-weight: 700; flex-shrink: 0;
        }
        .wf-step-icon.done   { background: var(--success); color: #fff; }
        .wf-step-icon.active { background: var(--primary);  color: #fff; }
        .wf-step-icon.idle   { background: #e0e4ef;          color: #aaa; }
        .wf-step-text   { font-size: 0.85rem; color: #555; }
        .wf-step-text strong { display: block; color: #222; font-size: 0.9rem; }
        .wf-arrow { color: #bbb; font-size: 1.2rem; margin: 0 12px; }

        /* ── Credentials box ── */
        .credentials-box {
            border: 1.5px solid var(--border); border-left: 4px solid var(--primary);
            border-radius: var(--radius); background: #fff; margin-bottom: 24px; overflow: hidden;
        }
        .credentials-box__header {
            background: var(--primary-light); color: var(--primary); font-weight: 700;
            font-size: 0.85rem; padding: 9px 16px; display: flex; align-items: center;
            gap: 6px; border-bottom: 1px solid #d5dcff;
        }
        .credentials-box__note { margin-left: auto; font-size: 0.73rem; font-weight: 500; color: #888; }
        .credentials-box__body { padding: 12px 16px; display: flex; flex-direction: column; gap: 8px; }
        .cred-row {
            display: flex; align-items: center; gap: 10px; padding: 8px 12px;
            border-radius: 6px; background: #f8f9fd; border: 1px solid var(--border);
        }
        .cred-label { font-size: 0.75rem; font-weight: 700; color: #999; min-width: 90px; text-transform: uppercase; letter-spacing: 0.05em; }
        .cred-value { font-size: 0.9rem; color: #333; font-weight: 600; flex: 1; }
        .cred-password { font-family: 'Courier New', monospace; font-size: 0.93rem; color: var(--primary); letter-spacing: 1.5px; }
        .cred-copy { background: transparent; border: 1px solid var(--border); color: #aaa; border-radius: 5px; padding: 3px 9px; font-size: 0.78rem; cursor: pointer; transition: all 0.15s; }
        .cred-copy:hover { border-color: var(--primary); color: var(--primary); background: var(--primary-light); }

        /* ── Success panel (after account created) ── */
        .success-panel {
            border: 2px solid var(--success); border-radius: 12px;
            background: #f0fff4; padding: 24px; margin-bottom: 28px;
        }
        .success-panel h5 { color: var(--success); font-weight: 700; margin-bottom: 16px; }
        .action-buttons { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 20px; }
        .btn-send-email {
            background: var(--primary); color: #fff; border: none;
            border-radius: var(--radius); padding: 11px 24px; font-weight: 700;
            font-size: 0.95rem; cursor: pointer; text-decoration: none;
            display: inline-flex; align-items: center; gap: 8px; transition: background 0.2s;
        }
        .btn-send-email:hover { background: #1a3de0; color: #fff; text-decoration: none; }
        .btn-new-onboard {
            background: #fff; color: var(--primary); border: 2px solid var(--primary);
            border-radius: var(--radius); padding: 11px 24px; font-weight: 700;
            font-size: 0.95rem; cursor: pointer; text-decoration: none;
            display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s;
        }
        .btn-new-onboard:hover { background: var(--primary-light); text-decoration: none; }

        /* ── Form time pickers ── */
        .time-picker-wrap { position: relative; }
        .time-picker-wrap input[type="text"] {
            cursor: pointer;
            background: #fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%232f55ff' viewBox='0 0 16 16'%3E%3Cpath d='M8 3.5a.5.5 0 0 0-1 0V9a.5.5 0 0 0 .252.434l3.5 2a.5.5 0 0 0 .496-.868L8 8.71V3.5z'/%3E%3Cpath d='M8 16A8 8 0 1 0 8 0a8 8 0 0 0 0 16zm7-8A7 7 0 1 1 1 8a7 7 0 0 1 14 0z'/%3E%3C/svg%3E") no-repeat right 12px center;
            padding-right: 36px;
        }
        .clock-dropdown {
            display: none; position: absolute; top: calc(100% + 6px); left: 0;
            z-index: 9999; background: #fff; border: 1.5px solid var(--border);
            border-radius: 12px; box-shadow: 0 8px 32px rgba(47,85,255,0.13);
            padding: 18px 20px 14px; width: 260px; user-select: none;
        }
        .clock-dropdown.open { display: block; }
        .clock-face { width: 170px; height: 170px; border-radius: 50%; background: var(--primary-light); border: 2px solid var(--border); position: relative; margin: 0 auto 14px; cursor: pointer; }
        .clock-center { position: absolute; top: 50%; left: 50%; width: 8px; height: 8px; background: var(--primary); border-radius: 50%; transform: translate(-50%, -50%); z-index: 3; }
        .clock-hand { position: absolute; bottom: 50%; left: 50%; transform-origin: bottom center; transform: translateX(-50%) rotate(0deg); background: var(--primary); border-radius: 4px 4px 0 0; width: 3px; z-index: 2; transition: transform 0.15s ease; }
        .clock-hand.hour-hand { height: 52px; }
        .clock-hand.minute-hand { height: 68px; width: 2px; background: #555; }
        .clock-number { position: absolute; width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; font-size: 0.75rem; font-weight: 600; border-radius: 50%; cursor: pointer; color: #444; transition: background 0.15s, color 0.15s; transform: translate(-50%, -50%); }
        .clock-number:hover, .clock-number.active { background: var(--primary); color: #fff; }
        .clock-tabs { display: flex; gap: 6px; justify-content: center; margin-bottom: 10px; }
        .clock-tab { padding: 4px 16px; border-radius: 20px; border: 1.5px solid var(--border); font-size: 0.82rem; font-weight: 600; cursor: pointer; background: #fff; color: #555; transition: all 0.15s; }
        .clock-tab.active { background: var(--primary); color: #fff; border-color: var(--primary); }
        .clock-time-display { text-align: center; font-size: 1.5rem; font-weight: 700; color: var(--primary); letter-spacing: 2px; margin-bottom: 8px; }
        .clock-confirm { width: 100%; background: var(--primary); color: #fff; border: none; border-radius: 6px; padding: 7px; font-weight: 600; cursor: pointer; margin-top: 4px; }
        .clock-confirm:hover { background: #1a3de0; }
        .ampm-toggle { display: flex; flex-direction: column; gap: 4px; }
        .ampm-btn { padding: 4px 10px; border-radius: 5px; border: 1.5px solid var(--border); background: #fff; color: #888; font-size: 0.75rem; font-weight: 700; cursor: pointer; transition: all 0.15s; }
        .ampm-btn.active { background: var(--primary); color: #fff; border-color: var(--primary); }

        /* ── Mode / Branch / Stipend toggles ── */
        .mode-toggle, .branch-toggle, .stipend-options { display: flex; gap: 10px; }
        .mode-btn, .branch-btn { flex: 1; padding: 9px; border: 2px solid var(--border); border-radius: var(--radius); background: #fff; color: #555; font-weight: 600; font-size: 0.9rem; cursor: pointer; text-align: center; transition: all 0.2s; }
        .mode-btn.selected, .branch-btn.selected { border-color: var(--primary); background: var(--primary); color: #fff; font-weight: 700; }
        .stipend-opt { flex: 1; padding: 9px; border: 2px solid var(--border); border-radius: var(--radius); background: #fff; color: #555; font-weight: 600; font-size: 0.9rem; cursor: pointer; text-align: center; transition: all 0.2s; }
        .stipend-opt.selected-yes { border-color: var(--success); background: var(--success); color: #fff; font-weight: 700; }
        .stipend-opt.selected-no  { border-color: #e53935; background: #e53935; color: #fff; font-weight: 700; }
        .stipend-amount-wrap { display: none; margin-top: 10px; }
        .stipend-amount-wrap.visible { display: block; }
        .training-info-box { border: 1.5px solid #d5dcff; border-left: 4px solid var(--primary); border-radius: var(--radius); background: var(--primary-light); padding: 12px 16px; margin-top: 10px; display: none; }
        .training-info-box.visible { display: flex; align-items: center; gap: 10px; }
        .training-info-box i { color: var(--primary); font-size: 1rem; }
        .training-info-box span { font-size: 0.87rem; color: #333; font-weight: 500; }

        select.form-control {
            appearance: none; -webkit-appearance: none;
            background: #fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%232f55ff' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E") no-repeat right 13px center;
            padding-right: 36px; cursor: pointer; color: #222 !important;
        }
        select.form-control option { color: #222; }
        select.form-control option[value=""][disabled] { color: #aaa; }

        .candidate-badge { display: inline-flex; align-items: center; gap: 5px; background: #f0fff4; border: 1px solid #c3e6cb; color: #28a745; font-size: 0.75rem; font-weight: 600; padding: 3px 10px; border-radius: 20px; margin-top: 6px; }
        .no-candidate-badge { display: inline-flex; align-items: center; gap: 5px; background: #fff5f5; border: 1px solid #f5c6cb; color: #e53935; font-size: 0.75rem; font-weight: 600; padding: 3px 10px; border-radius: 20px; margin-top: 6px; }
        .intern-id-note { font-size: 0.78rem; color: #6c757d; margin-top: 4px; display: flex; align-items: center; gap: 5px; }
        .intern-id-note i { color: var(--primary); }
        .history-btn { background: var(--primary); color: #fff; border: none; border-radius: var(--radius); padding: 8px 18px; font-weight: 600; font-size: 0.9rem; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 7px; transition: background 0.2s; }
        .history-btn:hover { background: #1a3de0; color: #fff; text-decoration: none; }
        .select2-container .select2-selection--single { height: 46px !important; border: 1.5px solid var(--border) !important; border-radius: 8px !important; padding: 8px 10px !important; }
        .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 28px !important; color: #222 !important; }
        .select2-container--default .select2-selection--single .select2-selection__arrow { height: 44px !important; }
        .select2-dropdown { border-radius: 8px !important; border: 1.5px solid var(--border) !important; }
    </style>
</head>
<body>
<div class="container-scroller">
    <?php include('../partials/_navbar.php'); ?>
    <div class="container-fluid page-body-wrapper">
        <?php include('../partials/_sidebar.php'); include('../partials/_settings-panel.php'); ?>
        <div class="main-panel">
        <div class="content-wrapper">

        <?php if (isset($_SESSION['swal'])): ?>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            Swal.fire({
                icon: '<?= $_SESSION['swal']['icon'] ?>',
                title: '<?= addslashes($_SESSION['swal']['title']) ?>',
                text: '<?= addslashes($_SESSION['swal']['text']) ?>',
                confirmButtonColor: '#4B49AC'
            });
        });
        </script>
        <?php unset($_SESSION['swal']); endif; ?>

        <?php if (!empty($error)): ?>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            Swal.fire({ icon: 'error', title: 'Error', text: '<?= addslashes($error) ?>', confirmButtonColor: '#4B49AC' });
        });
        </script>
        <?php endif; ?>

        <div class="row">
        <div class="col-12 grid-margin stretch-card">
        <div class="card">
        <div class="card-body">

            <div class="d-flex align-items-center justify-content-between mb-3">
                <h4 class="card-title mb-0">
                    <i class="fa-solid fa-user-plus me-2"></i> Intern Onboarding
                </h4>
                <a href="onboarding_history.php" class="history-btn">
                    <i class="fa-solid fa-clock-rotate-left"></i> View History
                </a>
            </div>

            <p><strong>HR / Admin:</strong> <span style="color:var(--primary);"><?= $userName ?></span></p>

            <!-- ── Workflow Steps Banner ── -->
            <div class="workflow-steps">
                <div class="wf-step">
                    <div class="wf-step-icon <?= $stepDone ? 'done' : 'active' ?>">
                        <?= $stepDone ? '<i class="fa-solid fa-check"></i>' : '1' ?>
                    </div>
                    <div class="wf-step-text">
                        <strong>Create Account</strong>
                        Fill form &amp; generate credentials
                    </div>
                </div>
                <div class="wf-arrow"><i class="fa-solid fa-chevron-right"></i></div>
                <div class="wf-step">
                    <div class="wf-step-icon <?= $stepDone ? 'done' : 'idle' ?>">
                        <?= $stepDone ? '<i class="fa-solid fa-check"></i>' : '2' ?>
                    </div>
                    <div class="wf-step-text">
                        <strong>View Credentials</strong>
                        userId, internId, username, password
                    </div>
                </div>
                <div class="wf-arrow"><i class="fa-solid fa-chevron-right"></i></div>
                <div class="wf-step">
                    <div class="wf-step-icon <?= $stepDone ? 'active' : 'idle' ?>">3</div>
                    <div class="wf-step-text">
                        <strong>Send Email</strong>
                        Prefilled offer letter template
                    </div>
                </div>
            </div>

            <?php if ($stepDone && $lastOnboarded): ?>
            <!-- ════════════════════════════════════════
                 STEP 2 + 3: Show credentials & Send Email
                 ════════════════════════════════════════ -->
            <div class="success-panel">
                <h5><i class="fa-solid fa-circle-check me-2"></i> Account Created Successfully!</h5>

                <div class="credentials-box">
                    <div class="credentials-box__header">
                        <i class="fa-solid fa-key"></i> Generated Login Credentials
                        <span class="credentials-box__note">Stored in database &amp; ready to send</span>
                    </div>
                    <div class="credentials-box__body">
                        <div class="cred-row">
                            <span class="cred-label"><i class="fa-solid fa-hashtag me-1"></i> User ID</span>
                            <span class="cred-value"><?= htmlspecialchars($lastOnboarded['userId']) ?></span>
                            <button type="button" class="cred-copy" onclick="copyText('<?= htmlspecialchars($lastOnboarded['userId']) ?>', this)"><i class="fa-solid fa-copy"></i></button>
                        </div>
                        <div class="cred-row">
                            <span class="cred-label"><i class="fa-solid fa-id-card me-1"></i> Intern ID</span>
                            <span class="cred-value"><?= htmlspecialchars($lastOnboarded['internId']) ?></span>
                            <button type="button" class="cred-copy" onclick="copyText('<?= htmlspecialchars($lastOnboarded['internId']) ?>', this)"><i class="fa-solid fa-copy"></i></button>
                        </div>
                        <div class="cred-row">
                            <span class="cred-label"><i class="fa-solid fa-envelope me-1"></i> Username</span>
                            <span class="cred-value"><?= htmlspecialchars($lastOnboarded['username']) ?></span>
                            <button type="button" class="cred-copy" onclick="copyText('<?= htmlspecialchars($lastOnboarded['username']) ?>', this)"><i class="fa-solid fa-copy"></i></button>
                        </div>
                        <div class="cred-row">
                            <span class="cred-label"><i class="fa-solid fa-lock me-1"></i> Password</span>
                            <span class="cred-value cred-password"><?= htmlspecialchars($lastOnboarded['password']) ?></span>
                            <button type="button" class="cred-copy" onclick="copyText('<?= htmlspecialchars($lastOnboarded['password'], ENT_QUOTES) ?>', this)"><i class="fa-solid fa-copy"></i></button>
                        </div>
                        <div class="cred-row">
                            <span class="cred-label"><i class="fa-solid fa-briefcase me-1"></i> Role</span>
                            <span class="cred-value"><?= htmlspecialchars($lastOnboarded['role']) ?></span>
                        </div>
                        <div class="cred-row">
                            <span class="cred-label"><i class="fa-solid fa-calendar me-1"></i> Joining</span>
                            <span class="cred-value"><?= date('d M Y', strtotime($lastOnboarded['doj'])) ?> → <?= date('d M Y', strtotime($lastOnboarded['end_date'])) ?></span>
                        </div>
                        <div class="cred-row">
                            <span class="cred-label"><i class="fa-solid fa-layer-group me-1"></i> Batch</span>
                            <span class="cred-value"><?= htmlspecialchars($lastOnboarded['batch']) ?></span>
                        </div>
                        <?php if (!empty($lastOnboarded['mentor'])): ?>
                        <div class="cred-row">
                            <span class="cred-label"><i class="fa-solid fa-user-tie me-1"></i> Mentor</span>
                            <span class="cred-value"><?= htmlspecialchars($lastOnboarded['mentor']) ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="action-buttons">
                    <a href="send_offer_email.php" class="btn-send-email">
                        <i class="fa-solid fa-paper-plane"></i> Send Offer Email
                    </a>
                    <!-- Clears ALL session state and starts fresh -->
                    <a href="onBoarding.php?clear_draft=1" class="btn-new-onboard">
                        <i class="fa-solid fa-plus"></i> Onboard Another Intern
                    </a>
                    <a href="onboarding_history.php" class="btn-new-onboard" style="border-color:#6c757d;color:#6c757d;">
                        <i class="fa-solid fa-clock-rotate-left"></i> View History
                    </a>
                </div>
            </div>

            <?php else: ?>
            <!-- ════════════════════════════════════════
                 STEP 1: Onboarding Form
                 ════════════════════════════════════════ -->

            <?php if (!empty($draft)): ?>
            <!-- Draft-restored banner -->
            <div class="draft-banner">
                <i class="fa-solid fa-clock-rotate-left"></i>
                <span>
                    <strong>Draft restored.</strong>
                    Your previously entered details have been reloaded automatically.
                    The pre-generated password is still the same — no need to regenerate.
                </span>
                <a href="onBoarding.php?clear_draft=1" title="Discard draft and start fresh">
                    <i class="fa-solid fa-trash-can me-1"></i> Discard &amp; Start Fresh
                </a>
            </div>
            <?php endif; ?>

            <div class="section-title">Intern Information</div>

            <form method="POST" id="onboardingForm">

                <!-- Row 1: Intern ID, Name & Email -->
                <div class="row">
                    <div class="col-md-4">
                    <div class="form-group mb-3">
                        <label>Intern ID</label>
                        <input type="text" class="form-control" name="intern_id" id="fieldInternId"
                               placeholder="Leave blank for auto-generation"
                               value="<?= $draftInternId ?>">
                        <div class="intern-id-note">
                            <i class="fa-solid fa-circle-info"></i>
                            <span>Leave blank to auto-generate (e.g., INT2026001)</span>
                        </div>
                    </div>
                    </div>
                    <div class="col-md-4">
                    <div class="form-group mb-3">
                        <label>Full Name <span class="required">*</span></label>
                        <?php if (!empty($approvedCandidates)): ?>
                            <select class="form-control" name="fullname" id="fieldFullname" required>
                                <option value="" disabled <?= empty($draftFullname) ? 'selected' : '' ?>>-- Select Approved Candidate --</option>
                                <?php foreach ($approvedCandidates as $ac): ?>
                                    <option value="<?= htmlspecialchars($ac['applicantName'], ENT_QUOTES) ?>"
                                            data-email="<?= htmlspecialchars($ac['email'], ENT_QUOTES) ?>"
                                            <?= ($draftFullname === htmlspecialchars($ac['applicantName'], ENT_QUOTES)) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($ac['applicantName'], ENT_QUOTES) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <span class="candidate-badge">
                                <i class="fa-solid fa-circle-check"></i>
                                <?= count($approvedCandidates) ?> HR-approved candidate(s)
                            </span>
                        <?php else: ?>
<input type="text" class="form-control" name="fullname" id="fieldFullname"
       required placeholder="No approved candidates — enter manually"
       value="<?= $draftFullname ?>"
       pattern="[a-zA-Z\s.-]+"
       title="Name must contain only letters, spaces, dots or hyphens"
       oninput="this.value = this.value.replace(/[^a-zA-Z\s.-]/g, '')">
                            <span class="no-candidate-badge">
                                <i class="fa-solid fa-triangle-exclamation"></i>
                                No HR-approved candidates pending
                            </span>
                        <?php endif; ?>
                    </div>
                    </div>
                    <div class="col-md-4">
                    <div class="form-group mb-3">
                        <label>Email <span class="required">*</span></label>
                        <input type="email" class="form-control" name="email" id="fieldEmail"
                               required placeholder="Auto-filled on candidate selection"
                               value="<?= $draftEmail ?>">
                    </div>
                    </div>
                </div>

                <!-- Row 2: Role & Tech Stack -->
                <div class="row">
                    <div class="col-md-6">
                    <div class="form-group mb-3">
                        <label>Role / Designation <span class="required">*</span></label>
                        <select class="form-control" name="role" id="roleSelect" required>
                            <option value="" disabled <?= empty($draftRole) ? 'selected' : '' ?>>-- Select Role --</option>
                            <?php foreach ($roles as $r): ?>
                                <option value="<?= $r ?>" <?= ($draftRole === $r) ? 'selected' : '' ?>><?= $r ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    </div>
                    <div class="col-md-6">
                    <div class="form-group mb-3">
                        <label>Technical Stack</label>
                        <input type="text" class="form-control" name="tech_stack" id="techStack"
                               placeholder="Auto-filled from role, you can edit"
                               value="<?= $draftTechStack ?>">
                    </div>
                    </div>
                </div>

                <!-- Row 3: DOJ & End Date -->
                <div class="row">
                    <div class="col-md-6">
                    <div class="form-group mb-3">
                        <label>Date of Joining (start_date) <span class="required">*</span></label>
                        <input type="date" class="form-control" name="doj" id="dojField"
                               required value="<?= $draftDoj ?>">
                    </div>
                    </div>
                    <div class="col-md-6">
                    <div class="form-group mb-3">
                        <label>Expected End Date <span class="required">*</span></label>
                        <input type="date" class="form-control" name="end_date" id="endDateField"
                               required value="<?= $draftEndDate ?>">
                    </div>
                    </div>
                </div>

                <!-- Row 4: Punch In & Out -->
                <div class="row">
                    <div class="col-md-6">
                    <div class="form-group mb-3">
                        <label>Punch In Time (expectedPunchIn / shift_in) <span class="required">*</span></label>
                        <input type="time" class="form-control" name="punch_in" id="punchInValue" required value="<?= $draftPunchIn ?>">
                    </div>
                    </div>
                    <div class="col-md-6">
                    <div class="form-group mb-3">
                        <label>Punch Out Time (expectedPunchOut / shift_out) <span class="required">*</span></label>
                        <input type="time" class="form-control" name="punch_out" id="punchOutValue" required value="<?= $draftPunchOut ?>">
                    </div>
                    </div>
                </div>

                <!-- Row 5: Batch & Mentor -->
                <div class="row">
                    <div class="col-md-6">
                    <div class="form-group mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="mb-0">Batch Allocation (batch_number) <span class="required">*</span></label>
                            <button type="button" class="btn btn-sm btn-primary" style="font-size:0.75rem;padding:4px 10px;" onclick="showCreateBatchModal()">
                                <i class="fa-solid fa-plus me-1"></i>Create Batch
                            </button>
                        </div>
                        <select class="form-control" name="batch" required>
                            <option value="" disabled <?= empty($draftBatch) ? 'selected' : '' ?>>-- Select Batch --</option>
                            <?php if (empty($batches)): ?>
                                <option disabled>No active batches</option>
                            <?php else: ?>
                                <?php foreach ($batches as $batch): ?>
                                    <option value="<?= htmlspecialchars($batch['batchName']) ?>"
                                            <?= ($draftBatch === htmlspecialchars($batch['batchName'])) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($batch['batchName']) ?> (<?= htmlspecialchars($batch['batchCode']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    </div>
                    <div class="col-md-6">
                    <div class="form-group mb-3">
                        <label>Mentor / Colleague <span class="required">*</span></label>
                        <select class="form-control searchable-select" name="approved_by" required>
                            <option value="" disabled <?= empty($draftMentorId) ? 'selected' : '' ?>>-- Search &amp; Select Mentor --</option>
                            <?php if (empty($mentors)): ?>
                                <option disabled>No mentors found</option>
                            <?php else: ?>
                                <?php foreach ($mentors as $mentor): ?>
                                    <option value="<?= $mentor['userId'] ?>"
                                            <?= ($draftMentorId == $mentor['userId']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($mentor['fullName']) ?>
                                        (<?= htmlspecialchars($mentor['employment_type']) ?> - <?= htmlspecialchars($mentor['designation']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    </div>
                </div>

                <!-- Row 6: Branch & Mode -->
                <div class="row">
                    <div class="col-md-6">
                    <div class="form-group mb-3">
                        <label>Branch / Location <span class="required">*</span></label>
                        <div class="branch-toggle">
                            <div class="branch-btn <?= ($draftBranch === 'Pune' || empty($draftBranch)) ? 'selected' : '' ?>"
                                 id="branchPune" onclick="selectBranch('Pune')">
                                <i class="fa-solid fa-city me-1"></i> Pune
                            </div>
                            <div class="branch-btn <?= ($draftBranch === 'Kinwat') ? 'selected' : '' ?>"
                                 id="branchKinwat" onclick="selectBranch('Kinwat')">
                                <i class="fa-solid fa-building me-1"></i> Kinwat
                            </div>
                        </div>
                        <input type="hidden" name="branch" id="branchValue"
                               value="<?= (!empty($draftBranch) ? $draftBranch : 'Pune') ?>">
                    </div>
                    </div>
                    <div class="col-md-6">
                    <div class="form-group mb-3">
                        <label>Work Mode <span class="required">*</span></label>
                        <div class="mode-toggle">
                            <div class="mode-btn <?= ($draftMode === 'Online' || empty($draftMode)) ? 'selected' : '' ?>"
                                 id="modeOnline" onclick="selectMode('Online')">
                                <i class="fa-solid fa-wifi"></i> Online
                            </div>
                            <div class="mode-btn <?= ($draftMode === 'Offline') ? 'selected' : '' ?>"
                                 id="modeOffline" onclick="selectMode('Offline')">
                                <i class="fa-solid fa-building"></i> Offline
                            </div>
                        </div>
                        <input type="hidden" name="mode" id="modeValue"
                               value="<?= (!empty($draftMode) ? $draftMode : 'Online') ?>">
                    </div>
                    </div>
                </div>

                <!-- Row 7: Stipend -->
                <div class="row">
                    <div class="col-md-6">
                    <div class="form-group mb-3">
                        <label>Stipend <span class="required">*</span></label>
                        <div class="stipend-options">
                            <div class="stipend-opt <?= ($draftStipend === 'yes') ? 'selected-yes' : '' ?>"
                                 id="stipendYes" onclick="selectStipend('yes')">
                                <i class="fa-solid fa-circle-check me-1"></i> Yes
                            </div>
                            <div class="stipend-opt <?= ($draftStipend === 'no') ? 'selected-no' : '' ?>"
                                 id="stipendNo" onclick="selectStipend('no')">
                                <i class="fa-solid fa-circle-xmark me-1"></i> No
                            </div>
                        </div>
                        <input type="hidden" name="stipend" id="stipendValue" value="<?= $draftStipend ?>">
                        <div class="stipend-amount-wrap <?= ($draftStipend === 'yes') ? 'visible' : '' ?>"
                             id="stipendAmountWrap">
                            <input type="number" class="form-control mt-2" name="stipend_amount" id="stipendAmount"
                                   placeholder="Enter stipend amount (₹)" min="0" step="100"
                                   value="<?= $draftStipendAmt ?>"
                                   <?= ($draftStipend === 'yes') ? 'required' : '' ?>>
                        </div>
                    </div>
                    </div>
                </div>

                <!-- Submit -->
                <div class="row mt-4">
                    <div class="col-md-12 text-end">
                        <button type="submit" name="create_account" class="btn btn-primary px-5" id="createAccountBtn">
                            <i class="fa-solid fa-user-plus me-2"></i> Create Account &amp; Generate Credentials
                        </button>
                    </div>
                </div>

            </form>
            <?php endif; ?>

        </div>
        </div>
        </div>
        </div>

        </div>
        </div>
    </div>
</div>

<script src="../vendors/js/vendor.bundle.base.js"></script>
<script src="../js/off-canvas.js"></script>
<script src="../js/hoverable-collapse.js"></script>
<script src="../js/template.js"></script>
<script src="../js/settings.js"></script>

<script>
// ── PHP-hydrated draft values (for JS-driven fields) ──────────────
var DRAFT = {
    mode:         <?= json_encode($draftMode ?: 'Online') ?>,
    branch:       <?= json_encode($draftBranch ?: 'Pune') ?>,
    stipend:      <?= json_encode($draftStipend) ?>,
    stipendAmt:   <?= json_encode($draftStipendAmt) ?>,
    punchIn:      <?= json_encode($draftPunchIn) ?>,
    punchInDisp:  <?= json_encode($draftPunchInDisp) ?>,
    punchOut:     <?= json_encode($draftPunchOut) ?>,
    punchOutDisp: <?= json_encode($draftPunchOutDisp) ?>,
};

// ── Candidate email auto-fill ──────────────────────────────────────
var approvedMap = {};
<?php foreach ($approvedCandidates as $ac): ?>
approvedMap[<?= json_encode($ac['applicantName']) ?>] = <?= json_encode($ac['email']) ?>;
<?php endforeach; ?>

var nameField = document.getElementById('fieldFullname');
if (nameField) {
    nameField.addEventListener('change', function () {
        var selectedName = this.value.trim();
        var emailField = document.getElementById('fieldEmail');
        if (approvedMap[selectedName]) {
            emailField.value = approvedMap[selectedName];
            emailField.style.borderColor = '#28a745';
            setTimeout(() => { emailField.style.borderColor = ''; }, 1500);
        } else {
            emailField.value = '';
        }
    });
}

// ── Copy helper ────────────────────────────────────────────────────
function copyText(text, btn) {
    navigator.clipboard.writeText(text).then(function () {
        var orig = btn.innerHTML;
        btn.innerHTML = '<i class="fa-solid fa-check"></i>';
        btn.style.color = '#28a745';
        setTimeout(() => { btn.innerHTML = orig; btn.style.color = ''; }, 2000);
    });
}

// ── Role → Tech Stack ──────────────────────────────────────────────
var stackMap = <?= json_encode($roleStack) ?>;
var roleSelect = document.getElementById('roleSelect');
if (roleSelect) {
    roleSelect.addEventListener('change', function () {
        var techField = document.getElementById('techStack');
        // Only auto-fill if field is empty or still matches the previous role's stack
        if (!techField.value || Object.values(stackMap).indexOf(techField.value) !== -1) {
            techField.value = stackMap[this.value] || '';
        }
    });
}

// ── Branch & Mode toggles ─────────────────────────────────────────
function selectBranch(val) {
    document.getElementById('branchValue').value = val;
    document.getElementById('branchPune').classList.toggle('selected',   val === 'Pune');
    document.getElementById('branchKinwat').classList.toggle('selected', val === 'Kinwat');
}
function selectMode(val) {
    document.getElementById('modeValue').value = val;
    document.getElementById('modeOnline').classList.toggle('selected',  val === 'Online');
    document.getElementById('modeOffline').classList.toggle('selected', val === 'Offline');
}

// ── Stipend toggles ───────────────────────────────────────────────
function selectStipend(val) {
    document.getElementById('stipendValue').value = val;
    document.getElementById('stipendYes').className = 'stipend-opt' + (val === 'yes' ? ' selected-yes' : '');
    document.getElementById('stipendNo').className  = 'stipend-opt' + (val === 'no'  ? ' selected-no'  : '');
    var amountWrap  = document.getElementById('stipendAmountWrap');
    var amountInput = document.getElementById('stipendAmount');
    if (val === 'yes') {
        amountWrap.classList.add('visible');
        amountInput.required = true;
    } else {
        amountWrap.classList.remove('visible');
        amountInput.required = false;
        amountInput.value = '';
    }
    updateTrainingInfo();
}

function updateTrainingInfo() {
    var stipendVal = document.getElementById('stipendValue').value;
    var doj        = document.getElementById('dojField') ? document.getElementById('dojField').value : '';
    var infoBox    = document.getElementById('trainingInfoBox');
    var infoText   = document.getElementById('trainingInfoText');
    if (!stipendVal || !infoBox) return;
    infoBox.classList.remove('visible');
    var days = stipendVal === 'yes' ? 8 : 15;
    var type = stipendVal === 'yes' ? 'Stipend-based' : 'Mentor-led (free)';
    var dateStr = '';
    if (doj) {
        var endDate = new Date(doj);
        endDate.setDate(endDate.getDate() + days);
        var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        dateStr = ' → ends <strong>' + String(endDate.getDate()).padStart(2,'0') + ' ' + months[endDate.getMonth()] + ' ' + endDate.getFullYear() + '</strong>';
    }
    if (infoText) infoText.innerHTML = '<strong>' + type + '</strong> — Training: <strong>' + days + ' days</strong>' + dateStr;
    infoBox.classList.add('visible');
}

var dojField = document.getElementById('dojField');
var endDateField = document.getElementById('endDateField');
if (dojField) {
    dojField.addEventListener('change', function() {
        updateTrainingInfo();
        if (endDateField) {
            endDateField.min = this.value;
            if (endDateField.value && endDateField.value < this.value) {
                endDateField.value = '';
            }
        }
    });
    // Trigger change on load to set initial min
    if (dojField.value && endDateField) {
        endDateField.min = dojField.value;
    }
}

// ── Form validation ───────────────────────────────────────────────
var onboardingForm = document.getElementById('onboardingForm');
if (onboardingForm) {
onboardingForm.addEventListener('submit', function (e) {
        // ── Date validation ───────────────────────────────────────
        var startDateVal = document.getElementById('dojField').value;
        var endDateVal = document.querySelector('input[name="end_date"]').value;
        if (startDateVal && endDateVal && startDateVal > endDateVal) {
            e.preventDefault();
            Swal.fire({ icon: 'warning', title: 'Invalid Dates', text: 'Start Date cannot be after Expected End Date.', confirmButtonColor: '#4B49AC' });
            return;
        }

        // ── Email validation ──────────────────────────────────────
        var emailVal = document.getElementById('fieldEmail').value.trim();
        var emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailVal) {
            e.preventDefault();
            Swal.fire({ icon: 'warning', title: 'Required', text: 'Please enter the intern\'s email address.', confirmButtonColor: '#4B49AC' });
            return;
        }
        if (!emailPattern.test(emailVal)) {
            e.preventDefault();
            Swal.fire({ icon: 'warning', title: 'Invalid Email', text: 'Please enter a valid email address (e.g. name@domain.com).', confirmButtonColor: '#4B49AC' });
            return;
        }

        // ── Full Name validation (free-text input only) ───────────
        var nameField = document.getElementById('fieldFullname');
        if (nameField && nameField.tagName === 'INPUT') {
            var nameVal = nameField.value.trim();
            var namePattern = /^[a-zA-Z\s.-]+$/;
            if (!nameVal) {
                e.preventDefault();
                Swal.fire({ icon: 'warning', title: 'Required', text: 'Please enter the intern\'s full name.', confirmButtonColor: '#4B49AC' });
                return;
            }
            if (!namePattern.test(nameVal)) {
                e.preventDefault();
                Swal.fire({ icon: 'warning', title: 'Invalid Name', text: 'Full name must contain only letters, spaces, dots, or hyphens. Numbers are not allowed.', confirmButtonColor: '#4B49AC' });
                return;
            }
        }

        var stipendVal = document.getElementById('stipendValue').value;
        if (!stipendVal) {
            e.preventDefault();
            Swal.fire({ icon:'warning', title:'Required', text:'Please select Stipend (Yes or No).', confirmButtonColor:'#4B49AC' });
            return;
        }
        if (stipendVal === 'yes' && !document.getElementById('stipendAmount').value) {
            e.preventDefault();
            Swal.fire({ icon:'warning', title:'Required', text:'Please enter the stipend amount.', confirmButtonColor:'#4B49AC' });
            return;
        }
        if (!document.getElementById('punchInValue').value) {
            e.preventDefault();
            Swal.fire({ icon:'warning', title:'Required', text:'Please select Punch In time.', confirmButtonColor:'#4B49AC' });
            return;
        }
        if (!document.getElementById('punchOutValue').value) {
            e.preventDefault();
            Swal.fire({ icon:'warning', title:'Required', text:'Please select Punch Out time.', confirmButtonColor:'#4B49AC' });
            return;
        }
        // Mentor validation
var mentorSelect = document.querySelector('[name="approved_by"]');
if (!mentorSelect || !mentorSelect.value) {
    e.preventDefault();
    Swal.fire({ icon: 'warning', title: 'Required', text: 'Please select a Mentor / Colleague.', confirmButtonColor: '#4B49AC' });
    return;
}
        var pIn  = document.getElementById('punchInValue').value;
        var pOut = document.getElementById('punchOutValue').value;
        if (pIn && pOut && pOut <= pIn) {
            e.preventDefault();
            Swal.fire({ icon:'warning', title:'Invalid Time', text:'Punch Out must be after Punch In.', confirmButtonColor:'#4B49AC' });
            return;
        }
    });
}



// ── Run stipend / training info init for restored drafts ──────────
(function initRestoredDraft() {
    // Stipend amount visibility (PHP handles the initial class, but
    // required attribute needs to be set via JS too)
    var sv = document.getElementById('stipendValue');
    if (sv && sv.value === 'yes') {
        var ai = document.getElementById('stipendAmount');
        if (ai) ai.required = true;
    }
    // Training info box
    if (sv && sv.value) updateTrainingInfo();
})();
</script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
$(document).ready(function () {
    $('.searchable-select').select2({
        placeholder: "Search & Select Mentor",
        allowClear: true,
        width: '100%'
    });

    // ── Restore Select2 mentor value from draft ──
    var draftMentorId = <?= json_encode($draftMentorId) ?>;
    if (draftMentorId) {
        $('.searchable-select').val(draftMentorId).trigger('change');
    }

    // ── Submit create batch via AJAX ──
    $('#btnSubmitModalBatch').on('click', function() {
        var batchName = $('#modalBatchName').val().trim();
        var batchCode = $('#modalBatchCode').val().trim();
        var startDate = $('#modalStartDate').val();
        var endDate = $('#modalEndDate').val();
        var batchLocation = $('#modalBatchLocation').val();
        var batchMode = $('#modalBatchMode').val();
        var description = $('#modalDescription').val().trim();

        if (!batchName || !batchCode || !startDate || !endDate || !batchLocation || !batchMode) {
            Swal.fire({
                icon: 'warning',
                title: 'Required Fields Missing',
                text: 'Please fill in all mandatory fields.',
                confirmButtonColor: '#4B49AC'
            });
            return;
        }

        var today = new Date().toISOString().split('T')[0];
        if (startDate < today) {
            Swal.fire({
                icon: 'warning',
                title: 'Invalid Date',
                text: 'Start Date cannot be in the past.',
                confirmButtonColor: '#4B49AC'
            });
            return;
        }

        if (endDate <= startDate) {
            Swal.fire({
                icon: 'warning',
                title: 'Invalid Date',
                text: 'End Date must be after Start Date.',
                confirmButtonColor: '#4B49AC'
            });
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin me-1"></i> Creating...');

        $.ajax({
            url: 'batch_master.php?ajax_create_batch=1',
            type: 'POST',
            data: {
                batchName: batchName,
                batchCode: batchCode,
                startDate: startDate,
                endDate: endDate,
                batchLocation: batchLocation,
                batchMode: batchMode,
                description: description
            },
            dataType: 'json',
            success: function(response) {
                $btn.prop('disabled', false).html('<i class="fa-solid fa-plus me-1"></i> Create Batch');
                if (response.success) {
                    $('#createBatchModal').modal('hide');
                    
                    // Clear inputs
                    $('#modalBatchName').val('');
                    $('#modalBatchCode').val('');
                    $('#modalStartDate').val('');
                    $('#modalEndDate').val('');
                    $('#modalBatchLocation').val('');
                    $('#modalBatchMode').val('Online');
                    $('#modalDescription').val('');

                    // Add new option to dropdown and select it
                    var $batchSelect = $('select[name="batch"]');
                    var newOption = new Option(response.batchName + ' (' + response.batchCode + ')', response.batchName, true, true);
                    $batchSelect.append(newOption).trigger('change');

                    Swal.fire({
                        icon: 'success',
                        title: 'Success!',
                        text: 'Batch created and selected successfully.',
                        confirmButtonColor: '#4B49AC'
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: response.error || 'Failed to create batch.',
                        confirmButtonColor: '#4B49AC'
                    });
                }
            },
            error: function() {
                $btn.prop('disabled', false).html('<i class="fa-solid fa-plus me-1"></i> Create Batch');
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'An error occurred while connecting to the server.',
                    confirmButtonColor: '#4B49AC'
                });
            }
        });
    });
});

function showCreateBatchModal() {
    $('#createBatchModal').modal('show');
}
</script>

<!-- CREATE BATCH MODAL -->
<div class="modal fade" id="createBatchModal" tabindex="-1" role="dialog" aria-hidden="true" style="z-index: 1055;">
    <div class="modal-dialog modal-md" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" style="font-weight: 700; color: #222;"><i class="fa-solid fa-plus me-2" style="color: #4B49AC;"></i> Create New Batch</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close" style="border: none; background: transparent; font-size: 1.5rem; outline: none;">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form id="ajaxCreateBatchForm" novalidate>
                <div class="modal-body" style="padding: 20px;">
                    <div class="row">
                        <div class="col-md-7">
                            <div class="form-group mb-3">
                                <label style="font-size:0.85rem;font-weight:600;color:#555;">Batch Name <span class="required" style="color:red;">*</span></label>
                                <input type="text" class="form-control" name="batchName" id="modalBatchName"
                                       placeholder="e.g. Batch A, Summer 2025" autocomplete="off" required>
                            </div>
                        </div>
                        <div class="col-md-5">
                            <div class="form-group mb-3">
                                <label style="font-size:0.85rem;font-weight:600;color:#555;">Batch Code <span class="required" style="color:red;">*</span></label>
                                <input type="text" class="form-control" name="batchCode" id="modalBatchCode"
                                       placeholder="e.g. BA, S25" style="text-transform:uppercase;"
                                       oninput="this.value=this.value.toUpperCase()" autocomplete="off" required>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label style="font-size:0.85rem;font-weight:600;color:#555;">Start Date <span class="required" style="color:red;">*</span></label>
                                <input type="date" class="form-control" name="startDate" id="modalStartDate" min="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label style="font-size:0.85rem;font-weight:600;color:#555;">End Date <span class="required" style="color:red;">*</span></label>
                                <input type="date" class="form-control" name="endDate" id="modalEndDate" min="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label style="font-size:0.85rem;font-weight:600;color:#555;">Batch Location <span class="required" style="color:red;">*</span></label>
                                <select class="form-control" name="batchLocation" id="modalBatchLocation" required style="height: 46px;">
                                    <option value="">— Select Location —</option>
                                    <option value="Pune">Pune</option>
                                    <option value="Kinwat">Kinwat</option>
                                    <option value="Remote">Remote</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label style="font-size:0.85rem;font-weight:600;color:#555;">Batch Mode <span class="required" style="color:red;">*</span></label>
                                <select class="form-control" name="batchMode" id="modalBatchMode" required style="height: 46px;">
                                    <option value="Online">Online</option>
                                    <option value="Offline">Offline</option>
                                    <option value="Hybrid">Hybrid</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="form-group mb-3">
                        <label style="font-size:0.85rem;font-weight:600;color:#555;">Description</label>
                        <textarea class="form-control" name="description" id="modalDescription" rows="2"
                                  placeholder="Optional notes about this batch"></textarea>
                    </div>
                </div>
                <div class="modal-footer" style="background: #f8f9fa;">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal" style="border-radius: 8px;">Cancel</button>
                    <button type="button" class="btn btn-primary" id="btnSubmitModalBatch" style="background:#4B49AC; border-color:#4B49AC; border-radius: 8px;">
                        <i class="fa-solid fa-plus me-1"></i> Create Batch
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
</body>
</html>