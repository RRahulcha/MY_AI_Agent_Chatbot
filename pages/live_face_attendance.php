<?php
session_start();
require_once(__DIR__ . '/../include/db_config.php');

$isLoggedIn = !empty($_SESSION['userId']);
$userId = $_SESSION['userId'] ?? '';
$fullName = 'Employee';
$designation = '';
$userRole = 'user';

if ($isLoggedIn && isset($con)) {
    $uQ = mysqli_query($con, "SELECT fullName, designation, userRole FROM signup WHERE userId='$userId' LIMIT 1");
    if ($uQ && $userData = mysqli_fetch_assoc($uQ)) {
        $fullName = $userData['fullName'] ?? 'Employee';
        $designation = $userData['designation'] ?? '';
        $userRole = $userData['userRole'] ?? 'user';
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php include('../partials/_links.php'); ?>
    <meta charset="UTF-8">
    <title>AI Live Face Attendance - SETTribe AMS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background-color: #f1f5f9;
            margin: 0;
            color: #1e293b;
        }

        .main-content-wrapper {
            display: flex;
            justify-content: center;
            align-items: center;
            width: 100%;
            padding: 24px 16px;
            min-height: <?= $isLoggedIn ? 'calc(100vh - 80px)' : '100vh' ?>;
        }

        .ai-attendance-card {
            background: #ffffff;
            border-radius: 22px;
            box-shadow: 0 14px 40px rgba(15, 23, 42, 0.08), 0 4px 12px rgba(0, 0, 0, 0.03);
            max-width: 580px;
            width: 100%;
            overflow: hidden;
            border: 1px solid #e2e8f0;
            position: relative;
        }

        /* Top Header */
        .ai-card-header {
            background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 50%, #3b82f6 100%);
            padding: 20px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            color: #ffffff;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .header-icon {
            width: 46px;
            height: 46px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            backdrop-filter: blur(4px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .header-text h2 {
            font-size: 18px;
            font-weight: 700;
            margin: 0 0 2px;
            color: #ffffff;
            letter-spacing: -0.2px;
        }

        .header-text p {
            font-size: 12px;
            color: rgba(255, 255, 255, 0.85);
            margin: 0;
        }

        .punch-mode-badge {
            background: rgba(255, 255, 255, 0.22);
            border: 1px solid rgba(255, 255, 255, 0.35);
            color: #ffffff;
            padding: 6px 14px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.3px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .pulse-light {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #4ade80;
            box-shadow: 0 0 8px #4ade80;
            animation: blink 1.2s infinite;
        }

        @keyframes blink {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.4; transform: scale(1.2); }
        }

        /* Card Body */
        .ai-card-body {
            padding: 24px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        /* Top Bar inside Card */
        .live-info-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: 100%;
            margin-bottom: 14px;
            font-size: 12.5px;
            color: #64748b;
            font-weight: 500;
        }

        .live-clock {
            font-weight: 700;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* Camera Box */
        .camera-wrapper {
            position: relative;
            width: 100%;
            height: 360px;
            border-radius: 18px;
            overflow: hidden;
            background: #090d16;
            border: 2px solid #cbd5e1;
            box-shadow: inset 0 0 25px rgba(0, 0, 0, 0.6);
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
            background: linear-gradient(90deg, transparent, #38bdf8, #60a5fa, #38bdf8, transparent);
            box-shadow: 0 0 14px 3px #38bdf8;
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
            width: 220px;
            height: 260px;
            border: 2px dashed rgba(56, 189, 248, 0.55);
            border-radius: 24px;
            pointer-events: none;
            z-index: 6;
            transition: all 0.3s ease;
        }

        .face-bracket.detecting {
            border-color: #38bdf8;
            border-style: solid;
            box-shadow: 0 0 18px rgba(56, 189, 248, 0.35);
        }

        .face-bracket.active {
            border-color: #22c55e;
            border-style: solid;
            box-shadow: 0 0 25px rgba(34, 197, 94, 0.6);
            transform: scale(1.02);
        }

        /* Real-Time Status Pill */
        .status-pill-box {
            margin-top: 18px;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 20px;
            border-radius: 14px;
            background: #f8fafc;
            border: 1.5px solid #e2e8f0;
            width: 100%;
            justify-content: center;
            transition: all 0.3s;
        }

        .status-dot {
            width: 11px;
            height: 11px;
            border-radius: 50%;
            background: #3b82f6;
            animation: pulseDot 1.4s infinite;
        }

        .status-dot.success {
            background: #22c55e;
            box-shadow: 0 0 10px #22c55e;
            animation: none;
        }

        .status-dot.warning {
            background: #f59e0b;
            box-shadow: 0 0 8px #f59e0b;
        }

        @keyframes pulseDot {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.35); opacity: 0.6; }
        }

        .status-text {
            font-size: 14px;
            font-weight: 600;
            color: #0f172a;
        }

        /* Match Info Badge */
        .match-info-strip {
            margin-top: 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            font-size: 12px;
            color: #64748b;
            padding: 4px;
        }

        .match-info-strip span {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* Success Card Overlay */
        .success-overlay {
            position: absolute;
            inset: 0;
            background: rgba(15, 23, 42, 0.94);
            backdrop-filter: blur(8px);
            display: none;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 20;
            padding: 24px;
            text-align: center;
            animation: fadeIn 0.35s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: scale(0.92); }
            to { opacity: 1; transform: scale(1); }
        }

        .success-check-icon {
            width: 68px;
            height: 68px;
            background: #10b981;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-size: 32px;
            margin-bottom: 14px;
            box-shadow: 0 0 30px rgba(16, 185, 129, 0.55);
            animation: bounceIn 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }

        @keyframes bounceIn {
            0% { transform: scale(0); }
            60% { transform: scale(1.15); }
            100% { transform: scale(1); }
        }

        .success-overlay h3 {
            font-size: 22px;
            color: #ffffff;
            font-weight: 700;
            margin: 0 0 4px;
        }

        .success-overlay p {
            font-size: 14px;
            color: #cbd5e1;
            margin: 0 0 14px;
        }

        .success-details-badge {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.18);
            padding: 10px 20px;
            border-radius: 12px;
            color: #38bdf8;
            font-weight: 700;
            font-size: 14px;
            margin-bottom: 12px;
        }

        .btn-kiosk-action {
            background: rgba(255, 255, 255, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: #f1f5f9;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 13px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }

        .btn-kiosk-action:hover {
            background: rgba(255, 255, 255, 0.2);
            color: #fff;
            text-decoration: none;
        }

        /* Control toolbar */
        .kiosk-toolbar {
            display: flex;
            gap: 10px;
            margin-top: 16px;
            width: 100%;
            justify-content: center;
            flex-wrap: wrap;
        }

        .toolbar-btn {
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            padding: 8px 14px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            color: #475569;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }

        .toolbar-btn:hover {
            background: #e2e8f0;
            color: #0f172a;
        }

        .toolbar-btn.active {
            background: #2563eb;
            color: #fff;
            border-color: #2563eb;
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
                <div class="content-wrapper d-flex justify-content-center align-items-center">
    <?php endif; ?>

        <div class="main-content-wrapper">
            <div class="ai-attendance-card" id="kioskCard">
                <!-- Header -->
                <div class="ai-card-header">
                    <div class="header-left">
                        <div class="header-icon">
                            <i class="fa-solid fa-camera-retro"></i>
                        </div>
                        <div class="header-text">
                            <h2>SETTribe Face Attendance</h2>
                            <p id="subHeaderText">Automated Real-Time Face Scanner</p>
                        </div>
                    </div>
                    <span class="punch-mode-badge" id="modeBadge">
                        <span class="pulse-light"></span> Live Auto
                    </span>
                </div>

                <!-- Body -->
                <div class="ai-card-body">
                    <!-- Live Info Header -->
                    <div class="live-info-bar">
                        <div class="live-clock">
                            <i class="fa-regular fa-clock text-primary"></i> <span id="clockText">--:--:--</span>
                        </div>
                        <div id="targetUserIndicator">
                            <?php if ($isLoggedIn): ?>
                                <span class="badge badge-light border"><i class="fa-solid fa-user-check text-success"></i> <?= htmlspecialchars($fullName) ?></span>
                            <?php else: ?>
                                <span class="badge badge-light border"><i class="fa-solid fa-users text-primary"></i> Office Kiosk (All Employees)</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Camera Viewport -->
                    <div class="camera-wrapper" id="cameraWrapper">
                        <video id="video" autoplay playsinline muted></video>
                        <div class="scan-laser"></div>
                        <div class="face-bracket" id="faceBracket"></div>

                        <!-- Success Overlay -->
                        <div class="success-overlay" id="successOverlay">
                            <div class="success-check-icon">
                                <i class="fa-solid fa-check"></i>
                            </div>
                            <h3 id="successTitle">Attendance Recorded!</h3>
                            <p id="successSubtitle">Verified via Facial Geometry</p>
                            <div class="success-details-badge" id="successDetails">
                                Time: --:--:--
                            </div>
                            <div style="color:#94a3b8; font-size:12.5px; margin-top:8px;" id="resumeNotice">
                                Ready for next employee in <span id="cdText" style="color:#fff; font-weight:700;">3</span>s...
                            </div>
                        </div>
                    </div>

                    <!-- Real-Time Status Pill -->
                    <div class="status-pill-box" id="statusPillBox">
                        <div class="status-dot" id="statusDot"></div>
                        <span class="status-text" id="statusText">Starting camera & AI service...</span>
                    </div>

                    <!-- Meta Info Strip -->
                    <div class="match-info-strip">
                        <span><i class="fa-solid fa-shield-halved text-primary"></i> Biometric Verified</span>
                        <span id="matchScoreText"><i class="fa-solid fa-brain text-info"></i> Facenet512 Engine Active</span>
                        <span><i class="fa-solid fa-bolt text-warning"></i> Instant Auto Punch</span>
                    </div>

                    <!-- Face Not Matching / Unrecognized Banner -->
                    <div id="mismatchBanner" style="display:none; margin-top:12px; width:100%; background:linear-gradient(135deg, rgba(254, 242, 242, 0.95), rgba(255, 247, 237, 0.95)); border:1px solid #fca5a5; border-radius:12px; padding:12px 14px; text-align:center; box-shadow: 0 4px 14px rgba(239, 68, 68, 0.08);">
                        <div style="font-size:13px; font-weight:700; color:#b91c1c; margin-bottom:4px; display:flex; align-items:center; justify-content:center; gap:6px;">
                            <i class="fa-solid fa-triangle-exclamation"></i> Face Not Matching or Not Registered?
                        </div>
                        <div style="font-size:11.5px; color:#64748b; margin-bottom:8px;">
                            Complete a quick Google Auth-style 3D motion scan to verify and store your face details.
                        </div>
                        <a href="face_verification.php<?= $isLoggedIn ? '?user_id=' . $userId : '' ?>" class="toolbar-btn" style="background:#2563eb; color:#fff; border:none; padding:7px 16px; border-radius:8px; font-size:12px; font-weight:700; box-shadow:0 4px 12px rgba(37,99,235,0.25); text-decoration:none;">
                            <i class="fa-solid fa-arrows-spin"></i> Start 3D Motion Face Verification
                        </a>
                    </div>

                    <!-- Toolbar Controls -->
                    <div class="kiosk-toolbar">
                        <a href="face_verification.php<?= $isLoggedIn ? '?user_id=' . $userId : '' ?>" class="toolbar-btn" style="background:#eff6ff; border-color:#93c5fd; color:#1d4ed8;" title="Google Auth-style 3D Motion Face Verification">
                            <i class="fa-solid fa-id-card-clip"></i> 3D Face Verification
                        </a>
                        <button type="button" class="toolbar-btn" id="btnAudioToggle" onclick="toggleAudio()">
                            <i class="fa-solid fa-volume-high" id="audioIcon"></i> Sound ON
                        </button>
                        <button type="button" class="toolbar-btn" onclick="toggleFullscreen()">
                            <i class="fa-solid fa-expand"></i> Fullscreen
                        </button>
                        <?php if ($isLoggedIn): ?>
                            <a href="index.php" class="toolbar-btn">
                                <i class="fa-solid fa-arrow-left"></i> Dashboard
                            </a>
                        <?php else: ?>
                            <a href="login.php" class="toolbar-btn">
                                <i class="fa-solid fa-right-to-bracket"></i> Login Page
                            </a>
                        <?php endif; ?>
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

    <canvas id="hiddenCanvas" style="display:none;"></canvas>

    <script>
        const AI_SERVICE_URL = '../ajax/scan_face_proxy.php';
        const video = document.getElementById('video');
        const hiddenCanvas = document.getElementById('hiddenCanvas');
        const statusText = document.getElementById('statusText');
        const statusDot = document.getElementById('statusDot');
        const statusPillBox = document.getElementById('statusPillBox');
        const faceBracket = document.getElementById('faceBracket');
        const matchScoreText = document.getElementById('matchScoreText');
        const clockText = document.getElementById('clockText');

        const successOverlay = document.getElementById('successOverlay');
        const successTitle = document.getElementById('successTitle');
        const successSubtitle = document.getElementById('successSubtitle');
        const successDetails = document.getElementById('successDetails');
        const resumeNotice = document.getElementById('resumeNotice');
        const cdText = document.getElementById('cdText');

        const mismatchBanner = document.getElementById('mismatchBanner');

        const isLoggedIn = <?= $isLoggedIn ? 'true' : 'false' ?>;
        const loggedInUserId = '<?= $userId ?>';

        let isScanning = true;
        let isProcessing = false;
        let audioEnabled = true;
        let cooldownActiveUntil = 0;
        let mismatchConsecutive = 0;

        // Digital Clock
        function updateClock() {
            const now = new Date();
            clockText.textContent = now.toLocaleTimeString('en-US', { hour12: true });
        }
        setInterval(updateClock, 1000);
        updateClock();

        // Audio chime generator using Web Audio API
        function playChime(isSuccess = true) {
            if (!audioEnabled) return;
            try {
                const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                const osc = audioCtx.createOscillator();
                const gain = audioCtx.createGain();
                osc.connect(gain);
                gain.connect(audioCtx.destination);

                if (isSuccess) {
                    osc.frequency.setValueAtTime(880, audioCtx.currentTime); // A5
                    osc.frequency.setValueAtTime(1318.51, audioCtx.currentTime + 0.12); // E6
                    gain.gain.setValueAtTime(0.2, audioCtx.currentTime);
                    gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + 0.5);
                    osc.start();
                    osc.stop(audioCtx.currentTime + 0.5);
                } else {
                    osc.frequency.setValueAtTime(350, audioCtx.currentTime);
                    gain.gain.setValueAtTime(0.15, audioCtx.currentTime);
                    gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + 0.25);
                    osc.start();
                    osc.stop(audioCtx.currentTime + 0.25);
                }
            } catch (e) {
                console.log("Audio API note:", e);
            }
        }

        function toggleAudio() {
            audioEnabled = !audioEnabled;
            const btn = document.getElementById('btnAudioToggle');
            const icon = document.getElementById('audioIcon');
            if (audioEnabled) {
                btn.innerHTML = '<i class="fa-solid fa-volume-high" id="audioIcon"></i> Sound ON';
                btn.classList.remove('active');
            } else {
                btn.innerHTML = '<i class="fa-solid fa-volume-xmark" id="audioIcon"></i> Sound OFF';
                btn.classList.add('active');
            }
        }

        function toggleFullscreen() {
            const elem = document.getElementById('kioskCard');
            if (!document.fullscreenElement) {
                if (elem.requestFullscreen) elem.requestFullscreen();
                else if (elem.webkitRequestFullscreen) elem.webkitRequestFullscreen();
            } else {
                if (document.exitFullscreen) document.exitFullscreen();
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
                statusText.textContent = "Looking for employee face... Stand in front of camera";
                statusDot.className = "status-dot";
                
                video.onloadedmetadata = () => {
                    processLoop();
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
            
            return hiddenCanvas.toDataURL('image/jpeg', 0.75);
        }

        // Non-blocking Sequential Process Loop
        async function processLoop() {
            if (!isScanning) return;
            if (isProcessing) return;

            const now = Date.now();
            if (now < cooldownActiveUntil) {
                setTimeout(processLoop, 250);
                return;
            }

            const frameData = captureFrameBase64();
            if (!frameData) {
                setTimeout(processLoop, 200);
                return;
            }

            isProcessing = true;
            try {
                const response = await fetch(AI_SERVICE_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        image: frameData,
                        mode: isLoggedIn ? 'auto' : 'kiosk'
                    })
                });

                const data = await response.json();

                if (!isScanning) return;

                if (data.detected && !data.recognized) {
                    faceBracket.classList.add('detecting');
                    faceBracket.classList.remove('active');
                    statusDot.className = "status-dot warning";
                    mismatchConsecutive++;

                    if (mismatchConsecutive >= 3 && mismatchBanner) {
                        mismatchBanner.style.display = 'block';
                    }

                    const sim = parseFloat(data.similarity) || 0;
                    if (sim > 0) {
                        statusText.textContent = `Matching face... Hold steady (${sim}%)`;
                        matchScoreText.innerHTML = `<i class="fa-solid fa-chart-line text-warning"></i> Confidence: ${sim}%`;
                    } else {
                        statusText.textContent = "Face detected! Look directly into the camera...";
                    }
                } else if (!data.detected) {
                    faceBracket.classList.remove('detecting', 'active');
                    statusDot.className = "status-dot";
                    statusText.textContent = "Looking for face... Please look into the camera";
                    matchScoreText.innerHTML = `<i class="fa-solid fa-brain text-info"></i> Facenet512 Engine Active`;
                } else if (data.recognized) {
                    mismatchConsecutive = 0;
                    if (mismatchBanner) mismatchBanner.style.display = 'none';
                    // Face successfully identified!
                    if (data.cooldown) {
                        statusText.textContent = `Attendance already processed for ${data.user_name}! Please step aside.`;
                        statusDot.className = "status-dot warning";
                        faceBracket.classList.remove('detecting', 'active');
                        cooldownActiveUntil = Date.now() + 2500;
                    } else {
                        handlePunchSuccess(data);
                        return;
                    }
                }
            } catch (err) {
                statusText.textContent = "Connecting to FaceVision AI service (Port 5001)...";
                statusDot.className = "status-dot warning";
            } finally {
                isProcessing = false;
                if (isScanning) {
                    setTimeout(processLoop, 130);
                }
            }
        }

        // Handle Automatic Attendance Punch Success
        function handlePunchSuccess(data) {
            isScanning = false;
            faceBracket.classList.remove('detecting');
            faceBracket.classList.add('active');

            statusDot.className = "status-dot success";
            statusPillBox.style.borderColor = "#86efac";
            statusPillBox.style.background = "#f0fdf4";
            statusText.textContent = `Recognized: ${data.user_name} (${data.similarity}%)`;

            playChime(true);

            const isPunchIn = (data.action === 'punch_in');
            const isPunchOut = (data.action === 'punch_out');

            successTitle.textContent = isPunchIn ? "Punch In Recorded!" : (isPunchOut ? "Punch Out Recorded!" : "Attendance Marked!");
            successSubtitle.textContent = `Welcome, ${data.user_name} (Confidence: ${data.similarity}%)`;

            let detailMsg = `Time: ${data.time}`;
            if (isPunchOut && data.man_hours) {
                detailMsg += ` | Working Hours: ${data.man_hours}`;
            }
            successDetails.textContent = detailMsg;

            if (isLoggedIn) {
                resumeNotice.innerHTML = `Redirecting to Dashboard in <span id="cdText" style="color:#fff; font-weight:700;">3</span>s...`;
            } else {
                resumeNotice.innerHTML = `Ready for next employee in <span id="cdText" style="color:#fff; font-weight:700;">3</span>s...`;
            }

            successOverlay.style.display = 'flex';

            let count = 3;
            const timer = setInterval(() => {
                count--;
                const elem = document.getElementById('cdText');
                if (elem) elem.textContent = count;

                if (count <= 0) {
                    clearInterval(timer);
                    if (isLoggedIn) {
                        window.location.href = 'index.php';
                    } else {
                        // Reset for next employee in Kiosk mode
                        successOverlay.style.display = 'none';
                        faceBracket.classList.remove('active', 'detecting');
                        statusPillBox.style.borderColor = "#e2e8f0";
                        statusPillBox.style.background = "#f8fafc";
                        statusText.textContent = "Looking for next employee face...";
                        statusDot.className = "status-dot";
                        cooldownActiveUntil = Date.now() + 3000;
                        isScanning = true;
                        processLoop();
                    }
                }
            }, 1000);
        }

        window.onload = startCamera;
    </script>
</body>

</html>
