<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
if (empty($_SESSION['userId'])) {
    header("Location: login.php");
    exit;
}
require_once(__DIR__ . '/../include/db_config.php');

$userId = $_SESSION['userId'] ?? '';
$punchType = strtolower($_GET['punch'] ?? 'in');
$isTour = isset($_GET['tour']) && ($_GET['tour'] == '1' || strtolower($_GET['tour']) === 'true');

// Check user details & work mode
$uQ = mysqli_query($con, "SELECT fullName, designation, userRole, type, mode FROM signup WHERE userId='$userId' LIMIT 1");
$userData = $uQ ? mysqli_fetch_assoc($uQ) : null;
$fullName = $userData['fullName'] ?? 'Employee';
$workMode = !empty($userData['type']) ? $userData['type'] : (!empty($userData['mode']) ? $userData['mode'] : 'Offline');

// ── ATTENDANCE RULES ──
// 1. Offline Intern/Employee -> must scan QR code first, then capture selfie
// 2. Online Intern/Employee -> capture selfie only
$qrVerified = isset($_GET['qr_verified']) && ($_GET['qr_verified'] === '1' || $_GET['qr_verified'] === 1 || $_GET['qr_verified'] === 'true');

if (strcasecmp(trim($workMode), 'Offline') === 0 && !$qrVerified) {
    // Offline users must scan QR code first!
    $p = urlencode($_GET['punch'] ?? 'In');
    $extra = '';
    if (isset($_GET['earlyReason'])) $extra .= '&earlyReason=' . urlencode($_GET['earlyReason']);
    if (isset($_GET['updatedToSenior'])) $extra .= '&updatedToSenior=' . urlencode($_GET['updatedToSenior']);
    header("Location: qrCodeScanner_copy.php?punch={$p}{$extra}");
    exit;
}

$passedLat = (isset($_GET['lat']) && is_numeric($_GET['lat']) && floatval($_GET['lat']) != 0.0) ? floatval($_GET['lat']) : null;
$passedLng = (isset($_GET['lng']) && is_numeric($_GET['lng']) && floatval($_GET['lng']) != 0.0) ? floatval($_GET['lng']) : null;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php include('../partials/_links.php'); ?>
    <meta charset="UTF-8">
    <title>AI Face Attendance - SETTribe AMS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background-color: #f4f6fb;
            margin: 0;
        }

        .main-content {
            display: flex;
            justify-content: center;
            align-items: flex-start;
            width: 100%;
            padding: 30px 16px;
            min-height: calc(100vh - 80px);
        }

        .ai-attendance-card {
            background: #fff;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(25, 119, 225, 0.08), 0 2px 8px rgba(0, 0, 0, 0.04);
            max-width: 520px;
            width: 100%;
            overflow: hidden;
            border: 1px solid #e2e8f0;
        }

        .ai-card-header {
            background: linear-gradient(135deg, #1977e1 0%, #1254b0 100%);
            padding: 20px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            color: #fff;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .header-icon {
            width: 44px;
            height: 44px;
            background: rgba(255, 255, 255, 0.18);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .header-text h2 {
            font-size: 17px;
            font-weight: 700;
            margin: 0 0 2px;
            color: #fff;
        }

        .header-text p {
            font-size: 12px;
            color: rgba(255, 255, 255, 0.82);
            margin: 0;
        }

        .punch-mode-badge {
            background: rgba(255, 255, 255, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: #fff;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.3px;
        }

        .ai-card-body {
            padding: 24px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        /* Camera Box */
        .camera-wrapper {
            position: relative;
            width: 100%;
            height: 340px;
            border-radius: 16px;
            overflow: hidden;
            background: #0f172a;
            border: 2px solid #e2e8f0;
            box-shadow: inset 0 0 20px rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        #video {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transform: scaleX(-1);
        }

        /* Laser Scanning Beam */
        .scan-laser {
            position: absolute;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, transparent, #38bdf8, #3b82f6, #38bdf8, transparent);
            box-shadow: 0 0 12px 2px #38bdf8;
            top: 0;
            animation: scanLine 2.2s ease-in-out infinite alternate;
            pointer-events: none;
            z-index: 5;
        }

        @keyframes scanLine {
            0% { top: 6%; }
            100% { top: 92%; }
        }

        /* Face Frame Reticle */
        .face-bracket {
            position: absolute;
            width: 200px;
            height: 240px;
            border: 2px dashed rgba(56, 189, 248, 0.45);
            border-radius: 18px;
            pointer-events: none;
            z-index: 6;
            transition: all 0.3s;
        }

        .face-bracket.active {
            border-color: #22c55e;
            box-shadow: 0 0 20px rgba(34, 197, 94, 0.4);
        }

        /* Real-Time Status Pill */
        .status-pill-box {
            margin-top: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 18px;
            border-radius: 12px;
            background: #f8fafc;
            border: 1.5px solid #e2e8f0;
            width: 100%;
            justify-content: center;
            transition: all 0.3s;
        }

        .status-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #3b82f6;
            animation: pulseDot 1.4s infinite;
        }

        .status-dot.success {
            background: #22c55e;
            box-shadow: 0 0 10px #22c55e;
        }

        .status-dot.warning {
            background: #f59e0b;
        }

        @keyframes pulseDot {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.3); opacity: 0.6; }
        }

        .status-text {
            font-size: 13.5px;
            font-weight: 600;
            color: #1e293b;
        }

        /* Match Info Badge */
        .match-info-strip {
            margin-top: 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            font-size: 12px;
            color: #64748b;
            padding: 6px 4px;
        }

        .match-info-strip span {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        /* Success Card Overlay */
        .success-overlay {
            position: absolute;
            inset: 0;
            background: rgba(15, 23, 42, 0.92);
            backdrop-filter: blur(6px);
            display: none;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 20;
            padding: 20px;
            text-align: center;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
        }

        .success-check-icon {
            width: 60px;
            height: 60px;
            background: #10b981;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 28px;
            margin-bottom: 12px;
            box-shadow: 0 0 25px rgba(16, 185, 129, 0.5);
        }

        .success-overlay h3 {
            font-size: 19px;
            color: #fff;
            font-weight: 700;
            margin: 0 0 4px;
        }

        .success-overlay p {
            font-size: 13px;
            color: #94a3b8;
            margin: 0 0 14px;
        }

        .success-details {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.12);
            padding: 8px 16px;
            border-radius: 10px;
            color: #38bdf8;
            font-weight: 600;
            font-size: 13px;
        }

        /* Retry / Mismatch Overlay */
        .retry-overlay {
            position: absolute;
            inset: 0;
            background: rgba(15, 23, 42, 0.94);
            backdrop-filter: blur(8px);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 24px;
            z-index: 20;
            animation: fadeIn 0.3s ease;
        }

        .retry-icon {
            width: 58px;
            height: 58px;
            background: #ef4444;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 26px;
            margin-bottom: 12px;
            box-shadow: 0 0 25px rgba(239, 68, 68, 0.5);
            animation: pulse-retry 2s infinite;
        }

        @keyframes pulse-retry {
            0% { transform: scale(1); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.6); }
            70% { transform: scale(1.04); box-shadow: 0 0 0 12px rgba(239, 68, 68, 0); }
            100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
        }

        .retry-overlay h3 {
            font-size: 19px;
            color: #fff;
            font-weight: 700;
            margin: 0 0 6px;
        }

        .retry-overlay p {
            font-size: 13px;
            color: #cbd5e1;
            margin: 0 0 14px;
            max-width: 320px;
            line-height: 1.4;
        }

        .retry-tips-box {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            padding: 10px 14px;
            margin-bottom: 18px;
            text-align: left;
            font-size: 12px;
            color: #94a3b8;
            max-width: 320px;
        }

        .retry-tips-box div {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 6px;
        }

        .retry-tips-box div:last-child {
            margin-bottom: 0;
        }

        .retry-tips-box i {
            color: #f59e0b;
        }

        .retry-btn-group {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            justify-content: center;
        }

        .btn-retry-scan {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: #fff;
            border: none;
            padding: 10px 20px;
            font-size: 13px;
            font-weight: 600;
            border-radius: 8px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.4);
        }

        .btn-retry-scan:hover {
            background: linear-gradient(135deg, #1d4ed8, #1e40af);
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(37, 99, 235, 0.6);
        }

        .btn-update-photo {
            background: rgba(255, 255, 255, 0.08);
            color: #e2e8f0;
            border: 1px solid rgba(255, 255, 255, 0.18);
            padding: 10px 16px;
            font-size: 13px;
            font-weight: 500;
            border-radius: 8px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }

        .btn-update-photo:hover {
            background: rgba(255, 255, 255, 0.16);
            color: #fff;
            text-decoration: none;
        }

        #hiddenCanvas {
            display: none;
        }
    </style>
</head>

<body>
    <div class="container-scroller">
        <?php include('../partials/_navbar.php'); ?>
        <div class="container-fluid page-body-wrapper">
            <?php include('../partials/_sidebar.php'); ?>

            <div class="main-content">
                <div class="ai-attendance-card">
                    
                    <!-- Header -->
                    <div class="ai-card-header">
                        <div class="header-left">
                            <div class="header-icon">
                                <i class="fa-solid fa-camera-retro"></i>
                            </div>
                            <div class="header-text">
                                <h2>AI Face Attendance</h2>
                                <p>Automated real-time face verification</p>
                            </div>
                        </div>
                        <span class="punch-mode-badge" id="punchModeBadge">
                            <?= ucfirst($punchType) ?> Mode
                        </span>
                    </div>

                    <!-- Body -->
                    <div class="ai-card-body">

                        <!-- Mode Rule Indicator -->
                        <?php if (strcasecmp(trim($workMode), 'Offline') === 0): ?>
                        <div class="d-flex align-items-center justify-content-between mb-3 w-100 flex-wrap gap-2">
                            <span class="badge badge-success px-3 py-2" style="font-size:0.8rem; border-radius:20px;">
                                <i class="fa-solid fa-qrcode me-1"></i> Offline Mode: QR Verified
                            </span>
                            <span class="badge badge-info px-3 py-2" style="font-size:0.8rem; border-radius:20px;">
                                Step 2 of 2: Selfie Capture
                            </span>
                        </div>
                        <?php else: ?>
                        <div class="d-flex align-items-center justify-content-between mb-3 w-100 flex-wrap gap-2">
                            <span class="badge badge-primary px-3 py-2" style="font-size:0.8rem; border-radius:20px;">
                                <i class="fa-solid fa-laptop-house me-1"></i> Online Mode: Direct Selfie
                            </span>
                            <span class="badge badge-info px-3 py-2" style="font-size:0.8rem; border-radius:20px;">
                                Single Step Verification
                            </span>
                        </div>
                        <?php endif; ?>

                        <!-- Mandatory GPS Location Notice Banner -->
                        <div id="locationNotice" class="alert alert-info py-2 px-3 mb-3 d-flex align-items-center justify-content-between" style="border-radius:12px; font-size:0.83rem; width:100%;">
                            <span><i class="fa-solid fa-location-crosshairs fa-spin me-2 text-primary"></i> <strong>Mandatory Location:</strong> <span id="locCoordsText">Acquiring GPS fix...</span></span>
                            <span id="locStatusBadge" class="badge bg-warning text-dark">Checking</span>
                        </div>

                        <div class="camera-wrapper">
                            <video id="video" autoplay playsinline></video>
                            <div class="scan-laser"></div>
                            <div class="face-bracket" id="faceBracket"></div>

                            <!-- Success Overlay -->
                            <div class="success-overlay" id="successOverlay">
                                <div class="success-check-icon">
                                    <i class="fa-solid fa-check"></i>
                                </div>
                                <h3 id="successTitle">Punch In Recorded!</h3>
                                <p id="successSubtitle">Verified via Facial Symmetry & Landmarks</p>
                                <div class="success-details" id="successDetails">
                                    Time: 10:00 AM
                                </div>
                                <div style="color:#94a3b8; font-size:12px; margin-top:12px;">
                                    Redirecting to dashboard in <span id="cdText" style="color:#fff; font-weight:700;">2</span>s...
                                </div>
                            </div>

                            <!-- Retry / Mismatch Overlay -->
                            <div class="retry-overlay" id="retryOverlay" style="display: none;">
                                <div class="retry-icon">
                                    <i class="fa-solid fa-triangle-exclamation"></i>
                                </div>
                                <h3 id="retryTitle">Face Match Failed</h3>
                                <p id="retrySubtitle">Your face could not be verified against your registered profile photo.</p>
                                <div class="retry-tips-box">
                                    <div><i class="fa-solid fa-lightbulb"></i> Look directly at camera with proper lighting</div>
                                    <div><i class="fa-solid fa-glasses"></i> Remove sunglasses, caps or face masks</div>
                                    <div><i class="fa-solid fa-id-badge"></i> Ensure your profile photo has a clear front face</div>
                                </div>
                                <div class="retry-btn-group">
                                    <button type="button" class="btn-retry-scan" id="btnRetryScan" onclick="retryScanning()">
                                        <i class="fa-solid fa-rotate-right"></i> Retry Face Scan
                                    </button>
                                    <a href="editProfile.php" class="btn-update-photo">
                                        <i class="fa-solid fa-user-pen"></i> Update Profile Photo
                                    </a>
                                </div>
                            </div>
                        </div>

                        <!-- Real-time Status -->
                        <div class="status-pill-box" id="statusPillBox">
                            <div class="status-dot" id="statusDot"></div>
                            <span class="status-text" id="statusText">Looking for registered face...</span>
                        </div>

                        <!-- Info Strip -->
                        <div class="match-info-strip">
                            <span><i class="fa-solid fa-shield-halved text-primary"></i> Biometric Verified</span>
                            <span id="matchScoreText"><i class="fa-solid fa-brain text-info"></i> Facenet Model Active</span>
                            <span><i class="fa-solid fa-bolt text-warning"></i> Auto Punch</span>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>

    <canvas id="hiddenCanvas"></canvas>

    <script>
        const AI_SERVICE_URL = '../ajax/scan_face_proxy.php';
        const video = document.getElementById('video');
        const hiddenCanvas = document.getElementById('hiddenCanvas');
        const statusText = document.getElementById('statusText');
        const statusDot = document.getElementById('statusDot');
        const statusPillBox = document.getElementById('statusPillBox');
        const faceBracket = document.getElementById('faceBracket');
        const matchScoreText = document.getElementById('matchScoreText');
        const successOverlay = document.getElementById('successOverlay');
        const successTitle = document.getElementById('successTitle');
        const successSubtitle = document.getElementById('successSubtitle');
        const successDetails = document.getElementById('successDetails');
        const cdText = document.getElementById('cdText');

        const retryOverlay = document.getElementById('retryOverlay');
        const retryTitle = document.getElementById('retryTitle');
        const retrySubtitle = document.getElementById('retrySubtitle');

        let isScanning = true;
        let scanInterval = null;
        let consecutiveMismatches = 0;
        let consecutiveMatches = 0;
        const MAX_MISMATCH_ATTEMPTS = 35; // ~10 - 12 seconds of continuous scanning before prompting

        // ── MANDATORY GPS LOCATION HANDLING ──
        let userLat = <?= ($passedLat !== null) ? $passedLat : 'null' ?>;
        let userLng = <?= ($passedLng !== null) ? $passedLng : 'null' ?>;
        let locationAcquired = (userLat !== null && userLng !== null);

        function updateLocationUI(success, msg) {
            const coordsText = document.getElementById('locCoordsText');
            const statusBadge = document.getElementById('locStatusBadge');
            const locNotice = document.getElementById('locationNotice');
            if (!coordsText || !statusBadge || !locNotice) return;

            if (success && userLat && userLng) {
                locNotice.className = "alert alert-success py-2 px-3 mb-3 d-flex align-items-center justify-content-between";
                coordsText.innerHTML = `GPS Verified: <strong>${userLat.toFixed(4)}, ${userLng.toFixed(4)}</strong>`;
                statusBadge.className = "badge bg-success text-white";
                statusBadge.textContent = "Verified";
            } else {
                locNotice.className = "alert alert-danger py-2 px-3 mb-3 d-flex align-items-center justify-content-between";
                coordsText.innerHTML = `<strong class="text-danger">${msg || 'GPS Location is MANDATORY!'}</strong>`;
                statusBadge.className = "badge bg-danger text-white";
                statusBadge.textContent = "Required";
            }
        }

        function requestMandatoryLocation() {
            return new Promise((resolve) => {
                if (locationAcquired && userLat && userLng) {
                    updateLocationUI(true);
                    resolve(true);
                    return;
                }
                if (!navigator.geolocation) {
                    updateLocationUI(false, "Geolocation not supported by browser. Location is mandatory!");
                    Swal.fire({
                        icon: 'error',
                        title: 'GPS Required',
                        text: 'Location services are strictly mandatory for recording attendance.',
                        confirmButtonColor: '#2563eb'
                    });
                    resolve(false);
                    return;
                }

                navigator.geolocation.getCurrentPosition(
                    (pos) => {
                        userLat = pos.coords.latitude;
                        userLng = pos.coords.longitude;
                        locationAcquired = true;
                        updateLocationUI(true);
                        resolve(true);
                    },
                    (err) => {
                        console.warn("Location error:", err);
                        locationAcquired = false;
                        updateLocationUI(false, "GPS location permission denied! Enable in browser.");
                        Swal.fire({
                            icon: 'warning',
                            title: 'Location is Mandatory',
                            text: 'GPS Location is strictly mandatory to record attendance. Please allow location access to continue.',
                            confirmButtonText: 'Enable & Retry',
                            confirmButtonColor: '#2563eb'
                        }).then(() => {
                            requestMandatoryLocation().then(res => {
                                if (res) startCamera();
                            });
                        });
                        resolve(false);
                    },
                    { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
                );
            });
        }

        // Sound chime generator using Web Audio API
        function playChime(isSuccess = true) {
            try {
                const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                const osc = audioCtx.createOscillator();
                const gain = audioCtx.createGain();
                osc.connect(gain);
                gain.connect(audioCtx.destination);

                if (isSuccess) {
                    osc.frequency.setValueAtTime(880, audioCtx.currentTime); // A5
                    osc.frequency.setValueAtTime(1318.51, audioCtx.currentTime + 0.12); // E6
                    gain.gain.setValueAtTime(0.18, audioCtx.currentTime);
                    gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + 0.45);
                    osc.start();
                    osc.stop(audioCtx.currentTime + 0.45);
                } else {
                    osc.frequency.setValueAtTime(320, audioCtx.currentTime);
                    gain.gain.setValueAtTime(0.2, audioCtx.currentTime);
                    gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + 0.3);
                    osc.start();
                    osc.stop(audioCtx.currentTime + 0.3);
                }
            } catch (e) {
                console.log("Audio not supported:", e);
            }
        }

        // Initialize Camera
        async function startCamera() {
            try {
                const stream = await navigator.mediaDevices.getUserMedia({
                    video: {
                        width: { ideal: 1280 },
                        height: { ideal: 720 },
                        facingMode: "user"
                    }
                });
                video.srcObject = stream;
                statusText.textContent = "Looking for registered face...";
                statusDot.className = "status-dot";
                
                video.onloadedmetadata = () => {
                    startScanningLoop();
                };
            } catch (err) {
                console.error("Camera access error:", err);
                statusText.textContent = "Camera access denied or unavailable!";
                statusDot.className = "status-dot warning";
                statusPillBox.style.borderColor = "#fca5a5";
                statusPillBox.style.background = "#fef2f2";
            }
        }

        // Grab current frame (high-speed 480x360 payload)
        function captureFrameBase64() {
            if (!video.videoWidth || !video.videoHeight) return null;
            hiddenCanvas.width = 480;
            hiddenCanvas.height = 360;
            const ctx = hiddenCanvas.getContext('2d');
            
            // Mirror frame
            ctx.translate(hiddenCanvas.width, 0);
            ctx.scale(-1, 1);
            ctx.drawImage(video, 0, 0, hiddenCanvas.width, hiddenCanvas.height);
            ctx.setTransform(1, 0, 0, 1, 0, 0);
            
            return hiddenCanvas.toDataURL('image/jpeg', 0.72);
        }

        // Non-overlapping sequential scanning loop
        let isProcessing = false;
        async function processFrame() {
            if (!isScanning) return;
            if (isProcessing) return;

            // Mandatory location check
            if (!locationAcquired || !userLat || !userLng) {
                statusText.textContent = "GPS Location is required to process attendance!";
                statusDot.className = "status-dot warning";
                requestMandatoryLocation().then(ok => {
                    if (ok && isScanning) setTimeout(processFrame, 200);
                });
                return;
            }

            const frameData = captureFrameBase64();
            if (!frameData) {
                if (isScanning) setTimeout(processFrame, 200);
                return;
            }

            isProcessing = true;
            try {
                const response = await fetch(AI_SERVICE_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        image: frameData,
                        target_user_id: '<?= $userId ?>',
                        latitude: userLat,
                        longitude: userLng,
                        punch: '<?= $punchType ?>'
                    })
                });

                const data = await response.json();

                if (!isScanning) return;

                if (data.detected && !data.recognized) {
                    consecutiveMismatches++;
                    const sim = parseFloat(data.similarity) || 0;

                    if (consecutiveMismatches >= MAX_MISMATCH_ATTEMPTS) {
                        showRetryPrompt(data);
                        return;
                    }

                    let msg = data.message;
                    if (!msg || msg.includes('does not match')) {
                        if (sim < 20) {
                            msg = "Scanning face... Please look directly at the camera";
                        } else if (sim < 35) {
                            msg = "Position your face inside the bracket & hold steady";
                        } else {
                            msg = `Verifying identity... Please hold still (${sim}%)`;
                        }
                    }
                    statusText.textContent = msg;
                    statusDot.className = "status-dot warning";
                    faceBracket.classList.remove('active');
                    consecutiveMatches = 0;
                    matchScoreText.innerHTML = `<i class="fa-solid fa-chart-line text-warning"></i> Sim: ${sim}%`;
                } else if (!data.detected) {
                    consecutiveMismatches = 0;
                    consecutiveMatches = 0;
                    statusText.textContent = "Position your face inside the bracket";
                    statusDot.className = "status-dot";
                    faceBracket.classList.remove('active');
                } else if (data.recognized) {
                    // RECOGNIZED!
                    consecutiveMismatches = 0;
                    if (data.cooldown) {
                        consecutiveMatches = 0;
                        statusText.textContent = data.message;
                        statusDot.className = "status-dot warning";
                    } else {
                        consecutiveMatches++;
                        faceBracket.classList.add('active');
                        statusText.textContent = `Face Matched! Hold steady... (${data.similarity}%)`;
                        statusDot.className = "status-dot success";
                        if (consecutiveMatches >= 2) {
                            handleSuccess(data);
                            return;
                        }
                    }
                }
            } catch (err) {
                statusText.textContent = "Connecting to Face Verification Service...";
            } finally {
                isProcessing = false;
                if (isScanning) {
                    setTimeout(processFrame, 120);
                }
            }
        }

        function showRetryPrompt(data) {
            isScanning = false;
            faceBracket.classList.remove('active');
            statusDot.className = "status-dot warning";
            statusPillBox.style.borderColor = "#fca5a5";
            statusPillBox.style.background = "#fef2f2";
            statusText.textContent = "Face match failed. Click 'Retry Face Scan' to try again.";
            
            const sim = parseFloat(data.similarity) || 0;
            retryTitle.textContent = "Face Verification Failed";
            retrySubtitle.innerHTML = `Your face did not match your registered profile photo.<br><span style="color:#f87171; font-weight:600; font-size:14px; margin-top:4px; display:inline-block;">Match Score: ${sim}% (Required: &ge; 48%)</span>`;
            
            playChime(false);
            retryOverlay.style.display = 'flex';
        }

        function retryScanning() {
            consecutiveMismatches = 0;
            retryOverlay.style.display = 'none';
            statusPillBox.style.borderColor = "#e2e8f0";
            statusPillBox.style.background = "#f8fafc";
            statusText.textContent = "Looking for registered face...";
            statusDot.className = "status-dot";
            isScanning = true;
            processFrame();
        }

        function startScanningLoop() {
            processFrame();
        }

        function handleSuccess(data) {
            isScanning = false;
            clearInterval(scanInterval);
            faceBracket.classList.add('active');
            statusDot.className = "status-dot success";
            statusPillBox.style.borderColor = "#86efac";
            statusPillBox.style.background = "#f0fdf4";
            statusText.textContent = `Recognized: ${data.user_name} (${data.similarity}%)`;
            
            playChime(true);

            const isPunchIn = (data.action === 'punch_in');
            const isPunchOut = (data.action === 'punch_out');

            successTitle.textContent = isPunchIn ? "Punch In Recorded!" : (isPunchOut ? "Punch Out Recorded!" : "Attendance Updated!");
            successSubtitle.textContent = `Welcome, ${data.user_name} (Similarity: ${data.similarity}%)`;
            
            let det = `Time: ${data.time}`;
            if (isPunchOut && data.man_hours) {
                det += ` | Working Hours: ${data.man_hours}`;
            }
            if (userLat && userLng) {
                det += ` | GPS: ${userLat.toFixed(4)}, ${userLng.toFixed(4)}`;
            }
            successDetails.textContent = det;
            successOverlay.style.display = 'flex';

            // Countdown and redirect to Dashboard
            let count = 2;
            cdText.textContent = count;
            const timer = setInterval(() => {
                count--;
                cdText.textContent = count;
                if (count <= 0) {
                    clearInterval(timer);
                    window.location.href = 'index.php';
                }
            }, 1000);
        }

        window.onload = async () => {
            const hasLocation = await requestMandatoryLocation();
            if (hasLocation) {
                startCamera();
            }
        };
    </script>
</body>
</html>