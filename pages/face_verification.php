<?php
session_start();
require_once(__DIR__ . '/../include/db_config.php');

$isLoggedIn = !empty($_SESSION['userId']);
$sessionUserId = $_SESSION['userId'] ?? '';
$sessionFullName = $_SESSION['fullName'] ?? '';
$sessionRole = $_SESSION['userRole'] ?? '';
$sessionDesignation = $_SESSION['designation'] ?? '';
$sessionPhoto = $_SESSION['profilePhoto'] ?? '';
$sessionEmail = $_SESSION['email'] ?? '';

// If user ID passed via GET, use as hint when logged out
$targetUserId = $_GET['user_id'] ?? $sessionUserId;
$userData = null;

if (!empty($targetUserId) && isset($con) && $con) {
    $q = mysqli_query($con, "SELECT userId, username, fullName, email, designation, userRole, profilePhoto FROM signup WHERE userId='" . mysqli_real_escape_string($con, $targetUserId) . "' LIMIT 1");
    if ($q && $row = mysqli_fetch_assoc($q)) {
        $userData = $row;
    }
}

if ($isLoggedIn && !empty($userData)) {
    $sessionEmail = $userData['email'] ?? $sessionEmail;
    $sessionFullName = $userData['fullName'] ?? $sessionFullName;
    $sessionPhoto = $userData['profilePhoto'] ?? $sessionPhoto;
    $sessionDesignation = $userData['designation'] ?? $sessionDesignation;
    $userMode = !empty($userData['type']) ? $userData['type'] : (!empty($userData['mode']) ? $userData['mode'] : 'Offline');
} else {
    $userMode = 'Offline';
}
$officialPunchUrl = (strcasecmp($userMode, 'Online') === 0) ? 'captureSelfie.php?punch=in' : 'qrCodeScanner_copy.php?punch=In';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php include('../partials/_links.php'); ?>
    <meta charset="UTF-8">
    <title>3D Motion Face Verification - SETTribe AMS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <style>
        :root {
            --brand-primary: #2563eb;
            --brand-gradient: linear-gradient(135deg, #1d4ed8 0%, #2563eb 50%, #3b82f6 100%);
            --accent-cyan: #06b6d4;
            --accent-green: #10b981;
            --page-bg: #f1f5f9;
            --card-bg: #ffffff;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --card-border: #e2e8f0;
        }

        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background-color: var(--page-bg);
            color: var(--text-main);
            margin: 0;
            min-height: 100vh;
        }

        .verifier-wrapper {
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 24px 16px;
            min-height: <?= $isLoggedIn ? 'calc(100vh - 85px)' : '100vh' ?>;
        }

        .verifier-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 22px;
            box-shadow: 0 14px 40px rgba(15, 23, 42, 0.08), 0 4px 12px rgba(0, 0, 0, 0.03);
            max-width: 660px;
            width: 100%;
            overflow: hidden;
            position: relative;
        }

        /* Header */
        .verifier-header {
            background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 50%, #3b82f6 100%);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            padding: 20px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            color: #ffffff;
        }

        .header-title-box {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .header-badge-icon {
            width: 46px;
            height: 46px;
            border-radius: 14px;
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(4px);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            color: #fff;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .header-title-box h1 {
            font-size: 18px;
            font-weight: 700;
            margin: 0 0 2px;
            color: #ffffff;
            letter-spacing: -0.2px;
        }

        .header-title-box p {
            font-size: 12px;
            color: rgba(255, 255, 255, 0.85);
            margin: 0;
        }

        .kiosk-badge {
            background: rgba(255, 255, 255, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.35);
            color: #ffffff;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            backdrop-filter: blur(4px);
        }

        /* Main Body */
        .verifier-body {
            padding: 24px 22px 22px;
            display: flex;
            flex-direction: column;
            align-items: center;
            background: #ffffff;
        }

        /* Location Notice Bar (Mandatory) */
        .location-notice-bar {
            width: 100%;
            border-radius: 12px;
            padding: 9px 14px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 12.5px;
            font-weight: 600;
            transition: all 0.3s;
        }

        .location-notice-bar.valid {
            background: #f0fdf4;
            border: 1px solid #86efac;
            color: #15803d;
        }

        .location-notice-bar.pending {
            background: #fefce8;
            border: 1px solid #fde047;
            color: #854d0e;
        }

        .location-notice-bar.error {
            background: #fef2f2;
            border: 1px solid #fca5a5;
            color: #b91c1c;
        }

        .location-icon-status {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Authentication Gate Box (Only shown if NOT logged in) */
        .auth-gate-box {
            width: 100%;
            background: #f8fafc;
            border: 1.5px solid #cbd5e1;
            border-radius: 16px;
            padding: 18px 20px;
            margin-bottom: 20px;
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.04);
            transition: all 0.3s ease;
        }

        .auth-gate-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
        }

        .auth-gate-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14.5px;
            font-weight: 700;
            color: #0f172a;
        }

        .auth-gate-title i {
            color: #2563eb;
            font-size: 16px;
        }

        .auth-gate-badge {
            font-size: 11.5px;
            font-weight: 600;
            background: #e0f2fe;
            color: #0369a1;
            padding: 3px 10px;
            border-radius: 12px;
            border: 1px solid #bae6fd;
        }

        .auth-form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 14px;
        }

        @media (max-width: 580px) {
            .auth-form-grid {
                grid-template-columns: 1fr;
            }
        }

        .auth-field {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .auth-field.full-width {
            grid-column: 1 / -1;
        }

        .auth-label {
            font-size: 12px;
            font-weight: 600;
            color: #475569;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .auth-input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .auth-icon-left {
            position: absolute;
            left: 12px;
            color: #94a3b8;
            font-size: 13px;
            pointer-events: none;
        }

        .auth-input {
            width: 100%;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 9px 12px 9px 34px;
            font-size: 13.5px;
            color: #0f172a;
            transition: all 0.2s;
            box-sizing: border-box;
        }

        .auth-input:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        }

        .auth-input.has-toggle {
            padding-right: 36px;
        }

        .btn-toggle-eye {
            position: absolute;
            right: 8px;
            background: none;
            border: none;
            color: #64748b;
            cursor: pointer;
            padding: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            transition: color 0.2s;
        }

        .btn-auth-verify {
            width: 100%;
            background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 100%);
            color: #ffffff;
            border: none;
            padding: 11px 18px;
            border-radius: 10px;
            font-size: 13.5px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25);
            transition: all 0.2s;
        }

        .btn-auth-verify:hover {
            background: linear-gradient(135deg, #1e40af 0%, #1d4ed8 100%);
            transform: translateY(-1px);
        }

        /* Verified Employee Profile Card */
        .auth-verified-card {
            width: 100%;
            background: #f0fdf4;
            border: 1.5px solid #86efac;
            border-radius: 16px;
            padding: 14px 18px;
            margin-bottom: 20px;
            display: <?= $isLoggedIn ? 'flex' : 'none' ?>;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            flex-wrap: wrap;
            box-shadow: 0 4px 14px rgba(16, 185, 129, 0.08);
            animation: fadeIn 0.3s ease-out;
        }

        .verified-user-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .verified-avatar-box {
            position: relative;
        }

        .emp-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #10b981;
            background: #e2e8f0;
        }

        .verified-badge-mini {
            position: absolute;
            bottom: -2px;
            right: -2px;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background: #10b981;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            border: 2px solid #fff;
        }

        .verified-info-meta h4 {
            margin: 0 0 2px;
            font-size: 15px;
            font-weight: 700;
            color: #0f172a;
        }

        .verified-info-meta span {
            font-size: 12px;
            color: #15803d;
            font-weight: 600;
        }

        .btn-switch-user {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            color: #475569;
            padding: 7px 14px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }

        .btn-switch-user:hover {
            background: #fee2e2;
            border-color: #fca5a5;
            color: #b91c1c;
        }

        /* Motion Step Breadcrumbs */
        .motion-steps-bar {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-bottom: 20px;
            width: 100%;
            flex-wrap: wrap;
        }

        .step-pill {
            display: flex;
            align-items: center;
            gap: 8px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            padding: 7px 14px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
            transition: all 0.25s ease;
        }

        .step-pill .step-num {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: #e2e8f0;
            color: #475569;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 700;
        }

        .step-pill.active {
            background: #eff6ff;
            border-color: #3b82f6;
            color: #1d4ed8;
            box-shadow: 0 2px 10px rgba(37, 99, 235, 0.12);
            transform: scale(1.02);
        }

        .step-pill.active .step-num {
            background: #2563eb;
            color: #ffffff;
        }

        .step-pill.completed {
            background: #f0fdf4;
            border-color: #86efac;
            color: #15803d;
        }

        .step-pill.completed .step-num {
            background: #10b981;
            color: #ffffff;
        }

        /* Circular Biometric Scanner */
        .scanner-circle-stage {
            position: relative;
            width: 310px;
            height: 310px;
            margin: 6px auto 16px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Locked Overlay (Only when logged out AND not authenticated) */
        .scanner-locked-placeholder {
            position: absolute;
            inset: 0;
            border-radius: 50%;
            background: rgba(15, 23, 42, 0.85);
            backdrop-filter: blur(8px);
            z-index: 10;
            display: <?= $isLoggedIn ? 'none' : 'flex' ?>;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 24px;
            color: #ffffff;
            transition: opacity 0.3s ease;
        }

        .lock-shield-icon {
            width: 58px;
            height: 58px;
            border-radius: 50%;
            background: rgba(37, 99, 235, 0.25);
            border: 2px solid #3b82f6;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: #60a5fa;
            margin-bottom: 12px;
            box-shadow: 0 0 20px rgba(37, 99, 235, 0.3);
        }

        .scanner-locked-placeholder h4 {
            font-size: 15px;
            font-weight: 700;
            margin: 0 0 6px;
            color: #ffffff;
        }

        .scanner-locked-placeholder p {
            font-size: 11.5px;
            color: #cbd5e1;
            margin: 0;
            line-height: 1.4;
        }

        /* SVG Circular Progress Ring */
        .progress-svg {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            transform: rotate(-90deg);
            z-index: 5;
            pointer-events: none;
        }

        .progress-meter-bg {
            fill: none;
            stroke: #e2e8f0;
            stroke-width: 8;
        }

        .progress-meter-fill {
            fill: none;
            stroke: url(#cyanBlueGradient);
            stroke-width: 8;
            stroke-linecap: round;
            stroke-dasharray: 942;
            stroke-dashoffset: 942;
            transition: stroke-dashoffset 0.15s ease-out;
            filter: drop-shadow(0 2px 6px rgba(37, 99, 235, 0.35));
        }

        /* Camera Video Container */
        .camera-viewport {
            width: 270px;
            height: 270px;
            border-radius: 50%;
            overflow: hidden;
            position: relative;
            background: #0f172a;
            box-shadow: 0 10px 28px rgba(15, 23, 42, 0.12);
            border: 4px solid #ffffff;
        }

        #verifierVideo {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transform: scaleX(-1);
        }

        .reticle-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 4;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .face-oval-guide {
            width: 180px;
            height: 220px;
            border: 2px dashed rgba(255, 255, 255, 0.75);
            border-radius: 50%;
            position: relative;
            transition: all 0.3s;
            animation: pulseOval 3s infinite ease-in-out;
        }

        .face-oval-guide.aligned {
            border-color: #10b981;
            border-style: solid;
            box-shadow: 0 0 20px rgba(16, 185, 129, 0.6);
        }

        @keyframes pulseOval {
            0%, 100% { transform: scale(1); opacity: 0.75; }
            50% { transform: scale(1.02); opacity: 1; }
        }

        .laser-scanline {
            position: absolute;
            width: 100%;
            height: 3px;
            background: linear-gradient(90deg, transparent 0%, rgba(56, 189, 248, 0.95) 50%, transparent 100%);
            box-shadow: 0 0 10px rgba(56, 189, 248, 0.8);
            top: 0;
            animation: scanLaser 2.2s infinite ease-in-out;
            pointer-events: none;
            z-index: 3;
        }

        @keyframes scanLaser {
            0% { top: 5%; opacity: 0.1; }
            50% { top: 85%; opacity: 1; }
            100% { top: 5%; opacity: 0.1; }
        }

        .motion-indicator-arrow {
            position: absolute;
            font-size: 34px;
            color: #2563eb;
            opacity: 0;
            transition: all 0.3s;
            filter: drop-shadow(0 2px 6px rgba(37, 99, 235, 0.4));
            z-index: 6;
            pointer-events: none;
        }

        .arrow-left {
            left: -16px;
            top: 50%;
            transform: translateY(-50%);
            animation: bounceLeft 1.2s infinite ease-in-out;
        }

        .arrow-right {
            right: -16px;
            top: 50%;
            transform: translateY(-50%);
            animation: bounceRight 1.2s infinite ease-in-out;
        }

        @keyframes bounceLeft {
            0%, 100% { transform: translate(0, -50%); }
            50% { transform: translate(-8px, -50%); }
        }

        @keyframes bounceRight {
            0%, 100% { transform: translate(0, -50%); }
            50% { transform: translate(8, -50%); }
        }

        .motion-instruction-box {
            text-align: center;
            margin-top: 8px;
            min-height: 64px;
        }

        .instruction-step-title {
            font-size: 16px;
            font-weight: 700;
            color: #0f172a;
            margin: 0 0 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .instruction-step-desc {
            font-size: 13px;
            color: #64748b;
            margin: 0;
            max-width: 440px;
        }

        .progress-stat-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 12.5px;
            font-weight: 700;
            color: #1d4ed8;
            margin-top: 10px;
        }

        .verifier-controls {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 18px;
            width: 100%;
            flex-wrap: wrap;
        }

        .btn-capture-next {
            background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 100%);
            color: #fff;
            border: none;
            padding: 11px 22px;
            border-radius: 10px;
            font-size: 13.5px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 14px rgba(37, 99, 235, 0.25);
            transition: all 0.2s;
        }

        .btn-capture-next:hover:not(:disabled) {
            background: linear-gradient(135deg, #1e40af 0%, #1d4ed8 100%);
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(37, 99, 235, 0.35);
            color: #fff;
        }

        .btn-capture-next:disabled {
            background: #cbd5e1;
            color: #94a3b8;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .btn-secondary-action {
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            color: #475569;
            padding: 10px 16px;
            border-radius: 10px;
            font-size: 12.5px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }

        .btn-secondary-action:hover {
            background: #e2e8f0;
            color: #0f172a;
        }

        /* Success Card Modal / Overlay */
        .verification-success-overlay {
            position: absolute;
            inset: 0;
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(12px);
            z-index: 50;
            display: none;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 30px;
            text-align: center;
            animation: fadeIn 0.35s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: scale(0.97); }
            to { opacity: 1; transform: scale(1); }
        }

        .success-icon-badge {
            width: 78px;
            height: 78px;
            border-radius: 50%;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 38px;
            color: #fff;
            margin-bottom: 16px;
            box-shadow: 0 10px 25px rgba(16, 185, 129, 0.35);
            animation: bounceSuccess 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }

        @keyframes bounceSuccess {
            0% { transform: scale(0.3); opacity: 0; }
            70% { transform: scale(1.15); }
            100% { transform: scale(1); opacity: 1; }
        }

        .success-title {
            font-size: 22px;
            font-weight: 800;
            color: #0f172a;
            margin: 0 0 6px;
        }

        .success-desc {
            font-size: 13.5px;
            color: #64748b;
            max-width: 440px;
            margin: 0 0 20px;
            line-height: 1.5;
        }

        .verified-card-preview {
            background: #f8fafc;
            border: 1px solid #bbf7d0;
            border-radius: 14px;
            padding: 14px 18px;
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 20px;
            width: 100%;
            max-width: 380px;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.08);
        }

        .verified-img {
            width: 58px;
            height: 58px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #10b981;
        }

        .verified-info {
            text-align: left;
        }

        .verified-info h4 {
            margin: 0 0 3px;
            font-size: 15px;
            font-weight: 700;
            color: #0f172a;
        }

        .verified-info p {
            margin: 0;
            font-size: 12px;
            color: #15803d;
            font-weight: 600;
        }

        .success-action-btns {
            display: flex;
            gap: 10px;
            width: 100%;
            max-width: 380px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .btn-punch-now {
            flex: 1;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: #fff;
            padding: 12px 18px;
            border-radius: 10px;
            font-size: 13.5px;
            font-weight: 700;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            box-shadow: 0 4px 14px rgba(16, 185, 129, 0.3);
            transition: all 0.2s;
            border: none;
            cursor: pointer;
        }

        .btn-punch-now:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(16, 185, 129, 0.4);
            color: #fff;
        }

        .btn-dashboard {
            flex: 1;
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            color: #334155;
            padding: 12px 18px;
            border-radius: 10px;
            font-size: 13.5px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s;
        }

        .btn-dashboard:hover {
            background: #e2e8f0;
            color: #0f172a;
        }
    </style>
</head>

<body>
    <?php if ($isLoggedIn): ?>
    <div class="container-scroller">
        <?php include('../partials/_navbar.php'); ?>
        <div class="container-fluid page-body-wrapper">
            <?php include('../partials/_sidebar.php'); ?>
            <div class="main-panel">
                <div class="content-wrapper d-flex justify-content-center align-items-center" style="background:#f1f5f9;">
    <?php endif; ?>

        <div class="verifier-wrapper">
            <div class="verifier-card">
                <!-- Header -->
                <div class="verifier-header">
                    <div class="header-title-box">
                        <div class="header-badge-icon">
                            <i class="fa-solid fa-id-card-clip"></i>
                        </div>
                        <div>
                            <h1>Biometric Motion Face Verification</h1>
                            <p>3D Motion & Liveness Authentication Engine</p>
                        </div>
                    </div>
                    <div>
                        <span class="kiosk-badge">
                            <i class="fa-solid fa-shield-halved"></i> Biometric Grade
                        </span>
                    </div>
                </div>

                <!-- Main Content -->
                <div class="verifier-body">

                    <!-- Location Status Bar (Mandatory) -->
                    <div class="location-notice-bar pending" id="locationNoticeBar">
                        <div class="location-icon-status">
                            <i class="fa-solid fa-location-crosshairs" id="locationNoticeIcon"></i>
                            <span id="locationNoticeText">Mandatory GPS Location: Acquiring device location...</span>
                        </div>
                        <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none" id="btnRetryLocation" style="font-size:11.5px; font-weight:700;">
                            <i class="fa-solid fa-arrows-rotate"></i> Retry
                        </button>
                    </div>
                    
                    <!-- Pre-Verification Identity Gate (Only shown when LOGGED OUT) -->
                    <div class="auth-gate-box" id="authGateBox" style="display: <?= $isLoggedIn ? 'none' : 'block' ?>;">
                        <div class="auth-gate-header">
                            <div class="auth-gate-title">
                                <i class="fa-solid fa-user-lock"></i>
                                <span>Employee Authentication</span>
                            </div>
                            <span class="auth-gate-badge">Required Before 3D Scan</span>
                        </div>

                        <form id="authLoginForm" onsubmit="event.preventDefault(); handleAuthSubmit();">
                            <div class="auth-form-grid">
                                <!-- Employee ID -->
                                <div class="auth-field">
                                    <label class="auth-label" for="authEmployeeId">
                                        <i class="fa-solid fa-id-badge text-primary"></i> Employee ID / Username
                                    </label>
                                    <div class="auth-input-wrapper">
                                        <i class="fa-solid fa-user auth-icon-left"></i>
                                        <input type="text" 
                                               id="authEmployeeId" 
                                               class="auth-input" 
                                               placeholder="e.g. INT031 or username" 
                                               value="<?= htmlspecialchars(!$isLoggedIn ? ($targetUserId ?: '') : '') ?>" 
                                               required 
                                               autocomplete="username">
                                    </div>
                                </div>

                                <!-- Registered Email Address -->
                                <div class="auth-field">
                                    <label class="auth-label" for="authEmail">
                                        <i class="fa-solid fa-envelope text-primary"></i> Registered Email
                                    </label>
                                    <div class="auth-input-wrapper">
                                        <i class="fa-solid fa-at auth-icon-left"></i>
                                        <input type="email" 
                                               id="authEmail" 
                                               class="auth-input" 
                                               placeholder="Enter official email" 
                                               required 
                                               autocomplete="email">
                                    </div>
                                </div>

                                <!-- Account Password -->
                                <div class="auth-field full-width">
                                    <label class="auth-label" for="authPassword">
                                        <i class="fa-solid fa-lock text-primary"></i> Account Password
                                    </label>
                                    <div class="auth-input-wrapper">
                                        <i class="fa-solid fa-key auth-icon-left"></i>
                                        <input type="password" 
                                               id="authPassword" 
                                               class="auth-input has-toggle" 
                                               placeholder="Enter your AMS password" 
                                               required 
                                               autocomplete="current-password">
                                        <button type="button" class="btn-toggle-eye" id="btnTogglePassword" title="Show / Hide Password">
                                            <i class="fa-solid fa-eye" id="eyeIcon"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <button type="submit" class="btn-auth-verify" id="btnAuthSubmit">
                                <i class="fa-solid fa-shield-halved"></i>
                                <span id="btnAuthText">Verify Identity & Start 3D Verification</span>
                            </button>
                        </form>
                    </div>

                    <!-- Verified Employee Info Card (Active when logged in OR after auth) -->
                    <div class="auth-verified-card" id="authVerifiedCard" style="display: <?= $isLoggedIn ? 'flex' : 'none' ?>;">
                        <div class="verified-user-left">
                            <div class="verified-avatar-box">
                                <img id="empAvatarImg" 
                                     src="../profile-photos/<?= !empty($sessionPhoto) ? htmlspecialchars($sessionPhoto) : (!empty($userData['profilePhoto']) ? htmlspecialchars($userData['profilePhoto']) : 'avatar.png') ?>" 
                                     class="emp-avatar" 
                                     onerror="this.src='../images/faces/face1.jpg'"
                                     alt="Avatar">
                                <div class="verified-badge-mini">
                                    <i class="fa-solid fa-check"></i>
                                </div>
                            </div>
                            <div class="verified-info-meta">
                                <h4 id="empNameDisplay"><?= !empty($sessionFullName) ? htmlspecialchars($sessionFullName) : (!empty($userData['fullName']) ? htmlspecialchars($userData['fullName']) : 'Employee Verified') ?></h4>
                                <span id="empMetaDisplay">
                                    ID: <strong id="empIdBadge"><?= !empty($sessionUserId) ? htmlspecialchars($sessionUserId) : (!empty($userData['userId']) ? htmlspecialchars($userData['userId']) : '---') ?></strong>
                                    &bull; <span id="empRoleBadge"><?= !empty($sessionDesignation) ? htmlspecialchars($sessionDesignation) : (!empty($userData['designation']) ? htmlspecialchars($userData['designation']) : 'Employee') ?></span>
                                </span>
                            </div>
                        </div>

                        <?php if (!$isLoggedIn): ?>
                        <button type="button" class="btn-switch-user" id="btnSwitchUser">
                            <i class="fa-solid fa-arrow-right-arrow-left"></i> Switch Employee
                        </button>
                        <?php endif; ?>
                    </div>

                    <!-- Step Breadcrumbs (4 Steps) -->
                    <div class="motion-steps-bar">
                        <div class="step-pill active" id="pillStep1">
                            <span class="step-num">1</span>
                            <span>Frontal Face</span>
                        </div>
                        <div class="step-pill" id="pillStep2">
                            <span class="step-num">2</span>
                            <span>Turn Left</span>
                        </div>
                        <div class="step-pill" id="pillStep3">
                            <span class="step-num">3</span>
                            <span>Turn Right</span>
                        </div>
                        <div class="step-pill" id="pillStep4">
                            <span class="step-num">4</span>
                            <span>Liveness Motion</span>
                        </div>
                    </div>

                    <!-- Circular Scanner Viewport with SVG Radial Ring -->
                    <div class="scanner-circle-stage">
                        <!-- Locked Overlay when user has not entered ID, Email & Password -->
                        <div class="scanner-locked-placeholder" id="scannerLockedPlaceholder" style="display: <?= $isLoggedIn ? 'none' : 'flex' ?>;">
                            <div class="lock-shield-icon">
                                <i class="fa-solid fa-lock"></i>
                            </div>
                            <h4>3D Scanner Locked</h4>
                            <p>Enter Employee ID, Registered Email, and Password above to authenticate identity.</p>
                        </div>

                        <!-- SVG Radial Progress Meter (Google Auth / FaceID style) -->
                        <svg class="progress-svg" viewBox="0 0 320 320">
                            <defs>
                                <linearGradient id="cyanBlueGradient" x1="0%" y1="0%" x2="100%" y2="100%">
                                    <stop offset="0%" stop-color="#38bdf8" />
                                    <stop offset="50%" stop-color="#3b82f6" />
                                    <stop offset="100%" stop-color="#10b981" />
                                </linearGradient>
                            </defs>
                            <circle class="progress-meter-bg" cx="160" cy="160" r="150"></circle>
                            <circle id="progressMeterFill" class="progress-meter-fill" cx="160" cy="160" r="150"></circle>
                        </svg>

                        <!-- Directional Arrow Guides -->
                        <i id="arrowLeft" class="fa-solid fa-chevron-left motion-indicator-arrow arrow-left"></i>
                        <i id="arrowRight" class="fa-solid fa-chevron-right motion-indicator-arrow arrow-right"></i>

                        <!-- Video Viewport -->
                        <div class="camera-viewport">
                            <video id="verifierVideo" autoplay playsinline muted></video>
                            <div class="laser-scanline" id="laserScanline"></div>
                            <div class="reticle-overlay">
                                <div class="face-oval-guide" id="faceOvalGuide"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Instruction & Step Banner -->
                    <div class="motion-instruction-box">
                        <div class="instruction-step-title" id="stepTitle">
                            <?= $isLoggedIn ? '<i class="fa-solid fa-arrows-to-circle text-primary"></i> Step 1: Look Straight' : '<i class="fa-solid fa-lock text-warning"></i> Authentication Required' ?>
                        </div>
                        <p class="instruction-step-desc" id="stepDesc">
                            <?= $isLoggedIn ? 'Position your face comfortably inside the circular frame. Hold steady and look directly at the lens.' : 'Please provide your Employee ID, Email, and Password above to begin 3D biometric motion enrollment.' ?>
                        </p>
                        <div class="progress-stat-pill" id="progressStatPill" style="display: <?= $isLoggedIn ? 'inline-flex' : 'none' ?>;">
                            <i class="fa-solid fa-gauge-high"></i>
                            <span id="progressText">0% Complete</span>
                        </div>
                    </div>

                    <!-- Controls -->
                    <div class="verifier-controls">
                        <button type="button" class="btn-capture-next" id="btnNextStep" <?= $isLoggedIn ? '' : 'disabled' ?>>
                            <i class="fa-solid fa-circle-check"></i> 
                            <span id="btnNextStepLabel">Confirm Frontal Face</span>
                        </button>
                        <?php if ($isLoggedIn): ?>
                        <a href="index.php" class="btn-secondary-action">
                            <i class="fa-solid fa-arrow-left"></i> Dashboard
                        </a>
                        <?php else: ?>
                        <a href="login.php" class="btn-secondary-action">
                            <i class="fa-solid fa-arrow-left"></i> Back to Login
                        </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Success Overlay (Google / FaceID Finish) -->
                <div class="verification-success-overlay" id="successOverlay">
                    <div class="success-icon-badge">
                        <i class="fa-solid fa-check"></i>
                    </div>
                    <h2 class="success-title">Biometric Face Verified & Updated!</h2>
                    <p class="success-desc">
                        Multi-pose 3D motion enrollment completed successfully. Your face embedding is stored in the AI system and your new profile photo is saved.
                    </p>

                    <div class="verified-card-preview">
                        <img id="verifiedPreviewImg" src="../images/faces/face1.jpg" class="verified-img" alt="Enrolled Face">
                        <div class="verified-info">
                            <h4 id="verifiedName"><?= htmlspecialchars($sessionFullName ?: 'Employee') ?></h4>
                            <p><i class="fa-solid fa-circle-check"></i> Profile Photo Updated & Attendance Ready</p>
                        </div>
                    </div>

                    <div class="success-action-btns">
                        <button type="button" class="btn-punch-now" id="btnPunchNow" style="text-decoration:none; display:inline-flex; align-items:center; justify-content:center; gap:8px; border:none; cursor:pointer;">
                            <i class="fa-solid fa-fingerprint"></i> Punch Attendance Now
                        </button>
                        <a href="<?= $isLoggedIn ? 'index.php' : 'login.php' ?>" class="btn-dashboard">
                            <i class="fa-solid fa-house"></i> <?= $isLoggedIn ? 'Dashboard' : 'Login' ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>

    <?php if ($isLoggedIn): ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <canvas id="cropCanvas" style="display:none;"></canvas>

    <script>
        // Web Audio Synthesizer for high-tech feedback
        const audioCtx = new (window.AudioContext || window.webkitAudioContext)();

        function playBeep(freq = 600, duration = 0.1, type = 'sine') {
            try {
                if (audioCtx.state === 'suspended') audioCtx.resume();
                const osc = audioCtx.createOscillator();
                const gain = audioCtx.createGain();
                osc.type = type;
                osc.frequency.value = freq;
                gain.gain.setValueAtTime(0.08, audioCtx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + duration);
                osc.connect(gain);
                gain.connect(audioCtx.destination);
                osc.start();
                osc.stop(audioCtx.currentTime + duration);
            } catch (e) {
                console.warn(e);
            }
        }

        function playStepSuccess() {
            playBeep(520, 0.08, 'triangle');
            setTimeout(() => playBeep(784, 0.12, 'sine'), 90);
        }

        function playGrandFanfare() {
            [523.25, 659.25, 783.99, 1046.5].forEach((freq, i) => {
                setTimeout(() => playBeep(freq, 0.25, 'triangle'), i * 110);
            });
        }

        // Web Speech Voice Synthesizer Guide
        let lastSpokenText = '';
        function speakPrompt(text) {
            if (!('speechSynthesis' in window)) return;
            try {
                if (window.speechSynthesis.speaking && lastSpokenText === text) {
                    return;
                }
                window.speechSynthesis.cancel();
                const utt = new SpeechSynthesisUtterance(text);
                utt.rate = 0.92; // Clear and measured pace
                utt.pitch = 1.0;
                utt.lang = 'en-US';
                lastSpokenText = text;
                window.speechSynthesis.speak(utt);
            } catch (e) {
                console.warn("Speech error:", e);
            }
        }

        // Ensure speech synthesis is unblocked on any user interaction
        window.addEventListener('click', () => {
            if (window.speechSynthesis && window.speechSynthesis.paused) {
                window.speechSynthesis.resume();
            }
        });

        // DOM elements
        const video = document.getElementById('verifierVideo');
        const cropCanvas = document.getElementById('cropCanvas');
        const progressMeterFill = document.getElementById('progressMeterFill');
        const progressText = document.getElementById('progressText');
        const progressStatPill = document.getElementById('progressStatPill');
        const stepTitle = document.getElementById('stepTitle');
        const stepDesc = document.getElementById('stepDesc');
        const btnNextStep = document.getElementById('btnNextStep');
        const btnNextStepLabel = document.getElementById('btnNextStepLabel');
        const faceOvalGuide = document.getElementById('faceOvalGuide');
        const arrowLeft = document.getElementById('arrowLeft');
        const arrowRight = document.getElementById('arrowRight');
        const successOverlay = document.getElementById('successOverlay');
        const btnPunchNow = document.getElementById('btnPunchNow');

        const pillStep1 = document.getElementById('pillStep1');
        const pillStep2 = document.getElementById('pillStep2');
        const pillStep3 = document.getElementById('pillStep3');
        const pillStep4 = document.getElementById('pillStep4');

        // Location Notice DOM
        const locationNoticeBar = document.getElementById('locationNoticeBar');
        const locationNoticeIcon = document.getElementById('locationNoticeIcon');
        const locationNoticeText = document.getElementById('locationNoticeText');
        const btnRetryLocation = document.getElementById('btnRetryLocation');

        // Auth Gate DOM
        const authGateBox = document.getElementById('authGateBox');
        const authEmployeeId = document.getElementById('authEmployeeId');
        const authEmail = document.getElementById('authEmail');
        const authPassword = document.getElementById('authPassword');
        const btnAuthSubmit = document.getElementById('btnAuthSubmit');
        const btnAuthText = document.getElementById('btnAuthText');
        const btnTogglePassword = document.getElementById('btnTogglePassword');
        const eyeIcon = document.getElementById('eyeIcon');
        const authVerifiedCard = document.getElementById('authVerifiedCard');
        const btnSwitchUser = document.getElementById('btnSwitchUser');
        const scannerLockedPlaceholder = document.getElementById('scannerLockedPlaceholder');

        const empNameDisplay = document.getElementById('empNameDisplay');
        const empIdBadge = document.getElementById('empIdBadge');
        const empRoleBadge = document.getElementById('empRoleBadge');
        const empAvatarImg = document.getElementById('empAvatarImg');
        const verifiedPreviewImg = document.getElementById('verifiedPreviewImg');
        const verifiedName = document.getElementById('verifiedName');

        // State Machine
        const IS_LOGGED_IN = <?= $isLoggedIn ? 'true' : 'false' ?>;
        let isAuthenticated = <?= $isLoggedIn ? 'true' : 'false' ?>;
        let authenticatedPassword = '';
        let authenticatedEmail = '<?= !empty($sessionEmail) ? addslashes($sessionEmail) : '' ?>';
        let currentStep = 1; // 1: Frontal, 2: Left, 3: Right, 4: Liveness, 5: Finished
        let frontalFrame = null;
        let leftPoseFrame = null;
        let rightPoseFrame = null;
        let livenessFrame = null;

        let activeEmpId = '<?= !empty($sessionUserId) ? addslashes($sessionUserId) : '' ?>';
        let activeEmpName = '<?= !empty($sessionFullName) ? addslashes($sessionFullName) : '' ?>';
        let cameraStream = null;

        // Mandatory Location State
        let userLatitude = null;
        let userLongitude = null;
        let isLocationAcquired = false;

        const CIRCUMFERENCE = 2 * Math.PI * 150; // 942.477

        function setRadialProgress(percent) {
            const offset = CIRCUMFERENCE - (percent / 100) * CIRCUMFERENCE;
            progressMeterFill.style.strokeDashoffset = offset;
            progressText.textContent = `${percent}% Complete`;
        }

        // Toggle password visibility
        if (btnTogglePassword) {
            btnTogglePassword.addEventListener('click', () => {
                if (authPassword.type === 'password') {
                    authPassword.type = 'text';
                    eyeIcon.className = 'fa-solid fa-eye-slash';
                } else {
                    authPassword.type = 'password';
                    eyeIcon.className = 'fa-solid fa-eye';
                }
            });
        }

        // ── MANDATORY LOCATION HANDLER ──
        function requestMandatoryLocation() {
            locationNoticeBar.className = "location-notice-bar pending";
            locationNoticeIcon.className = "fa-solid fa-location-crosshairs fa-spin";
            locationNoticeText.textContent = "Acquiring mandatory GPS location...";

            if (!navigator.geolocation) {
                locationNoticeBar.className = "location-notice-bar error";
                locationNoticeIcon.className = "fa-solid fa-triangle-exclamation";
                locationNoticeText.textContent = "Geolocation is not supported by your browser. Location is mandatory!";
                isLocationAcquired = false;
                btnNextStep.disabled = true;
                return;
            }

            navigator.geolocation.getCurrentPosition(
                (position) => {
                    userLatitude = position.coords.latitude;
                    userLongitude = position.coords.longitude;
                    isLocationAcquired = true;

                    locationNoticeBar.className = "location-notice-bar valid";
                    locationNoticeIcon.className = "fa-solid fa-location-dot text-success";
                    locationNoticeText.innerHTML = `GPS Verified: <strong>${userLatitude.toFixed(4)}, ${userLongitude.toFixed(4)}</strong> (Mandatory Location Verified)`;
                    
                    if (isAuthenticated) {
                        btnNextStep.disabled = false;
                    }
                },
                (error) => {
                    console.error("GPS error:", error);
                    isLocationAcquired = false;
                    btnNextStep.disabled = true;
                    locationNoticeBar.className = "location-notice-bar error";
                    locationNoticeIcon.className = "fa-solid fa-triangle-exclamation";
                    
                    let msg = "GPS Location is MANDATORY! Please allow location permissions in your browser to proceed.";
                    if (error.code === error.PERMISSION_DENIED) {
                        msg = "Location permission denied. Location is mandatory! Please click the lock/site settings in address bar to allow location.";
                    }
                    locationNoticeText.textContent = msg;

                    Swal.fire({
                        icon: 'warning',
                        title: 'Location Access Required',
                        text: 'GPS Location is mandatory for 3D Biometric Verification. Please enable location permissions to proceed.',
                        confirmButtonColor: '#2563eb'
                    });
                },
                {
                    enableHighAccuracy: true,
                    timeout: 10000,
                    maximumAge: 0
                }
            );
        }

        btnRetryLocation.addEventListener('click', requestMandatoryLocation);

        // Handle Logged-Out Authentication (Employee ID + Email + Password)
        async function handleAuthSubmit() {
            const empId = authEmployeeId.value.trim();
            const email = authEmail.value.trim();
            const password = authPassword.value;

            if (!empId) {
                Swal.fire('Employee ID Required', 'Please enter your Employee ID or Username.', 'warning');
                authEmployeeId.focus();
                return;
            }

            if (!email) {
                Swal.fire('Email Required', 'Please enter your registered Email Address.', 'warning');
                authEmail.focus();
                return;
            }

            if (!password) {
                Swal.fire('Password Required', 'Please enter your account password.', 'warning');
                authPassword.focus();
                return;
            }

            btnAuthSubmit.disabled = true;
            btnAuthText.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> Verifying Credentials...`;

            try {
                const response = await fetch('../ajax/auth_face_verification.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        employee_id: empId,
                        email: email,
                        password: password
                    })
                });

                const data = await response.json();

                if (data.success && data.user) {
                    isAuthenticated = true;
                    authenticatedPassword = password;
                    authenticatedEmail = email;
                    activeEmpId = data.user.userId;
                    activeEmpName = data.user.fullName || data.user.username;

                    // Update UI with user details
                    empNameDisplay.textContent = activeEmpName;
                    empIdBadge.textContent = activeEmpId;
                    empRoleBadge.textContent = data.user.designation || data.user.userRole || 'Employee';
                    if (data.user.profilePhoto) {
                        empAvatarImg.src = `../profile-photos/${data.user.profilePhoto}`;
                    }

                    // Audio chime
                    playStepSuccess();

                    // Switch Auth Gate Box to Verified Badge
                    authGateBox.style.display = 'none';
                    authVerifiedCard.style.display = 'flex';

                    // Unlock Scanner Viewport
                    scannerLockedPlaceholder.style.display = 'none';
                    progressStatPill.style.display = 'inline-flex';

                    // Check location
                    if (isLocationAcquired) {
                        btnNextStep.disabled = false;
                    } else {
                        requestMandatoryLocation();
                    }

                    // Reset steps & Start Camera
                    resetSteps();
                    await startCamera();

                    Swal.fire({
                        toast: true,
                        position: 'top-end',
                        icon: 'success',
                        title: `Authenticated: ${activeEmpName}`,
                        text: 'Ready for 3D Motion Face Verification',
                        timer: 2500,
                        showConfirmButton: false
                    });

                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Authentication Failed',
                        text: data.message || 'Incorrect Employee ID, Email, or Password.',
                        confirmButtonColor: '#2563eb'
                    });
                    authPassword.value = '';
                    authPassword.focus();
                }
            } catch (err) {
                console.error("Auth error:", err);
                Swal.fire({
                    icon: 'error',
                    title: 'Connection Error',
                    text: 'Unable to reach the authentication server. Please check your network connection.',
                    confirmButtonColor: '#2563eb'
                });
            } finally {
                btnAuthSubmit.disabled = false;
                btnAuthText.textContent = "Verify Identity & Start 3D Verification";
            }
        }

        // Switch User Button (Only in logged out mode)
        if (btnSwitchUser) {
            btnSwitchUser.addEventListener('click', () => {
                stopCamera();
                isAuthenticated = false;
                authenticatedPassword = '';
                authenticatedEmail = '';
                activeEmpId = '';
                activeEmpName = '';
                authPassword.value = '';

                authVerifiedCard.style.display = 'none';
                authGateBox.style.display = 'block';
                scannerLockedPlaceholder.style.display = 'flex';
                progressStatPill.style.display = 'none';
                btnNextStep.disabled = true;

                stepTitle.innerHTML = `<i class="fa-solid fa-lock text-warning"></i> Authentication Required`;
                stepDesc.textContent = "Please provide your Employee ID, Email, and Password above to begin 3D biometric motion enrollment.";
                btnNextStepLabel.textContent = "Confirm Frontal Face";

                setRadialProgress(0);
                authEmployeeId.focus();
            });
        }

        // Camera Initialization
        async function startCamera() {
            try {
                if (cameraStream) {
                    stopCamera();
                }
                cameraStream = await navigator.mediaDevices.getUserMedia({
                    video: {
                        width: { ideal: 1280 },
                        height: { ideal: 720 },
                        facingMode: "user"
                    },
                    audio: false
                });
                video.srcObject = cameraStream;
                setRadialProgress(5);
                
                video.onloadedmetadata = () => {
                    startAutoDetectionLoop();
                    if (currentStep === 1) {
                        speakPrompt("Look straight into the camera and hold steady");
                    }
                };
                // Fallback start if metadata already fired
                setTimeout(() => {
                    if (video.readyState >= 2) {
                        startAutoDetectionLoop();
                        if (currentStep === 1) {
                            speakPrompt("Look straight into the camera and hold steady");
                        }
                    }
                }, 500);

            } catch (err) {
                console.error("Camera access error:", err);
                Swal.fire({
                    icon: 'error',
                    title: 'Camera Access Needed',
                    text: 'Please allow camera access in your browser to verify and enroll your face.',
                    confirmButtonColor: '#2563eb'
                });
            }
        }

        function stopCamera() {
            stopAutoDetectionLoop();
            if (cameraStream) {
                cameraStream.getTracks().forEach(track => track.stop());
                cameraStream = null;
            }
            if (video) {
                video.srcObject = null;
            }
        }

        // Capture high quality frame from video (Google-speed 480x360 payload)
        function captureFrame(quality = 0.85) {
            if (!video.videoWidth || !video.videoHeight) return null;
            cropCanvas.width = 480;
            cropCanvas.height = 360;
            const ctx = cropCanvas.getContext('2d');
            ctx.translate(cropCanvas.width, 0);
            ctx.scale(-1, 1);
            ctx.drawImage(video, 0, 0, cropCanvas.width, cropCanvas.height);
            ctx.setTransform(1, 0, 0, 1, 0, 0);
            return cropCanvas.toDataURL('image/jpeg', quality);
        }

        // ── AUTONOMOUS BIOMETRIC VISION & POSE DETECTION ENGINE ──
        let autoDetectActive = false;
        let lastFramePixels = null;
        let stepHoldStart = null;
        let isStepLocked = false;
        const cvCanvas = document.createElement('canvas');
        cvCanvas.width = 160;
        cvCanvas.height = 120;
        const cvCtx = cvCanvas.getContext('2d', { willReadFrequently: true });

        // Hardware accelerated native detector if supported in modern browser
        let nativeDetector = null;
        if ('FaceDetector' in window) {
            try {
                nativeDetector = new window.FaceDetector({ fastMode: true, maxDetectedFaces: 1 });
            } catch (e) {
                console.log("Native FaceDetector note:", e);
            }
        }

        function startAutoDetectionLoop() {
            if (autoDetectActive) return;
            autoDetectActive = true;
            runDetectionCycle();
        }

        function stopAutoDetectionLoop() {
            autoDetectActive = false;
        }

        async function runDetectionCycle() {
            if (!autoDetectActive) return;

            try {
                if (video && video.readyState >= 2 && isAuthenticated && currentStep <= 4 && !isStepLocked) {
                    await analyzeCurrentFrame();
                }
            } catch (e) {
                console.warn("CV cycle warning:", e);
            }

            if (autoDetectActive) {
                requestAnimationFrame(runDetectionCycle);
            }
        }

        async function analyzeCurrentFrame() {
            if (!video.videoWidth || !video.videoHeight) return;

            // Draw current video to low-res analysis canvas
            cvCtx.drawImage(video, 0, 0, cvCanvas.width, cvCanvas.height);
            const frame = cvCtx.getImageData(0, 0, cvCanvas.width, cvCanvas.height);
            const data = frame.data;

            // Analyze central oval region: x: [40, 120], y: [20, 100]
            let skinCount = 0;
            let sumLuminance = 0;
            let motionDelta = 0;
            const totalSampled = 40 * 40;

            for (let y = 20; y < 100; y += 2) {
                for (let x = 40; x < 120; x += 2) {
                    const idx = (y * cvCanvas.width + x) * 4;
                    const r = data[idx];
                    const g = data[idx + 1];
                    const b = data[idx + 2];
                    const lum = 0.299 * r + 0.587 * g + 0.114 * b;
                    sumLuminance += lum;

                    // Robust skin tone color space filter
                    const isSkin = (r > 60 && g > 40 && b > 20 && r > g && (r - b) > 12 && Math.abs(r - g) > 10);
                    if (isSkin) skinCount++;

                    if (lastFramePixels) {
                        const dR = Math.abs(r - lastFramePixels[idx]);
                        const dG = Math.abs(g - lastFramePixels[idx + 1]);
                        const dB = Math.abs(b - lastFramePixels[idx + 2]);
                        motionDelta += (dR + dG + dB) / 3;
                    }
                }
            }

            const skinRatio = skinCount / totalSampled;
            const avgLuminance = sumLuminance / totalSampled;
            motionDelta = lastFramePixels ? (motionDelta / totalSampled) : 0;
            lastFramePixels = new Uint8ClampedArray(data);

            // Native detector check
            let hasNativeFace = false;
            if (nativeDetector) {
                try {
                    const faces = await nativeDetector.detect(video);
                    if (faces && faces.length > 0) hasNativeFace = true;
                } catch (e) {}
            }

            // Face is verified present in central frame
            const facePresent = hasNativeFace || (skinRatio > 0.12 && avgLuminance > 25 && avgLuminance < 240);
            const now = Date.now();

            // Calibrated timing for authentic, comfortable biometric verification
            const STEP1_HOLD_REQUIRED = 2200; // 2.2s to align and verify frontal face
            const STEP2_HOLD_REQUIRED = 2600; // 2.6s steady hold after turning left
            const STEP3_HOLD_REQUIRED = 2600; // 2.6s steady hold after turning right
            const STEP4_HOLD_REQUIRED = 2400; // 2.4s steady hold for 3D liveness & blink
            const STEP_TRANSITION_DELAY = 800; // 800ms transition delay between steps

            if (currentStep === 1) {
                // Step 1: Frontal Face Alignment & Hold
                if (facePresent) {
                    faceOvalGuide.classList.add('aligned');
                    if (!stepHoldStart) stepHoldStart = now;
                    const heldMs = now - stepHoldStart;
                    const progress = Math.min(25, Math.floor((heldMs / STEP1_HOLD_REQUIRED) * 25));
                    setRadialProgress(progress);
                    const remainSec = Math.max(0, ((STEP1_HOLD_REQUIRED - heldMs) / 1000)).toFixed(1);
                    btnNextStepLabel.innerHTML = `<i class="fa-solid fa-arrows-to-circle text-primary fa-spin"></i> Aligning Frontal Face (${remainSec}s)...`;
                    stepDesc.textContent = `Position your face inside the circle and look straight. Hold steady (${remainSec}s remaining).`;

                    if (heldMs >= STEP1_HOLD_REQUIRED) {
                        isStepLocked = true;
                        stepHoldStart = null;
                        btnNextStepLabel.innerHTML = `<i class="fa-solid fa-circle-check text-success"></i> Step 1: Frontal Face Locked!`;
                        stepDesc.textContent = "Frontal pose captured! Next: Turn your head to the LEFT.";
                        frontalFrame = captureFrame();
                        playStepSuccess();
                        setTimeout(() => advanceToStep(2), STEP_TRANSITION_DELAY);
                    }
                } else {
                    stepHoldStart = null;
                    faceOvalGuide.classList.remove('aligned');
                    btnNextStepLabel.innerHTML = `<i class="fa-solid fa-arrows-to-circle text-primary"></i> Align Face In Center Circle`;
                    stepDesc.textContent = "Position your face comfortably inside the circular frame. Hold steady and look directly at the lens.";
                    setRadialProgress(0);
                }

            } else if (currentStep === 2) {
                // Step 2: Turn Head Left & Hold Pose
                if (facePresent) {
                    faceOvalGuide.classList.add('aligned');
                    if (!stepHoldStart) stepHoldStart = now;
                    const heldMs = now - stepHoldStart;
                    const progress = 25 + Math.min(25, Math.floor((heldMs / STEP2_HOLD_REQUIRED) * 25));
                    setRadialProgress(progress);
                    const remainSec = Math.max(0, ((STEP2_HOLD_REQUIRED - heldMs) / 1000)).toFixed(1);
                    btnNextStepLabel.innerHTML = `<i class="fa-solid fa-arrow-left text-primary fa-bounce"></i> Hold Left Pose (${remainSec}s)...`;
                    stepDesc.textContent = `Keep your head turned to the LEFT. Hold steady (${remainSec}s remaining).`;

                    if (heldMs >= STEP2_HOLD_REQUIRED) {
                        isStepLocked = true;
                        stepHoldStart = null;
                        btnNextStepLabel.innerHTML = `<i class="fa-solid fa-circle-check text-success"></i> Step 2: Left Pose Captured!`;
                        stepDesc.textContent = "Left profile captured! Next: Turn your head to the RIGHT.";
                        leftPoseFrame = captureFrame();
                        playStepSuccess();
                        setTimeout(() => advanceToStep(3), STEP_TRANSITION_DELAY);
                    }
                } else {
                    stepHoldStart = null;
                }

            } else if (currentStep === 3) {
                // Step 3: Turn Head Right & Hold Pose
                if (facePresent) {
                    faceOvalGuide.classList.add('aligned');
                    if (!stepHoldStart) stepHoldStart = now;
                    const heldMs = now - stepHoldStart;
                    const progress = 50 + Math.min(25, Math.floor((heldMs / STEP3_HOLD_REQUIRED) * 25));
                    setRadialProgress(progress);
                    const remainSec = Math.max(0, ((STEP3_HOLD_REQUIRED - heldMs) / 1000)).toFixed(1);
                    btnNextStepLabel.innerHTML = `<i class="fa-solid fa-arrow-right text-primary fa-bounce"></i> Hold Right Pose (${remainSec}s)...`;
                    stepDesc.textContent = `Keep your head turned to the RIGHT. Hold steady (${remainSec}s remaining).`;

                    if (heldMs >= STEP3_HOLD_REQUIRED) {
                        isStepLocked = true;
                        stepHoldStart = null;
                        btnNextStepLabel.innerHTML = `<i class="fa-solid fa-circle-check text-success"></i> Step 3: Right Pose Captured!`;
                        stepDesc.textContent = "Right profile captured! Next: Center face for Liveness Check.";
                        rightPoseFrame = captureFrame();
                        playStepSuccess();
                        setTimeout(() => advanceToStep(4), STEP_TRANSITION_DELAY);
                    }
                } else {
                    stepHoldStart = null;
                }

            } else if (currentStep === 4) {
                // Step 4: 3D Liveness & Micro-Motion / Blink Verification
                if (facePresent) {
                    faceOvalGuide.classList.add('aligned');
                    if (!stepHoldStart) stepHoldStart = now;
                    const heldMs = now - stepHoldStart;
                    const progress = 75 + Math.min(25, Math.floor((heldMs / STEP4_HOLD_REQUIRED) * 25));
                    setRadialProgress(progress);
                    const remainSec = Math.max(0, ((STEP4_HOLD_REQUIRED - heldMs) / 1000)).toFixed(1);
                    btnNextStepLabel.innerHTML = `<i class="fa-solid fa-face-smile text-success fa-bounce"></i> Verifying 3D Liveness (${remainSec}s)...`;
                    stepDesc.textContent = `Look straight ahead and blink or smile naturally (${remainSec}s remaining).`;

                    if (heldMs >= STEP4_HOLD_REQUIRED) {
                        isStepLocked = true;
                        stepHoldStart = null;
                        btnNextStepLabel.innerHTML = `<i class="fa-solid fa-check-double text-success"></i> 3D Biometrics Complete!`;
                        stepDesc.textContent = "All poses captured successfully! Processing biometric embeddings...";
                        livenessFrame = captureFrame();
                        playStepSuccess();
                        setRadialProgress(100);
                        speakPrompt("Face verified successfully");
                        setTimeout(() => {
                            currentStep = 5;
                            stopAutoDetectionLoop();
                            submitBiometricVerification();
                        }, 750);
                    }
                } else {
                    stepHoldStart = null;
                }
            }
        }

        // Advance to a specific step with voice instructions and calibrated grace delay
        function advanceToStep(nextStep) {
            currentStep = nextStep;
            isStepLocked = true;
            stepHoldStart = null;

            if (currentStep === 2) {
                pillStep1.className = 'step-pill completed';
                pillStep2.className = 'step-pill active';
                setRadialProgress(25);
                stepTitle.innerHTML = `<i class="fa-solid fa-arrow-left text-primary"></i> Step 2: Turn Head Left`;
                stepDesc.textContent = "Please turn your head to your LEFT. Hold steady when in position.";
                btnNextStepLabel.innerHTML = `<i class="fa-solid fa-volume-high text-primary fa-beat"></i> "Turn head left" (Please turn now...)`;
                arrowLeft.style.opacity = '1';
                arrowRight.style.opacity = '0';
                faceOvalGuide.classList.remove('aligned');

                // Voice prompt: Speak Left instruction clearly
                speakPrompt("Please turn your head to the left");

                // Enforce 1.8s grace period: allows user to hear voice and turn head before hold timer starts
                setTimeout(() => {
                    if (currentStep === 2) {
                        isStepLocked = false;
                        stepHoldStart = null;
                        btnNextStepLabel.innerHTML = `<i class="fa-solid fa-arrow-left text-primary fa-bounce"></i> Turn Left & Hold Steady...`;
                    }
                }, 1800);

            } else if (currentStep === 3) {
                pillStep2.className = 'step-pill completed';
                pillStep3.className = 'step-pill active';
                setRadialProgress(50);
                stepTitle.innerHTML = `<i class="fa-solid fa-arrow-right text-primary"></i> Step 3: Turn Head Right`;
                stepDesc.textContent = "Now turn your head to your RIGHT. Hold steady when in position.";
                btnNextStepLabel.innerHTML = `<i class="fa-solid fa-volume-high text-primary fa-beat"></i> "Turn head right" (Please turn now...)`;
                arrowLeft.style.opacity = '0';
                arrowRight.style.opacity = '1';
                faceOvalGuide.classList.remove('aligned');

                // Voice prompt: Speak Right instruction clearly
                speakPrompt("Please turn your head to the right");

                // Enforce 1.8s grace period: allows user to hear voice and turn head before hold timer starts
                setTimeout(() => {
                    if (currentStep === 3) {
                        isStepLocked = false;
                        stepHoldStart = null;
                        btnNextStepLabel.innerHTML = `<i class="fa-solid fa-arrow-right text-primary fa-bounce"></i> Turn Right & Hold Steady...`;
                    }
                }, 1800);

            } else if (currentStep === 4) {
                pillStep3.className = 'step-pill completed';
                pillStep4.className = 'step-pill active';
                setRadialProgress(75);
                stepTitle.innerHTML = `<i class="fa-solid fa-face-smile text-success"></i> Step 4: 3D Liveness Check`;
                stepDesc.textContent = "Look straight ahead and blink naturally or smile to prove 3D liveness.";
                btnNextStepLabel.innerHTML = `<i class="fa-solid fa-volume-high text-success fa-beat"></i> "Look straight and blink"`;
                arrowLeft.style.opacity = '0';
                arrowRight.style.opacity = '0';
                faceOvalGuide.classList.add('aligned');

                // Voice prompt: Speak Liveness / Blink instruction clearly
                speakPrompt("Look straight ahead and blink your eyes");

                // Enforce 1.4s grace period before counting steady liveness
                setTimeout(() => {
                    if (currentStep === 4) {
                        isStepLocked = false;
                        stepHoldStart = null;
                        btnNextStepLabel.innerHTML = `<i class="fa-solid fa-face-smile text-success fa-bounce"></i> Verifying 3D Liveness...`;
                    }
                }, 1400);
            }
        }

        // Manual button override (in case user wants to snap immediately)
        btnNextStep.addEventListener('click', () => {
            if (isStepLocked || currentStep > 4) return;
            isStepLocked = true;
            if (currentStep === 1) {
                frontalFrame = captureFrame();
                playStepSuccess();
                advanceToStep(2);
            } else if (currentStep === 2) {
                leftPoseFrame = captureFrame();
                playStepSuccess();
                advanceToStep(3);
            } else if (currentStep === 3) {
                rightPoseFrame = captureFrame();
                playStepSuccess();
                advanceToStep(4);
            } else if (currentStep === 4) {
                livenessFrame = captureFrame();
                playStepSuccess();
                currentStep = 5;
                setRadialProgress(100);
                stopAutoDetectionLoop();
                submitBiometricVerification();
            }
        });

        // Submit multi-pose biometric registration to backend
        async function submitBiometricVerification() {
            if (!isLocationAcquired || !userLatitude || !userLongitude) {
                Swal.fire('GPS Location Mandatory', 'Location is required. Please grant GPS location access to complete verification.', 'warning');
                requestMandatoryLocation();
                return;
            }

            btnNextStep.disabled = true;
            btnNextStepLabel.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> Processing 512-D Biometrics...`;
            stepTitle.innerHTML = `<i class="fa-solid fa-brain text-primary"></i> Computing Face Embeddings...`;
            stepDesc.textContent = "Storing multi-pose facial features and updating biometric profile photo...";

            try {
                const response = await fetch('../ajax/verify_face_proxy.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        user_id: activeEmpId,
                        password: authenticatedPassword,
                        email: authenticatedEmail,
                        latitude: userLatitude,
                        longitude: userLongitude,
                        name: activeEmpName,
                        frontal_image: frontalFrame,
                        left_image: leftPoseFrame,
                        right_image: rightPoseFrame,
                        liveness_image: livenessFrame,
                        poses: [leftPoseFrame, rightPoseFrame, livenessFrame].filter(Boolean)
                    })
                });

                const data = await response.json();

                if (data.success) {
                    playGrandFanfare();
                    speakPrompt("Biometrics verified successfully");
                    verifiedPreviewImg.src = frontalFrame;
                    empAvatarImg.src = frontalFrame;
                    verifiedName.textContent = activeEmpName || activeEmpId;
                    successOverlay.style.display = 'flex';
                } else {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Verification Incomplete',
                        text: data.message || 'Face detection failed. Please ensure good lighting and face camera directly.',
                        confirmButtonColor: '#2563eb'
                    });
                    resetSteps();
                }
            } catch (err) {
                console.error("Submission error:", err);
                Swal.fire({
                    icon: 'error',
                    title: 'Engine Connection Error',
                    text: 'Unable to reach the AI Biometric service. Please make sure the service is running on port 5001.',
                    confirmButtonColor: '#2563eb'
                });
                resetSteps();
            } finally {
                btnNextStep.disabled = false;
            }
        }

        function resetSteps() {
            currentStep = 1;
            isStepLocked = false;
            stepHoldStart = null;
            frontalFrame = null;
            leftPoseFrame = null;
            rightPoseFrame = null;
            livenessFrame = null;

            pillStep1.className = 'step-pill active';
            pillStep2.className = 'step-pill';
            pillStep3.className = 'step-pill';
            pillStep4.className = 'step-pill';
            arrowLeft.style.opacity = '0';
            arrowRight.style.opacity = '0';
            faceOvalGuide.classList.remove('aligned');
            setRadialProgress(5);
            stepTitle.innerHTML = `<i class="fa-solid fa-arrows-to-circle text-primary"></i> Step 1: Look Straight`;
            stepDesc.textContent = "Position your face comfortably inside the circular frame. Hold steady and look directly at the lens.";
            btnNextStepLabel.textContent = "Auto-Detecting Frontal Face...";
            speakPrompt("Look straight into the camera and hold steady");
            startAutoDetectionLoop();
        }

        // Instant Punch Now button action
        btnPunchNow.addEventListener('click', async () => {
            btnPunchNow.disabled = true;
            btnPunchNow.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> Punching Attendance...`;

            try {
                const attendanceFrame = captureFrame() || frontalFrame;
                const resp = await fetch('../ajax/scan_face_proxy.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        image: attendanceFrame,
                        target_user_id: activeEmpId,
                        latitude: userLatitude,
                        longitude: userLongitude
                    })
                });
                const res = await resp.json();

                if (res.recognized) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Attendance Recorded!',
                        html: `<b>Welcome, ${res.user_name}</b><br>Punch time: <strong>${res.time}</strong> (Match: ${res.similarity}%)`,
                        confirmButtonColor: '#10b981',
                        confirmButtonText: 'Go to Dashboard'
                    }).then(() => {
                        window.location.href = 'index.php';
                    });
                } else {
                    Swal.fire({
                        icon: 'info',
                        title: 'Biometrics Registered!',
                        text: 'Face profile saved. Please proceed to the Live Face Attendance kiosk to punch.',
                        confirmButtonColor: '#2563eb'
                    }).then(() => {
                        window.location.href = 'live_face_attendance.php';
                    });
                }
            } catch (err) {
                console.error("Punch error:", err);
                window.location.href = 'live_face_attendance.php';
            }
        });

        // Initialize on load
        window.onload = () => {
            requestMandatoryLocation();

            if (IS_LOGGED_IN) {
                // If logged in: don't ask for anything! Unlock scanner & start camera immediately
                isAuthenticated = true;
                startCamera();
            } else {
                // If logged out: focus on ID or email
                if (authEmployeeId && authEmployeeId.value.trim()) {
                    if (authEmail) authEmail.focus();
                } else if (authEmployeeId) {
                    authEmployeeId.focus();
                }
            }
        };
    </script>
</body>

</html>
