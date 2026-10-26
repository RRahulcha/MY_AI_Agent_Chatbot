<?php
session_start();
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}

include('../include/db_config.php');

$userId = mysqli_real_escape_string($con, $_SESSION['userId']);
$userRow = mysqli_fetch_assoc(mysqli_query($con, "SELECT fullName FROM signup WHERE userId='$userId'"));
$hrName  = $userRow ? htmlspecialchars($userRow['fullName']) : 'HR Team';

// ── Pull last-onboarded from session ──────────────────────────────
$intern = $_SESSION['last_onboarded'] ?? null;

// ── If accessed directly (not from onboarding.php), allow manual lookup via GET ?internId= ──
if (!$intern && isset($_GET['internId'])) {
    $safeId = mysqli_real_escape_string($con, trim($_GET['internId']));
    $row = mysqli_fetch_assoc(mysqli_query($con, "
        SELECT s.userId, s.internId, s.fullName, s.email, s.username, s.password,
               s.designation AS role, s.start_date AS doj, s.end_date,
               s.batch_number AS batch, s.mode, s.branch, s.colleague AS mentor,
               s.expectedPunchIn AS punch_in, s.expectedPunchOut AS punch_out
        FROM signup s WHERE s.internId = '$safeId' LIMIT 1
    "));
    if ($row) {
        $intern = [
            'internId'  => $row['internId'],
            'userId'    => $row['userId'],
            'fullName'  => $row['fullName'],
            'email'     => $row['email'],
            'username'  => $row['username'],
            'password'  => $row['password'],
            'role'      => $row['role'],
            'doj'       => $row['doj'],
            'end_date'  => $row['end_date'],
            'batch'     => $row['batch'],
            'mode'      => $row['mode'],
            'branch'    => $row['branch'],
            'mentor'    => $row['mentor'],
            'punch_in'  => $row['punch_in'],
            'punch_out' => $row['punch_out'],
        ];
    }
}

$swalMsg = '';
$swalIcon = '';
$showGmailButton = false;

// ── Handle SEND ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_email'])) {
    $toEmail   = trim($_POST['to_email']);
    $toName    = trim($_POST['to_name']);
    $subject   = trim($_POST['subject']);
    $body      = $_POST['email_body'];

    // Convert HTML to better formatted plain text for Gmail
    // Replace table with nicely formatted credentials box
    $plainBody = $body;
    
    // Create a nicely formatted credentials section
$credentialsBox = "\n" .

"🎉 WELCOME TO SETTRIBE LLP! 🎉\n\n" .

"Dear " . ($intern['fullName'] ?? 'Intern') . ",\n\n" .

"We are excited to have you on board and look forward to an enriching\n" .
"and collaborative journey together!\n\n" .

"Your internship account has been created successfully.\n\n" .

"━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n" .
"YOUR LOGIN CREDENTIALS\n" .
"━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n" .

"🆔 Intern ID : " . ($intern['internId'] ?? 'N/A') . "\n\n" .

"👤 Username : " . ($intern['username'] ?? 'N/A') . "\n\n" .

"🔐 Password : " . ($intern['password'] ?? 'N/A') . "\n\n" .

"📎 IMPORTANT:\n" .
"Please find your offer letter attached for your reference.\n" .
"Kindly review it and acknowledge your acceptance.\n\n" .

"Once again, welcome to the SETTribe family! 🎊\n\n" .

"Best Regards,\n" .
"SETTribe Team\n" .
"An Ed-Tech Prudentia\n\n" .

"━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n" .
"📧 This is an automated email. Please do not reply.\n" .
"For queries, contact: hr@settribe.com\n" .
"━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━";
    $fullBody = $credentialsBox;

    // Generate Gmail compose URL
    $gmailUrl = "https://mail.google.com/mail/?view=cm&fs=1"
              . "&to=" . urlencode($toEmail)
              . "&su=" . urlencode($subject)
              . "&body=" . urlencode($fullBody);

    // Log the email attempt (optional - won't fail if table doesn't exist)
$logQuery = "INSERT INTO email_logs (internId, sentTo, sentBy, sentAt, subject, status, emailType)
    VALUES ('" . mysqli_real_escape_string($con, $intern['internId'] ?? '') . "',
            '" . mysqli_real_escape_string($con, $toEmail) . "',
            '$userId', 
            NOW(),
            '" . mysqli_real_escape_string($con, $subject) . "',
            'opened',
            'offer_letter')";

@mysqli_query($con, $logQuery);

    // Store Gmail URL and email info in session
    $_SESSION['gmail_url'] = $gmailUrl;
    $_SESSION['email_to'] = $toEmail;
    $_SESSION['email_prepared'] = true;
    
    // Set success message
    $swalMsg = 'Email prepared successfully! Click the button below to open Gmail.';
    $swalIcon = 'success';
    $showGmailButton = true;
}

// ── Build prefilled template ─────────────────────────────────────
$tplName     = $intern ? htmlspecialchars($intern['fullName'])  : '';
$tplInternId = $intern ? htmlspecialchars($intern['internId'])  : '';
$tplUsername = $intern ? htmlspecialchars($intern['username'])  : '';
$tplPassword = $intern ? htmlspecialchars($intern['password'])  : '';
$tplRole     = $intern ? htmlspecialchars($intern['role'])      : '';
$tplEmail    = $intern ? htmlspecialchars($intern['email'])     : '';
$tplMentor   = $intern ? htmlspecialchars($intern['mentor'])    : '';
$tplBatch    = $intern ? htmlspecialchars($intern['batch'])     : '';
$tplMode     = $intern ? htmlspecialchars($intern['mode'])      : '';
$tplBranch   = $intern ? htmlspecialchars($intern['branch'])    : '';
$tplDoj      = $intern ? date('d M Y', strtotime($intern['doj']))     : '';
$tplEnd      = $intern ? date('d M Y', strtotime($intern['end_date'])) : '';
$tplPunchIn  = $intern ? date('h:i A', strtotime($intern['punch_in'])) : '';
$tplPunchOut = $intern ? date('h:i A', strtotime($intern['punch_out'])) : '';

$emailBodyHtml = <<<HTML
<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; background: #f9f9f9;">
    <div style="background: linear-gradient(135deg, #2f55ff 0%, #5b7fff 100%); color: white; padding: 30px; border-radius: 10px 10px 0 0; text-align: center;">
        <h2 style="margin: 0; font-size: 24px;">🎉 Welcome to SETTribe LLP! 🎉</h2>
        <p style="margin: 10px 0 0 0; font-size: 14px; opacity: 0.9;">An Ed-Tech Prudentia</p>
    </div>
    
    <div style="background: white; padding: 30px; border-radius: 0 0 10px 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
        <p style="font-size: 16px; color: #333; line-height: 1.6;">Dear <strong>{$tplName}</strong>,</p>
        
        <p style="font-size: 15px; color: #555; line-height: 1.8;">
            We are excited to have you on board and look forward to an enriching and collaborative journey together!
        </p>
        
        <p style="font-size: 15px; color: #555; line-height: 1.8;">
            Your internship account has been created successfully.
        </p>
        
        <div style="background: #f0f4ff; border-left: 4px solid #2f55ff; padding: 20px; margin: 25px 0; border-radius: 5px;">
            <h3 style="margin: 0 0 15px 0; color: #2f55ff; font-size: 18px;">🔐 Your Login Credentials</h3>
            
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td style="padding: 12px 0; color: #666; font-weight: 600; width: 130px;">🆔 Intern ID:</td>
                    <td style="padding: 12px 0; color: #333; font-weight: 700; font-family: 'Courier New', monospace;">{$tplInternId}</td>
                </tr>
                <tr style="border-top: 1px solid #e0e4ef;">
                    <td style="padding: 12px 0; color: #666; font-weight: 600;">👤 Username:</td>
                    <td style="padding: 12px 0; color: #333; font-weight: 700; font-family: 'Courier New', monospace;">{$tplUsername}</td>
                </tr>
                <tr style="border-top: 1px solid #e0e4ef;">
                    <td style="padding: 12px 0; color: #666; font-weight: 600;">🔐 Password:</td>
                    <td style="padding: 12px 0; color: #333; font-weight: 700; font-family: 'Courier New', monospace; letter-spacing: 1px;">{$tplPassword}</td>
                </tr>
                <tr style="border-top: 1px solid #e0e4ef;">
                   <td style="padding: 12px 0; color: #666; font-weight: 600;">🕘 Punch In:</td>
                   <td style="padding: 12px 0; color: #333; font-weight: 700;">{$tplPunchIn}</td>
                </tr>

               <tr style="border-top: 1px solid #e0e4ef;">
                  <td style="padding: 12px 0; color: #666; font-weight: 600;">🕔 Punch Out:</td>
                  <td style="padding: 12px 0; color: #333; font-weight: 700;">{$tplPunchOut}</td>
               </tr>
            </table>
        </div>
        
        <div style="background: #fff8e1; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; border-radius: 5px;">
            <p style="margin: 0; font-size: 14px; color: #7b5b00;">
                <strong>📎 Important:</strong> Please find your offer letter attached for your reference. Kindly review it and acknowledge your acceptance.
            </p>
        </div>
        
        <p style="font-size: 15px; color: #555; line-height: 1.8; margin-top: 25px;">
            Once again, welcome to the <strong style="color: #2f55ff;">SETTribe family!</strong> 🎊
        </p>
        
        <div style="margin-top: 30px; padding-top: 20px; border-top: 2px solid #e0e4ef;">
            <p style="font-size: 15px; color: #333; margin: 5px 0;"><strong>Best Regards,</strong></p>
            <p style="font-size: 15px; color: #2f55ff; margin: 5px 0; font-weight: 600;">SETTribe Team</p>
            <p style="font-size: 13px; color: #888; margin: 5px 0; font-style: italic;">An Ed-Tech Prudentia</p>
        </div>
    </div>
    
    <div style="text-align: center; padding: 20px; color: #888; font-size: 12px;">
        <p style="margin: 5px 0;">This is an automated email. Please do not reply.</p>
        <p style="margin: 5px 0;">For queries, contact: <a href="mailto:hr@settribe.com" style="color: #2f55ff;">hr@settribe.com</a></p>
    </div>
</div>
HTML;

// Encode for use in textarea (keep < > & safe)
$emailBodyEncoded = htmlspecialchars($emailBodyHtml);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Send Offer Email – SETTribe</title>
    <?php include('../partials/_links.php'); ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary:       #2f55ff;
            --primary-light: #eef1ff;
            --success:       #28a745;
            --danger:        #e53935;
            --border:        #e0e4ef;
            --radius:        8px;
            --shadow:        0 2px 16px rgba(47,85,255,0.08);
        }

        /* ── Page layout ── */
        .email-wrapper { max-width: 900px; margin: 0 auto; padding: 0 8px; }

        /* ── Section title ── */
        .section-title {
            font-size: 1.05rem; font-weight: 700;
            border-left: 4px solid var(--primary);
            padding-left: 12px; margin: 28px 0 16px; color: #222;
        }

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

        /* ── Intern summary card ── */
        .intern-card {
            border: 1.5px solid var(--border); border-left: 4px solid var(--primary);
            border-radius: var(--radius); background: var(--primary-light);
            padding: 16px 20px; margin-bottom: 24px;
            display: flex; flex-wrap: wrap; gap: 14px 28px; align-items: flex-start;
        }
        .intern-card__name {
            font-size: 1.1rem; font-weight: 800; color: #111;
            width: 100%; margin-bottom: 4px;
        }
        .intern-card__chip {
            display: inline-flex; align-items: center; gap: 6px;
            background: #fff; border: 1px solid var(--border);
            border-radius: 20px; padding: 4px 12px;
            font-size: 0.8rem; font-weight: 600; color: #444;
        }
        .intern-card__chip i { color: var(--primary); font-size: 0.78rem; }
        .intern-card__chip.password-chip {
            font-family: 'Courier New', monospace;
            color: var(--primary); letter-spacing: 1px;
        }

        /* ── Email composer ── */
        .composer {
            border: 1.5px solid var(--border); border-radius: 12px;
            background: #fff; box-shadow: var(--shadow); overflow: hidden;
        }
        .composer__header {
            background: linear-gradient(90deg, #2f55ff 0%, #5b7fff 100%);
            padding: 16px 22px; display: flex; align-items: center; gap: 12px;
        }
        .composer__header i { color: #fff; font-size: 1.3rem; }
        .composer__header h5 { color: #fff; font-weight: 700; margin: 0; font-size: 1rem; }
        .composer__header small { color: rgba(255,255,255,0.75); font-size: 0.78rem; margin-left: auto; }

        .composer__body { padding: 22px 24px; display: flex; flex-direction: column; gap: 16px; }

        .field-row { display: flex; align-items: flex-start; gap: 14px; }
        .field-label {
            min-width: 90px; font-size: 0.8rem; font-weight: 700; color: #888;
            text-transform: uppercase; letter-spacing: 0.06em; padding-top: 11px;
        }
        .field-input { flex: 1; }
        .form-control {
            border-radius: var(--radius); border: 1.5px solid var(--border);
            padding: 9px 13px; font-size: 0.93rem; color: #222;
            width: 100%; transition: border 0.2s, box-shadow 0.2s;
        }
        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(47,85,255,0.1); outline: none;
        }
        .composer__divider { border: none; border-top: 1.5px solid var(--border); margin: 0; }

        /* ── Body editor ── */
        .body-toolbar {
            display: flex; gap: 6px; flex-wrap: wrap;
            padding: 10px 14px; background: #f8f9fd;
            border-bottom: 1px solid var(--border);
        }
        .toolbar-btn {
            background: #fff; border: 1.5px solid var(--border); color: #555;
            border-radius: 5px; padding: 4px 10px; font-size: 0.8rem;
            cursor: pointer; font-weight: 600; transition: all 0.15s; line-height: 1.4;
        }
        .toolbar-btn:hover { border-color: var(--primary); color: var(--primary); background: var(--primary-light); }

        #emailBodyEditor {
            min-height: 340px; width: 100%; border: none; outline: none;
            padding: 18px 20px; font-size: 0.93rem; color: #222; line-height: 1.7;
            resize: vertical; font-family: 'Segoe UI', Arial, sans-serif;
            overflow-y: auto;
        }
        /* Preview mode */
        #previewPane {
            display: none; min-height: 340px; padding: 18px 20px;
            border: none; font-size: 0.93rem; color: #222; line-height: 1.7;
            background: #fff; overflow: auto;
        }

        /* ── Attachment notice ── */
        .attach-notice {
            display: flex; align-items: center; gap: 10px;
            background: #fff8e1; border: 1.5px solid #ffe082;
            border-radius: var(--radius); padding: 12px 16px;
            font-size: 0.87rem; color: #7b5b00; font-weight: 500;
        }
        .attach-notice i { color: #f59f00; font-size: 1rem; flex-shrink: 0; }

        /* ── Action bar ── */
        .composer__footer {
            padding: 16px 24px; background: #f8f9fd;
            border-top: 1.5px solid var(--border);
            display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
        }
        .btn-send {
            background: var(--primary); color: #fff; border: none;
            border-radius: var(--radius); padding: 11px 28px; font-weight: 700;
            font-size: 0.95rem; cursor: pointer;
            display: inline-flex; align-items: center; gap: 8px; transition: background 0.2s;
            text-decoration: none;
        }
        .btn-send:hover { background: #1a3de0; color: #fff; text-decoration: none; }
        .btn-send:disabled { background: #b0baf8; cursor: not-allowed; }

        .btn-gmail {
            background: #ea4335; color: #fff; border: none;
            border-radius: var(--radius); padding: 12px 32px; font-weight: 700;
            font-size: 1rem; cursor: pointer;
            display: inline-flex; align-items: center; gap: 10px; transition: all 0.2s;
            text-decoration: none; box-shadow: 0 4px 12px rgba(234,67,53,0.3);
        }
        .btn-gmail:hover { 
            background: #d33426; color: #fff; text-decoration: none;
            box-shadow: 0 6px 16px rgba(234,67,53,0.4); transform: translateY(-2px);
        }

        .btn-preview {
            background: #fff; color: var(--primary); border: 2px solid var(--primary);
            border-radius: var(--radius); padding: 10px 20px; font-weight: 700;
            font-size: 0.9rem; cursor: pointer;
            display: inline-flex; align-items: center; gap: 7px; transition: all 0.2s;
        }
        .btn-preview:hover { background: var(--primary-light); }

        .btn-back {
            background: transparent; color: #888; border: 2px solid var(--border);
            border-radius: var(--radius); padding: 10px 18px; font-weight: 600;
            font-size: 0.88rem; cursor: pointer; text-decoration: none;
            display: inline-flex; align-items: center; gap: 7px; transition: all 0.2s;
        }
        .btn-back:hover { border-color: #aaa; color: #444; text-decoration: none; }

        .send-status { margin-left: auto; font-size: 0.82rem; color: #888; }

        /* ── Gmail button container ── */
        .gmail-button-container {
            margin: 24px 0;
            padding: 24px;
            background: linear-gradient(135deg, #f8f9fd 0%, #eef1ff 100%);
            border: 2px dashed var(--primary);
            border-radius: 12px;
            text-align: center;
        }
        .gmail-button-container h5 {
            color: #222;
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 8px;
        }
        .gmail-button-container p {
            color: #666;
            font-size: 0.9rem;
            margin-bottom: 20px;
        }

        /* ── No intern warning ── */
        .no-intern-warn {
            border: 2px dashed var(--border); border-radius: 12px;
            padding: 40px 24px; text-align: center; color: #888;
        }
        .no-intern-warn i { font-size: 2.5rem; color: #ccc; margin-bottom: 16px; display: block; }
        .no-intern-warn h5 { color: #555; margin-bottom: 8px; }

        /* ── Copy button inside chip ── */
        .copy-btn {
            background: transparent; border: none; color: #aaa;
            cursor: pointer; padding: 0 2px; font-size: 0.82rem;
            transition: color 0.15s;
        }
        .copy-btn:hover { color: var(--primary); }

        @media (max-width: 600px) {
            .workflow-steps { flex-direction: column; align-items: flex-start; gap: 12px; }
            .wf-arrow { display: none; }
            .field-row { flex-direction: column; gap: 4px; }
            .field-label { padding-top: 0; }
        }
    </style>
</head>
<body>
<div class="container-scroller">
    <?php include('../partials/_navbar.php'); ?>
    <div class="container-fluid page-body-wrapper">
        <?php include('../partials/_sidebar.php'); include('../partials/_settings-panel.php'); ?>
        <div class="main-panel">
        <div class="content-wrapper">

        <?php if ($swalMsg): ?>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            Swal.fire({
                icon: '<?= $swalIcon ?>',
                title: '<?= $swalIcon === "success" ? "Email Prepared!" : "Action Required" ?>',
                html: '<?= addslashes($swalMsg) ?>',
                confirmButtonColor: '#2f55ff',
                confirmButtonText: 'OK'
            });
        });
        </script>
        <?php endif; ?>

        <div class="email-wrapper">

        <div class="d-flex align-items-center justify-content-between mb-3 mt-2">
            <h4 class="card-title mb-0">
                <i class="fa-solid fa-paper-plane me-2" style="color:var(--primary);"></i>
                Send Offer Email
            </h4>
            <a href="onboarding.php" class="btn-back">
                <i class="fa-solid fa-arrow-left"></i> Back to Onboarding
            </a>
        </div>

        <!-- Workflow Steps -->
        <div class="workflow-steps">
            <div class="wf-step">
                <div class="wf-step-icon done"><i class="fa-solid fa-check"></i></div>
                <div class="wf-step-text"><strong>Create Account</strong>Done</div>
            </div>
            <div class="wf-arrow"><i class="fa-solid fa-chevron-right"></i></div>
            <div class="wf-step">
                <div class="wf-step-icon done"><i class="fa-solid fa-check"></i></div>
                <div class="wf-step-text"><strong>View Credentials</strong>Done</div>
            </div>
            <div class="wf-arrow"><i class="fa-solid fa-chevron-right"></i></div>
            <div class="wf-step">
                <div class="wf-step-icon active">3</div>
                <div class="wf-step-text"><strong>Send Email</strong>Compose &amp; send</div>
            </div>
        </div>

        <?php if ($intern): ?>

        <!-- Show Gmail button if email was prepared -->
        <?php if ($showGmailButton && isset($_SESSION['gmail_url'])): ?>
        <div class="gmail-button-container">
            <h5><i class="fa-solid fa-envelope-circle-check me-2" style="color:var(--success);"></i>Email Ready to Send!</h5>
            <p>Your email has been prepared with all the login credentials.<br>Click the button below to open Gmail and send it.</p>
            <a href="<?= htmlspecialchars($_SESSION['gmail_url']) ?>" target="_blank" class="btn-gmail" id="openGmailBtn">
                <i class="fa-brands fa-google"></i> Open in Gmail
            </a>
            <p style="margin-top: 16px; font-size: 0.85rem; color: #888;">
                <i class="fa-solid fa-info-circle me-1"></i>
                To: <strong><?= htmlspecialchars($_SESSION['email_to']) ?></strong>
            </p>
        </div>
        <?php 
        // Clear the session variables after showing
        unset($_SESSION['gmail_url']);
        unset($_SESSION['email_to']);
        unset($_SESSION['email_prepared']);
        endif; 
        ?>

        <!-- Intern Summary Chips -->
        <div class="intern-card">
            <div class="intern-card__name">
                <i class="fa-solid fa-user-tie me-2" style="color:var(--primary);"></i>
                <?= $tplName ?>
            </div>
            <span class="intern-card__chip">
                <i class="fa-solid fa-id-card"></i>
                <?= $tplInternId ?>
                <button type="button" class="copy-btn" onclick="quickCopy('<?= $tplInternId ?>', this)" title="Copy Intern ID"><i class="fa-solid fa-copy"></i></button>
            </span>
            <span class="intern-card__chip">
                <i class="fa-solid fa-envelope"></i>
                <?= $tplEmail ?>
                <button type="button" class="copy-btn" onclick="quickCopy('<?= $tplEmail ?>', this)" title="Copy Email"><i class="fa-solid fa-copy"></i></button>
            </span>
            <span class="intern-card__chip password-chip">
                <i class="fa-solid fa-lock"></i>
                <?= $tplPassword ?>
                <button type="button" class="copy-btn" onclick="quickCopy('<?= htmlspecialchars($tplPassword, ENT_QUOTES) ?>', this)" title="Copy Password"><i class="fa-solid fa-copy"></i></button>
            </span>
        </div>

        <!-- Email Composer -->
        <form method="POST" id="emailForm">
            <input type="hidden" name="send_email" value="1">
            <input type="hidden" name="to_name"   value="<?= $tplName ?>">

            <div class="composer">
                <div class="composer__header">
                    <i class="fa-solid fa-envelope-open-text"></i>
                    <h5>Compose Offer Email</h5>
                    <small>Opens Gmail — review and click Send!</small>
                </div>

                <div class="composer__body">
                    <!-- To -->
                    <div class="field-row">
                        <span class="field-label">To</span>
                        <div class="field-input">
                            <input type="email" class="form-control" name="to_email" id="toEmail"
                                   value="<?= $tplEmail ?>" required>
                        </div>
                    </div>

                    <hr class="composer__divider">

                    <!-- Subject -->
                    <div class="field-row">
                        <span class="field-label">Subject</span>
                        <div class="field-input">
                            <input type="text" class="form-control" name="subject" id="emailSubject"
                                   value="Welcome to SETTribe LLP – Internship Onboarding" required>
                        </div>
                    </div>

                    <hr class="composer__divider">

                    <!-- Body toolbar -->
                    <div style="border:1.5px solid var(--border);border-radius:var(--radius);overflow:hidden;">
                        <div class="body-toolbar">
                            <span style="font-size:0.78rem;color:#888;font-weight:600;padding-top:3px;margin-right:6px;">Body</span>
                            <button type="button" class="toolbar-btn" onclick="fmt('bold')"><b>B</b></button>
                            <button type="button" class="toolbar-btn" onclick="fmt('italic')"><i>I</i></button>
                            <button type="button" class="toolbar-btn" onclick="fmt('underline')"><u>U</u></button>
                            <button type="button" class="toolbar-btn" onclick="insertLineBreak()">↵ BR</button>
                            <button type="button" class="toolbar-btn" id="previewToggleBtn" onclick="togglePreview()">
                                <i class="fa-solid fa-eye me-1"></i> Preview
                            </button>
                            <button type="button" class="toolbar-btn" onclick="resetBody()" style="margin-left:auto;color:#e53935;border-color:#f5c6cb;">
                                <i class="fa-solid fa-rotate-left me-1"></i> Reset
                            </button>
                        </div>

                        <!-- Editable div (rich text) -->
                        <div id="emailBodyEditor" contenteditable="true" spellcheck="true">
                            <?= $emailBodyHtml ?>
                        </div>

                        <!-- Preview pane -->
                        <div id="previewPane"></div>
                    </div>

                    <!-- Hidden textarea that gets synced before submit -->
                    <textarea name="email_body" id="emailBodyHidden" style="display:none;"></textarea>

                    <!-- Attachment reminder -->
                    <div class="attach-notice">
                        <i class="fa-solid fa-paperclip"></i>
                        <span>
                            <strong>Note:</strong> Gmail will open in a new tab. You can attach the offer letter PDF there before sending.
                            Login credentials are automatically included in the email body.
                        </span>
                    </div>
                </div>

                <div class="composer__footer">
                    <button type="submit" class="btn-send" id="sendBtn" onclick="syncBody()">
                        <i class="fa-solid fa-paper-plane"></i> Prepare Email
                    </button>
                    <button type="button" class="btn-preview" onclick="togglePreview()">
                        <i class="fa-solid fa-eye"></i> Preview
                    </button>
                    <a href="onboarding.php" class="btn-back">
                        <i class="fa-solid fa-arrow-left"></i> Back
                    </a>
                    <span class="send-status" id="sendStatus"></span>
                </div>
            </div>
        </form>

        <!-- Implementation note -->
        <div style="margin-top:18px;padding:14px 18px;background:#f8f9fd;border:1.5px solid var(--border);border-radius:var(--radius);font-size:0.83rem;color:#666;">
            <i class="fa-solid fa-circle-info me-1" style="color:var(--primary);"></i>
            <strong>How it works:</strong>
            Click "Prepare Email" to generate your email, then click the Gmail button that appears.
            Gmail will open in a new tab with pre-filled recipient, subject, and body (including login credentials).
            You can review the email, attach files if needed, and click Send from Gmail directly.
            This ensures reliable delivery through your existing email account.
        </div>

        <?php else: ?>

        <!-- No intern data -->
        <div class="no-intern-warn">
            <i class="fa-solid fa-user-slash"></i>
            <h5>No Intern Data Found</h5>
            <p>This page requires a recently onboarded intern in session.<br>
               Either complete the onboarding flow first, or load by Intern ID:</p>
            <form method="GET" class="d-flex justify-content-center gap-2 mt-3" style="max-width:360px;margin:0 auto;">
                <input type="text" name="internId" class="form-control" placeholder="e.g. INT2026001" required>
                <button type="submit" class="btn-send" style="padding:9px 20px;">Load</button>
            </form>
            <a href="onboarding.php" class="btn-back d-inline-flex mt-4">
                <i class="fa-solid fa-arrow-left me-1"></i> Go to Onboarding
            </a>
        </div>

        <?php endif; ?>

        </div><!-- /email-wrapper -->
        </div><!-- /content-wrapper -->
        </div><!-- /main-panel -->
    </div>
</div>

<script src="../vendors/js/vendor.bundle.base.js"></script>
<script src="../js/off-canvas.js"></script>
<script src="../js/hoverable-collapse.js"></script>
<script src="../js/template.js"></script>
<script src="../js/settings.js"></script>

<script>
// ── Store original body for reset ──
var originalBody = document.getElementById('emailBodyEditor')
    ? document.getElementById('emailBodyEditor').innerHTML
    : '';

// ── Rich text formatting ──
function fmt(cmd) {
    document.getElementById('emailBodyEditor').focus();
    document.execCommand(cmd, false, null);
}
function insertLineBreak() {
    document.getElementById('emailBodyEditor').focus();
    document.execCommand('insertHTML', false, '<br>');
}

// ── Sync contenteditable → hidden textarea before submit ──
function syncBody() {
    var editor = document.getElementById('emailBodyEditor');
    var hidden = document.getElementById('emailBodyHidden');
    if (editor && hidden) hidden.value = editor.innerHTML;
}

// ── Validate & sync on form submit ──
var emailForm = document.getElementById('emailForm');
if (emailForm) {
    emailForm.addEventListener('submit', function (e) {
        syncBody();
        var body = document.getElementById('emailBodyHidden').value.trim();
        if (!body || body.replace(/<[^>]*>/g,'').trim().length < 10) {
            e.preventDefault();
            Swal.fire({ 
                icon:'warning', 
                title:'Empty Body', 
                text:'Email body cannot be empty.', 
                confirmButtonColor:'#2f55ff' 
            });
            return;
        }
        var btn = document.getElementById('sendBtn');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i> Preparing…';
        }
        document.getElementById('sendStatus').textContent = 'Preparing email...';
    });
}

// ── Preview toggle ──
var previewOpen = false;
function togglePreview() {
    var editor  = document.getElementById('emailBodyEditor');
    var preview = document.getElementById('previewPane');
    var btn     = document.getElementById('previewToggleBtn');
    if (!editor || !preview) return;
    previewOpen = !previewOpen;
    if (previewOpen) {
        preview.innerHTML = editor.innerHTML;
        preview.style.display = 'block';
        editor.style.display  = 'none';
        if (btn) btn.innerHTML = '<i class="fa-solid fa-pen me-1"></i> Edit';
    } else {
        editor.style.display  = 'block';
        preview.style.display = 'none';
        if (btn) btn.innerHTML = '<i class="fa-solid fa-eye me-1"></i> Preview';
    }
}

// ── Reset body ──
function resetBody() {
    Swal.fire({
        icon: 'question', title: 'Reset email body?',
        text: 'This will restore the original prefilled template.',
        showCancelButton: true, confirmButtonText: 'Yes, reset',
        confirmButtonColor: '#e53935', cancelButtonColor: '#6c757d'
    }).then((result) => {
        if (result.isConfirmed) {
            var editor = document.getElementById('emailBodyEditor');
            if (editor) editor.innerHTML = originalBody;
            if (previewOpen) togglePreview();
        }
    });
}

// ── Quick copy helper (chips) ──
function quickCopy(text, btn) {
    navigator.clipboard.writeText(text).then(function () {
        var orig = btn.innerHTML;
        btn.innerHTML = '<i class="fa-solid fa-check" style="color:#28a745;"></i>';
        setTimeout(() => { btn.innerHTML = orig; }, 2000);
    });
}

// ── Gmail button click tracking (optional) ──
var gmailBtn = document.getElementById('openGmailBtn');
if (gmailBtn) {
    gmailBtn.addEventListener('click', function() {
        setTimeout(function() {
            Swal.fire({
                icon: 'info',
                title: 'Gmail Opened',
                html: 'Please review the email in the new tab and click <strong>Send</strong>!<br><small>If Gmail didn\'t open, please check your popup blocker.</small>',
                confirmButtonColor: '#2f55ff',
                confirmButtonText: 'Got it!'
            });
        }, 500);
    });
}
</script>
</body>
</html>