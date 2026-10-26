<?php
session_start();
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}
include('../include/db_config.php');
date_default_timezone_set('Asia/Kolkata');

$userId = mysqli_real_escape_string($con, $_SESSION['userId']);
$query  = mysqli_query($con, "SELECT * FROM signup WHERE userId='$userId' LIMIT 1");
$user = ($query && mysqli_num_rows($query) > 0) ? mysqli_fetch_assoc($query) : [];

$profilePhoto    = !empty($user['profilePhoto']) ? $user['profilePhoto'] : 'default.png';
$fullName        = $user['fullName'] ?? '';
$dob             = !empty($user['dob']) ? date('Y-m-d', strtotime($user['dob'])) : '';
$email           = $user['email'] ?? '';
$mobileNo        = $user['mobileNo'] ?? '';
$username        = $user['username'] ?? '';
$designation     = $user['designation'] ?? 'Team Member';
$department      = $user['department'] ?? 'General';
$empId           = !empty($user['emp_id']) ? $user['emp_id'] : (!empty($user['internId']) ? $user['internId'] : 'N/A');
$employmentType  = $user['employment_type'] ?? 'Intern';
$workMode        = $user['mode'] ?? $user['type'] ?? 'Offline';
$branch          = $user['office'] ?? $user['branch'] ?? 'Main Office';
$startDate       = !empty($user['start_date']) ? date('d M Y', strtotime($user['start_date'])) : (!empty($user['registration_date']) ? date('d M Y', strtotime($user['registration_date'])) : 'N/A');
$endDate         = (!empty($user['end_date']) && $user['end_date'] !== '0000-00-00') ? date('d M Y', strtotime($user['end_date'])) : 'Ongoing / Active';

$isHrOrAdmin     = in_array(strtolower(trim($user['designation'] ?? '')), ['hr', 'admin', 'superadmin']) ||
                   in_array(strtolower(trim($user['userRole'] ?? '')), ['hr', 'admin', 'superadmin']) ||
                   in_array(strtolower(trim($_SESSION['userRole'] ?? '')), ['hr', 'admin', 'superadmin']) ||
                   in_array(strtolower(trim($_SESSION['designation'] ?? '')), ['hr', 'admin', 'superadmin']);

// Generate Official Attendance QR Code inline for instant guaranteed rendering
$profileQrSvg = '';
try {
    require_once __DIR__ . '/../vendors/TCPDF/tcpdf_barcodes_2d.php';
    $qrEmployeeId = !empty($user['emp_id']) ? $user['emp_id'] : (!empty($user['internId']) ? $user['internId'] : $userId);
    $qrPayload = [
        'system' => 'SETTribe AMS',
        'userId' => strval($userId),
        'emp_id' => strval($qrEmployeeId),
        'name'   => strval($fullName),
        'role'   => strval($user['userRole'] ?? 'employee'),
        'mode'   => strval($workMode),
        'dept'   => strval($department),
        'token'  => 'SETTRIBE-' . strtoupper($userId) . '-PERMANENT'
    ];
    $qrJson = json_encode($qrPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $barcode = new TCPDF2DBarcode($qrJson, 'QRCODE,L');
    $rawSvg = $barcode->getBarcodeSVGcode(4, 4, '#0f172a');
    
    // Strip XML & DOCTYPE for direct HTML embedding
    $cleanSvg = preg_replace('/<\?xml[^>]*\?>\s*/i', '', $rawSvg);
    $cleanSvg = preg_replace('/<!DOCTYPE[^>]*>\s*/i', '', $cleanSvg);
    if (preg_match('/<svg\s+width="([0-9.]+)"\s+height="([0-9.]+)"/i', $cleanSvg, $dim)) {
        $w = $dim[1];
        $h = $dim[2];
        $profileQrSvg = preg_replace(
            '/<svg\s+width="[0-9.]+"\s+height="[0-9.]+"/i',
            '<svg id="inlineQrSvg" viewBox="0 0 ' . $w . ' ' . $h . '" width="100%" height="100%" preserveAspectRatio="xMidYMid meet"',
            $cleanSvg
        );
    } else {
        $profileQrSvg = $cleanSvg;
    }
    // Prepare Data URI for bulletproof <img> rendering
    $qrDataUri = !empty($cleanSvg) ? 'data:image/svg+xml;base64,' . base64_encode($cleanSvg) : '';
} catch (Throwable $e) {
    $profileQrSvg = '';
    $qrDataUri = '';
    $qrJson = '';
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <meta charset="utf-8" />
    <title>SETTribe · Executive Profile &amp; Digital Identity</title>
    <?php include('../partials/_links.php'); ?>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --primary-light: #eff6ff;
            --accent-purple: #7c3aed;
            --accent-cyan: #06b6d4;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --surface: #ffffff;
            --surface-ground: #f8fafc;
            --border-color: #e2e8f0;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --radius-card: 20px;
            --radius-item: 14px;
        }

        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: #f1f5f9;
            color: var(--text-main);
            -webkit-font-smoothing: antialiased;
        }

        /* ── Executive Hero Banner ── */
        .exec-hero-card {
            background: radial-gradient(circle at 100% 0%, rgba(37, 99, 235, 0.25) 0%, transparent 45%),
                        radial-gradient(circle at 0% 100%, rgba(124, 58, 237, 0.2) 0%, transparent 50%),
                        linear-gradient(135deg, #090d16 0%, #0f172a 50%, #1e1b4b 100%);
            border-radius: var(--radius-card);
            padding: 32px;
            color: #ffffff;
            margin-bottom: 26px;
            box-shadow: 0 20px 40px -15px rgba(15, 23, 42, 0.4), 0 0 0 1px rgba(255, 255, 255, 0.08);
            position: relative;
            overflow: hidden;
        }

        .avatar-container {
            position: relative;
            display: inline-block;
        }

        .exec-avatar-img {
            width: 116px;
            height: 116px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 12px 28px rgba(0, 0, 0, 0.35);
            background: #ffffff;
            transition: all 0.3s ease;
        }

        .exec-avatar-img:hover {
            transform: scale(1.03);
            border-color: #38bdf8;
        }

        .avatar-camera-btn {
            position: absolute;
            bottom: 4px;
            right: 4px;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            border: 2.5px solid #0f172a;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
            transition: all 0.2s ease;
        }

        .avatar-camera-btn:hover {
            transform: scale(1.15);
            background: #38bdf8;
            color: #0f172a;
        }

        .hero-badge-pill {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.18);
            padding: 5px 14px;
            border-radius: 20px;
            font-size: 0.74rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #93c5fd;
        }

        .exec-name-title {
            font-size: 1.85rem;
            font-weight: 800;
            margin: 6px 0 10px 0;
            color: #ffffff;
            letter-spacing: -0.025em;
            line-height: 1.2;
        }

        .hero-chip-row {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .hero-chip {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            background: rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.14);
            padding: 6px 14px;
            border-radius: 12px;
            font-size: 0.82rem;
            font-weight: 600;
            color: #e2e8f0;
            transition: all 0.2s ease;
        }

        .hero-chip:hover {
            background: rgba(255, 255, 255, 0.15);
            border-color: rgba(255, 255, 255, 0.25);
            color: #ffffff;
        }

        .hero-chip.status-chip {
            background: rgba(16, 185, 129, 0.15);
            border-color: rgba(16, 185, 129, 0.3);
            color: #6ee7b7;
        }

        /* ── Modern Premium Section Cards ── */
        .modern-profile-card {
            background: var(--surface);
            border-radius: var(--radius-card);
            border: 1px solid var(--border-color);
            padding: 28px;
            box-shadow: 0 10px 30px -10px rgba(15, 23, 42, 0.05), 0 2px 6px rgba(0, 0, 0, 0.02);
            margin-bottom: 24px;
            transition: all 0.25s ease;
        }

        .modern-profile-card:hover {
            border-color: #cbd5e1;
            box-shadow: 0 16px 36px -12px rgba(15, 23, 42, 0.08);
        }

        .card-header-styled {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-bottom: 18px;
            margin-bottom: 22px;
            border-bottom: 1px solid #f1f5f9;
        }

        .card-header-title {
            font-size: 1.1rem;
            font-weight: 800;
            color: var(--text-main);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-header-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.95rem;
        }

        .icon-blue   { background: #eff6ff; color: #2563eb; }
        .icon-purple { background: #f5f3ff; color: #7c3aed; }
        .icon-emerald{ background: #ecfdf5; color: #059669; }
        .icon-amber  { background: #fffbeb; color: #d97706; }

        /* ── Modern Inputs Styling ── */
        .form-group label {
            font-weight: 700;
            font-size: 0.82rem;
            color: #475569;
            margin-bottom: 7px;
            display: block;
        }

        .modern-input-group {
            position: relative;
            display: flex;
            align-items: center;
        }

        .modern-input-group .input-icon-left {
            position: absolute;
            left: 14px;
            color: #94a3b8;
            font-size: 0.9rem;
            pointer-events: none;
            z-index: 5;
            transition: color 0.2s ease;
        }

        .modern-form-control {
            width: 100%;
            height: 46px;
            border: 1.5px solid #e2e8f0;
            border-radius: 12px;
            font-size: 0.9rem;
            font-weight: 500;
            padding: 10px 14px 10px 42px;
            color: #0f172a;
            background: #ffffff;
            transition: all 0.2s ease;
        }

        .modern-form-control:focus {
            outline: none;
            border-color: #2563eb;
            background: #ffffff;
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1);
        }

        .modern-form-control:focus + .input-icon-left,
        .modern-input-group:focus-within .input-icon-left {
            color: #2563eb;
        }

        .eye-toggle-btn {
            position: absolute;
            right: 14px;
            cursor: pointer;
            color: #94a3b8;
            font-size: 0.95rem;
            transition: color 0.2s;
            z-index: 6;
        }
        .eye-toggle-btn:hover { color: #2563eb; }

        .btn-modern-primary {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: #ffffff;
            border: none;
            font-weight: 700;
            padding: 13px 32px;
            border-radius: 12px;
            box-shadow: 0 6px 20px rgba(37, 99, 235, 0.35);
            transition: all 0.2s ease;
            font-size: 0.92rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }

        .btn-modern-primary:hover {
            color: #ffffff;
            transform: translateY(-2px);
            box-shadow: 0 10px 26px rgba(37, 99, 235, 0.45);
        }

        /* ── SMART DIGITAL IDENTITY PASS CARD (RIGHT COLUMN) ── */
        .smart-pass-container {
            background: linear-gradient(145deg, #ffffff 0%, #f8fafc 100%);
            border-radius: var(--radius-card);
            border: 1px solid #e2e8f0;
            overflow: hidden;
            box-shadow: 0 14px 34px -10px rgba(15, 23, 42, 0.08);
            transition: all 0.25s ease;
            position: relative;
        }

        .smart-pass-container:hover {
            box-shadow: 0 20px 40px -12px rgba(15, 23, 42, 0.12);
            border-color: #cbd5e1;
        }

        .smart-pass-top-band {
            background: linear-gradient(135deg, #090d16 0%, #0f172a 60%, #1e293b 100%);
            padding: 16px 22px;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 2px solid rgba(37, 99, 235, 0.4);
        }

        .pass-brand-title {
            font-size: 0.85rem;
            font-weight: 800;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: #f8fafc;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .pass-chip-icon {
            color: #fbbf24;
            font-size: 1.1rem;
        }

        .qr-badge-inner-box {
            padding: 24px;
            text-align: center;
        }

        .qr-frame-shadow {
            display: inline-block;
            padding: 14px;
            background: #ffffff;
            border-radius: 18px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08), 0 0 0 1px #e2e8f0;
            margin-bottom: 16px;
            position: relative;
        }

        .qr-frame-shadow::before, .qr-frame-shadow::after {
            content: '';
            position: absolute;
            width: 14px;
            height: 14px;
            border-color: #2563eb;
            border-style: solid;
        }
        .qr-frame-shadow::before {
            top: 6px;
            left: 6px;
            border-width: 3px 0 0 3px;
            border-top-left-radius: 6px;
        }
        .qr-frame-shadow::after {
            bottom: 6px;
            right: 6px;
            border-width: 0 3px 3px 0;
            border-bottom-right-radius: 6px;
        }

        #clientQrCodeBox {
            width: 200px;
            height: 200px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #ffffff;
            overflow: hidden;
        }

        .pass-emp-name {
            font-size: 1.18rem;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 2px;
        }

        .pass-emp-id {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #eff6ff;
            color: #1d4ed8;
            font-weight: 700;
            font-size: 0.8rem;
            padding: 3px 12px;
            border-radius: 20px;
            border: 1px solid #bfdbfe;
            margin-bottom: 6px;
        }

        .pass-emp-meta {
            font-size: 0.84rem;
            color: #64748b;
            font-weight: 600;
        }

        .mode-guidance-pill {
            margin-top: 14px;
            border-radius: 12px;
            padding: 10px 14px;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 10px;
            text-align: left;
        }

        .mode-guidance-offline {
            background: #fffbeb;
            border: 1px solid #fef3c7;
            color: #92400e;
        }

        .mode-guidance-online {
            background: #f0f9ff;
            border: 1px solid #e0f2fe;
            color: #0369a1;
        }

        .pass-actions-row {
            padding: 16px 22px;
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .btn-pass-action {
            padding: 8px 16px;
            border-radius: 10px;
            font-size: 0.8rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s ease;
        }

        /* ── Modern 2-Column Organization Grid ── */
        .org-spec-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-bottom: 14px;
        }

        .org-spec-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: var(--radius-item);
            padding: 12px 14px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            transition: all 0.2s ease;
        }

        .org-spec-box:hover {
            background: #ffffff;
            border-color: #cbd5e1;
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.04);
            transform: translateY(-1px);
        }

        .org-spec-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.85rem;
            flex-shrink: 0;
            margin-top: 2px;
        }

        .org-spec-label {
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #64748b;
            margin-bottom: 2px;
        }

        .org-spec-value {
            font-size: 0.88rem;
            font-weight: 800;
            color: #0f172a;
            word-break: break-word;
        }

        @media (max-width: 768px) {
            .exec-hero-card { padding: 22px; }
            .org-spec-grid { grid-template-columns: 1fr; }
            .exec-name-title { font-size: 1.5rem; }
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

                    <!-- ── EXECUTIVE PROFILE HERO BANNER ── -->
                    <div class="exec-hero-card">
                        <div class="d-flex align-items-center flex-wrap gap-4">
                            <div class="avatar-container">
                                <img src="../profile-photos/<?= htmlspecialchars($profilePhoto, ENT_QUOTES, 'UTF-8'); ?>"
                                     id="avatarPreview"
                                     class="exec-avatar-img"
                                     alt="Profile Avatar"
                                     onerror="this.src='../images/faces/face8.jpg'">
                                <label for="imageUpload" class="avatar-camera-btn" title="Upload new photo">
                                    <i class="fas fa-camera"></i>
                                </label>
                            </div>

                            <div style="flex: 1;">
                                <div class="hero-badge-pill mb-2">
                                    <i class="fas fa-shield-halved me-1 text-cyan"></i> SETTribe Executive Profile
                                </div>
                                <h1 class="exec-name-title">
                                    <?= htmlspecialchars($fullName) ?>
                                </h1>
                                <div class="hero-chip-row">
                                    <div class="hero-chip">
                                        <i class="fas fa-id-badge text-warning"></i>
                                        <span>ID: <strong><?= htmlspecialchars($empId) ?></strong></span>
                                    </div>
                                    <div class="hero-chip">
                                        <i class="fas fa-briefcase text-info"></i>
                                        <span><?= htmlspecialchars($designation) ?></span>
                                    </div>
                                    <div class="hero-chip">
                                        <i class="fas fa-building text-primary"></i>
                                        <span><?= htmlspecialchars($department) ?></span>
                                    </div>
                                    <div class="hero-chip">
                                        <i class="fas fa-laptop-house text-purple"></i>
                                        <span><?= htmlspecialchars($workMode) ?></span>
                                    </div>
                                    <div class="hero-chip status-chip">
                                        <i class="fas fa-circle-check"></i>
                                        <span>Active Account</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── MAIN FORM CONTAINER ── -->
                    <form class="forms-sample" action="editProfile_db.php" method="post" enctype="multipart/form-data" id="profileForm">

                        <!-- Hidden File Input triggered by Avatar Camera Button -->
                        <input type="file" name="upload" id="imageUpload" accept="image/jpeg,image/jpg,image/png" style="display:none;">

                        <div class="row">

                            <!-- ── LEFT COLUMN: EDITABLE PERSONAL & SECURITY DATA ── -->
                            <div class="col-lg-7">
                                <div class="modern-profile-card">
                                    <div class="card-header-styled">
                                        <div class="card-header-title">
                                            <div class="card-header-icon icon-blue">
                                                <i class="fas fa-user-pen"></i>
                                            </div>
                                            <span>Personal &amp; Account Details</span>
                                        </div>
                                        <span class="badge badge-light border text-muted" style="font-size:0.75rem; border-radius:10px;">
                                            Editable
                                        </span>
                                    </div>

                                    <div class="row">
                                        <!-- Full Name -->
                                        <div class="col-md-12 mb-3">
                                            <div class="form-group mb-0">
                                                <label for="fullName">Full Name <span class="text-danger">*</span></label>
                                                <div class="modern-input-group">
                                                    <i class="fas fa-user input-icon-left"></i>
                                                    <input type="text" class="modern-form-control" name="fullName" id="fullName" value="<?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'); ?>" required>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Email Address -->
                                        <div class="col-md-6 mb-3">
                                            <div class="form-group mb-0">
                                                <label for="email">Email Address <span class="text-danger">*</span></label>
                                                <div class="modern-input-group">
                                                    <i class="fas fa-envelope input-icon-left"></i>
                                                    <input type="email" class="modern-form-control" name="email" id="email" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>" required>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Mobile Number -->
                                        <div class="col-md-6 mb-3">
                                            <div class="form-group mb-0">
                                                <label for="mobileNo">Mobile Number</label>
                                                <div class="modern-input-group">
                                                    <i class="fas fa-phone input-icon-left"></i>
                                                    <input type="text" class="modern-form-control" name="mobileNo" id="mobileNo" value="<?= htmlspecialchars($mobileNo, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Enter mobile number">
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Date of Birth -->
                                        <div class="col-md-6 mb-3">
                                            <div class="form-group mb-0">
                                                <label for="dob">Date of Birth <span class="text-danger">*</span></label>
                                                <div class="modern-input-group">
                                                    <i class="fas fa-calendar-alt input-icon-left"></i>
                                                    <input type="date" class="modern-form-control" name="dob" id="dob" value="<?= htmlspecialchars($dob, ENT_QUOTES, 'UTF-8'); ?>" required>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Username -->
                                        <div class="col-md-6 mb-3">
                                            <div class="form-group mb-0">
                                                <label for="username">System Username <span class="text-danger">*</span></label>
                                                <div class="modern-input-group">
                                                    <i class="fas fa-at input-icon-left"></i>
                                                    <input type="text" class="modern-form-control" name="username" id="username" value="<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?>" required>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Password Divider -->
                                        <div class="col-md-12 my-3 border-top pt-3">
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="card-header-icon icon-purple" style="width:28px; height:28px; font-size:0.8rem;">
                                                    <i class="fas fa-lock"></i>
                                                </div>
                                                <span style="font-weight:700; color:#334155; font-size:0.88rem;">
                                                    Security &amp; Password
                                                </span>
                                                <span class="text-muted ms-auto" style="font-size:0.75rem;">(Leave blank to keep unchanged)</span>
                                            </div>
                                        </div>

                                        <!-- New Password -->
                                        <div class="col-md-6 mb-3">
                                            <div class="form-group mb-0">
                                                <label for="password">New Password</label>
                                                <div class="modern-input-group">
                                                    <i class="fas fa-key input-icon-left"></i>
                                                    <input type="password" class="modern-form-control" name="password" id="password" placeholder="Enter new password" autocomplete="new-password">
                                                    <i id="togglePassword" class="fas fa-eye eye-toggle-btn"></i>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Confirm Password -->
                                        <div class="col-md-6 mb-3">
                                            <div class="form-group mb-0">
                                                <label for="confirmPassword">Confirm New Password</label>
                                                <div class="modern-input-group">
                                                    <i class="fas fa-shield-halved input-icon-left"></i>
                                                    <input type="password" class="modern-form-control" id="confirmPassword" placeholder="Re-type new password" autocomplete="new-password">
                                                    <i id="toggleConfirmPassword" class="fas fa-eye eye-toggle-btn"></i>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mt-4 pt-3 border-top text-end">
                                        <button type="submit" id="submitBtn" name="update" class="btn-modern-primary">
                                            <i class="fas fa-floppy-disk"></i>
                                            <span>Save Profile Changes</span>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- ── RIGHT COLUMN: SMART DIGITAL IDENTITY PASS & WORK METADATA ── -->
                            <div class="col-lg-5">

                                <!-- ── CARD 1: SMART DIGITAL IDENTITY & ATTENDANCE PASS ── -->
                                <div class="smart-pass-container mb-4">
                                    <div class="smart-pass-top-band">
                                        <div class="pass-brand-title">
                                            <i class="fa-solid fa-microchip pass-chip-icon"></i>
                                            <span>SETTribe &bull; Smart Pass</span>
                                        </div>
                                        <div class="d-flex align-items-center gap-2">
                                            <i class="fa-solid fa-wifi text-muted" style="font-size:0.85rem;"></i>
                                            <span class="badge badge-success px-2 py-1" style="font-size:0.7rem; border-radius:12px;">Active</span>
                                        </div>
                                    </div>

                                    <div class="qr-badge-inner-box">
                                        <!-- QR Code Frame with Bevel & Reticles -->
                                        <div class="qr-frame-shadow">
                                            <div id="clientQrCodeBox">
                                                <?php if (!empty($qrDataUri)): ?>
                                                    <img src="<?= $qrDataUri ?>" 
                                                         id="employeeQrCodeImg"
                                                         alt="Official Attendance QR Code"
                                                         style="width:200px; height:200px; display:block; margin:0 auto; object-fit:contain;" />
                                                <?php elseif (!empty($profileQrSvg)): ?>
                                                    <?= $profileQrSvg ?>
                                                <?php else: ?>
                                                    <img src="../ajax/get_employee_qr.php?user_id=<?= urlencode($userId) ?>" 
                                                         id="employeeQrCodeImg"
                                                         alt="Official Attendance QR Code"
                                                         style="width:200px; height:200px; display:block; margin:0 auto; object-fit:contain;" />
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <div class="pass-emp-name">
                                            <?= htmlspecialchars($fullName) ?>
                                        </div>
                                        <div class="pass-emp-id">
                                            <i class="fa-solid fa-id-card"></i>
                                            <span>ID: <?= htmlspecialchars($empId) ?></span>
                                        </div>
                                        <div class="pass-emp-meta">
                                            <?= htmlspecialchars($designation) ?> &bull; <?= htmlspecialchars($department) ?>
                                        </div>

                                        <!-- Mode-Specific Attendance Rule Pill -->
                                        <?php if (strcasecmp(trim($workMode), 'Online') === 0): ?>
                                        <div class="mode-guidance-pill mode-guidance-online">
                                            <i class="fas fa-laptop text-info fa-lg"></i>
                                            <div>
                                                <strong>Online Work Mode:</strong> Selfie Attendance only. Mandatory GPS location recorded.
                                            </div>
                                        </div>
                                        <?php else: ?>
                                        <div class="mode-guidance-pill mode-guidance-offline">
                                            <i class="fas fa-qrcode text-warning fa-lg"></i>
                                            <div>
                                                <strong>Offline Work Mode:</strong> Step 1: Scan QR Code &bull; Step 2: Face Scan.
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Quick Actions Footer -->
                                    <div class="pass-actions-row">
                                        <button type="button" class="btn btn-sm btn-primary btn-pass-action" id="btnDownloadQrPng" onclick="downloadQrAsPng()">
                                            <i class="fas fa-id-badge"></i> Download Badge (PNG)
                                        </button>
                                        <a href="../ajax/get_employee_qr.php?user_id=<?= urlencode($userId) ?>&download=1" 
                                           class="btn btn-sm btn-outline-primary btn-pass-action">
                                            <i class="fas fa-file-code"></i> Vector SVG
                                        </a>
                                        <button type="button" class="btn btn-sm btn-outline-secondary btn-pass-action" onclick="window.print()">
                                            <i class="fas fa-print"></i> Print
                                        </button>
                                    </div>
                                    <canvas id="qrBadgeCanvas" style="display:none;"></canvas>
                                </div>

                                <!-- ── CARD 2: ORGANIZATION & WORK METADATA (MODERN 2-COLUMN GRID) ── -->
                                <div class="modern-profile-card">
                                    <div class="card-header-styled">
                                        <div class="card-header-title">
                                            <div class="card-header-icon icon-emerald">
                                                <i class="fas fa-sitemap"></i>
                                            </div>
                                            <span>Organization &amp; Work Profile</span>
                                        </div>
                                        <span class="badge badge-light border text-muted" style="font-size:0.75rem; border-radius:10px;">
                                            Official
                                        </span>
                                    </div>

                                    <div class="org-spec-grid">
                                        <div class="org-spec-box">
                                            <div class="org-spec-icon icon-purple">
                                                <i class="fas fa-user-tag"></i>
                                            </div>
                                            <div>
                                                <div class="org-spec-label">Designation</div>
                                                <div class="org-spec-value"><?= htmlspecialchars($designation) ?></div>
                                            </div>
                                        </div>

                                        <div class="org-spec-box">
                                            <div class="org-spec-icon icon-blue">
                                                <i class="fas fa-network-wired"></i>
                                            </div>
                                            <div>
                                                <div class="org-spec-label">Department</div>
                                                <div class="org-spec-value"><?= htmlspecialchars($department) ?></div>
                                            </div>
                                        </div>

                                        <div class="org-spec-box">
                                            <div class="org-spec-icon icon-amber">
                                                <i class="fas fa-building"></i>
                                            </div>
                                            <div>
                                                <div class="org-spec-label">Branch / Office</div>
                                                <div class="org-spec-value"><?= htmlspecialchars($branch) ?></div>
                                            </div>
                                        </div>

                                        <div class="org-spec-box">
                                            <div class="org-spec-icon icon-emerald">
                                                <i class="fas fa-laptop-house"></i>
                                            </div>
                                            <div>
                                                <div class="org-spec-label">Work Mode</div>
                                                <div class="org-spec-value">
                                                    <span class="badge <?= strcasecmp(trim($workMode), 'Online') === 0 ? 'badge-info' : 'badge-warning' ?>" style="font-size:0.78rem;">
                                                        <?= htmlspecialchars($workMode) ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="org-spec-box">
                                            <div class="org-spec-icon icon-blue">
                                                <i class="fas fa-calendar-check"></i>
                                            </div>
                                            <div>
                                                <div class="org-spec-label">Joining Date</div>
                                                <div class="org-spec-value"><?= htmlspecialchars($startDate) ?></div>
                                            </div>
                                        </div>

                                        <div class="org-spec-box">
                                            <div class="org-spec-icon icon-emerald">
                                                <i class="fas fa-clock"></i>
                                            </div>
                                            <div>
                                                <div class="org-spec-label">Tenure Status</div>
                                                <div class="org-spec-value text-success"><?= htmlspecialchars($endDate) ?></div>
                                            </div>
                                        </div>
                                    </div>

                                    <?php if (!$isHrOrAdmin): ?>
                                    <div class="p-2 px-3 bg-light rounded text-muted d-flex align-items-center gap-2" style="font-size:0.78rem; border:1px solid #e2e8f0;">
                                        <i class="fas fa-circle-info text-primary"></i>
                                        <span>Work profile metadata is managed by HR &amp; Admin. Contact HR for updates.</span>
                                    </div>
                                    <?php endif; ?>
                                </div>

                            </div>

                        </div>
                    </form>

                </div>
            </div>
        </div>
    </div>

    <!-- JS Scripts -->
    <script src="../vendors/js/vendor.bundle.base.js"></script>
    <script src="../js/off-canvas.js"></script>
    <script src="../js/hoverable-collapse.js"></script>
    <script src="../js/template.js"></script>
    <script src="../js/settings.js"></script>

    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="../vendors/js/qrcode.min.js"></script>

    <script>
    $(document).ready(function () {

        // Client-side QR Code rendering (HTML5 Canvas & Image fallback)
        (function renderClientQr() {
            const qrBox = document.getElementById('clientQrCodeBox');
            const qrDataStr = <?= json_encode($qrJson ?? '') ?>;
            if (qrBox && qrDataStr && typeof QRCode !== 'undefined') {
                try {
                    qrBox.innerHTML = '';
                    new QRCode(qrBox, {
                        text: qrDataStr,
                        width: 200,
                        height: 200,
                        colorDark: "#0f172a",
                        colorLight: "#ffffff",
                        correctLevel: QRCode.CorrectLevel.M
                    });
                } catch (err) {
                    console.warn('QRCode JS render error:', err);
                }
            }
        })();

        // Flatpickr for Date of Birth
        flatpickr("#dob", {
            dateFormat: "Y-m-d",
            maxDate: new Date()
        });

        // Instant image upload live preview
        $('#imageUpload').on('change', function (e) {
            const file = e.target.files[0];
            if (file) {
                const ext = file.name.split('.').pop().toLowerCase();
                if (['jpeg', 'jpg', 'png'].includes(ext)) {
                    const reader = new FileReader();
                    reader.onload = function (evt) {
                        $('#avatarPreview').attr('src', evt.target.result);
                    };
                    reader.readAsDataURL(file);
                } else {
                    Swal.fire('Invalid File', 'Please select a JPEG or PNG image file.', 'error');
                    this.value = '';
                }
            }
        });

        // Password show/hide toggle
        $('#togglePassword').on('click', function () {
            const pwd = $('#password');
            const type = pwd.attr('type') === 'password' ? 'text' : 'password';
            pwd.attr('type', type);
            $(this).toggleClass('fa-eye fa-eye-slash');
        });

        $('#toggleConfirmPassword').on('click', function () {
            const pwd = $('#confirmPassword');
            const type = pwd.attr('type') === 'password' ? 'text' : 'password';
            pwd.attr('type', type);
            $(this).toggleClass('fa-eye fa-eye-slash');
        });

        // Form submission validation
        $('#profileForm').on('submit', function (e) {
            const pwd = $('#password').val();
            const confirmPwd = $('#confirmPassword').val();

            if (pwd !== '' && pwd !== confirmPwd) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Password Mismatch',
                    text: 'New password and confirm password do not match. Please check and try again.',
                    confirmButtonColor: '#4f46e5'
                });
                return false;
            }

            $('#submitBtn').prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-2"></i> Saving Changes...');
        });
    });

    // Generate high-resolution digital QR Attendance Badge as PNG
    function downloadQrAsPng() {
        const qrBox = document.getElementById('clientQrCodeBox');
        const qrCanvas = qrBox ? qrBox.querySelector('canvas') : null;
        const qrImg = qrBox ? qrBox.querySelector('img') : document.getElementById('employeeQrCodeImg');

        const canvas = document.getElementById('qrBadgeCanvas');
        const ctx = canvas.getContext('2d');
        const badgeWidth = 480;
        const badgeHeight = 630;
        canvas.width = badgeWidth;
        canvas.height = badgeHeight;

        // White card background with subtle border
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, badgeWidth, badgeHeight);

        // Header gradient
        const grad = ctx.createLinearGradient(0, 0, badgeWidth, 0);
        grad.addColorStop(0, '#0f172a');
        grad.addColorStop(0.5, '#1e1b4b');
        grad.addColorStop(1, '#312e81');
        ctx.fillStyle = grad;
        ctx.fillRect(0, 0, badgeWidth, 90);

        // Header text
        ctx.fillStyle = '#ffffff';
        ctx.font = 'bold 20px "Plus Jakarta Sans", sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('SETTribe IT Solutions', badgeWidth / 2, 40);

        ctx.font = '600 12px "Plus Jakarta Sans", sans-serif';
        ctx.fillStyle = '#94a3b8';
        ctx.fillText('Official Attendance & Identity QR Badge', badgeWidth / 2, 68);

        // Draw QR Image box
        const qrSize = 310;
        const qrX = (badgeWidth - qrSize) / 2;
        const qrY = 115;

        ctx.strokeStyle = '#e2e8f0';
        ctx.lineWidth = 2;
        ctx.strokeRect(qrX - 10, qrY - 10, qrSize + 20, qrSize + 20);

        function renderAndSave(imgElement) {
            if (imgElement) {
                try {
                    ctx.drawImage(imgElement, qrX, qrY, qrSize, qrSize);
                } catch (e) {
                    console.warn('Canvas drawImage notice:', e);
                }
            }

            // Employee Name & Details
            ctx.textAlign = 'center';
            ctx.fillStyle = '#0f172a';
            ctx.font = 'bold 20px "Plus Jakarta Sans", sans-serif';
            ctx.fillText('<?= addslashes($fullName) ?>', badgeWidth / 2, 475);

            ctx.fillStyle = '#4f46e5';
            ctx.font = 'bold 15px "Plus Jakarta Sans", sans-serif';
            ctx.fillText('Employee ID: <?= addslashes($empId) ?>', badgeWidth / 2, 505);

            ctx.fillStyle = '#64748b';
            ctx.font = '600 13px "Plus Jakarta Sans", sans-serif';
            ctx.fillText('<?= addslashes($designation) ?> • <?= addslashes($department) ?>', badgeWidth / 2, 532);

            // Work mode badge footer
            ctx.fillStyle = '<?= strcasecmp(trim($workMode), "Online") === 0 ? "#0284c7" : "#d97706" ?>';
            ctx.font = 'bold 12px "Plus Jakarta Sans", sans-serif';
            ctx.fillText('WORK MODE: <?= strtoupper(addslashes($workMode)) ?>', badgeWidth / 2, 565);

            // Bottom verified footer
            ctx.fillStyle = '#10b981';
            ctx.font = '600 11px "Plus Jakarta Sans", sans-serif';
            ctx.fillText('✓ AMS BIOMETRIC & GPS VERIFIED', badgeWidth / 2, 595);

            // Download Trigger
            try {
                const dataUrl = canvas.toDataURL('image/png');
                const link = document.createElement('a');
                link.download = 'SETTribe_QR_<?= preg_replace("/[^a-zA-Z0-9_-]/", "", $empId) ?>.png';
                link.href = dataUrl;
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            } catch (err) {
                console.error(err);
                Swal.fire('Download Notice', 'Please use the "Download Vector (SVG)" button for your high-res badge.', 'info');
            }
        }

        if (qrCanvas) {
            renderAndSave(qrCanvas);
        } else if (qrImg && qrImg.src) {
            const img = new Image();
            img.crossOrigin = 'anonymous';
            img.onload = () => renderAndSave(img);
            img.onerror = () => renderAndSave(null);
            img.src = qrImg.src;
        } else {
            Swal.fire('Error', 'QR Code is not ready yet. Please wait a moment.', 'warning');
        }
    }
    </script>

    <?php
    require_once('../include/sweetAlert.php');
    if (isset($_SESSION['status'])) {
        $status = $_SESSION['status'];
        $msg    = $_SESSION['msg'];
        if ($status != true) {
            toastMsg('error', $msg);
        } else {
            toastMsg('success', $msg);
        }
        unset($_SESSION['status']);
        unset($_SESSION['msg']);
    }
    ?>
</body>
</html>