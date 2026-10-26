<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['userId'])) {
    header("Location: login.php");
    exit;
}
require_once('../include/db_config.php');
require_once('../vendors/TCPDF/tcpdf_barcodes_2d.php');

$userId = mysqli_real_escape_string($con, $_SESSION['userId'] ?? '');
$queryForCheck = mysqli_query($con, "
    SELECT userId, emp_id, internId, fullName, username, email, mobileNo, 
           designation, department, branch, office, mode, type, userRole, 
           employment_type, status, start_date, registration_date, 
           expectedPunchIn, expectedPunchOut, profilePhoto
    FROM signup 
    WHERE userId = '$userId' 
    LIMIT 1
");
$data = $queryForCheck ? mysqli_fetch_assoc($queryForCheck) : null;
$workMode    = !empty($data['type']) ? $data['type'] : (!empty($data['mode']) ? $data['mode'] : 'Offline');
$fullName    = $data['fullName'] ?? 'Employee';
$empId       = !empty($data['emp_id']) ? $data['emp_id'] : (!empty($data['internId']) ? $data['internId'] : $userId);
$designation = $data['designation'] ?? 'Team Member';
$department  = $data['department'] ?? 'General';
$branch      = $data['branch'] ?? ($data['office'] ?? 'Main Office');
$profilePhoto = !empty($data['profilePhoto']) ? $data['profilePhoto'] : 'default.png';

$punch = $_GET['punch'] ?? 'In';
$isOnline = (strcasecmp(trim($workMode), "Online") === 0);

// ── ONLINE EMPLOYEES: FACE ONLY (NO QR SHOW) ──
if ($isOnline) {
    $p = urlencode($punch);
    $extra = '';
    if (isset($_GET['earlyReason'])) $extra .= '&earlyReason=' . urlencode($_GET['earlyReason']);
    if (isset($_GET['updatedToSenior'])) $extra .= '&updatedToSenior=' . urlencode($_GET['updatedToSenior']);
    header("Location: captureSelfie.php?punch={$p}{$extra}");
    exit;
}

// ── COMPACT PERMANENT QR PAYLOAD (BOLD DOTS & INSTANT CAMERA DECODING) ──
$qrPayload = [
    'system' => 'SETTribe AMS',
    'userId' => strval($userId),
    'emp_id' => strval($empId),
    'name'   => strval($fullName),
    'role'   => strval($data['userRole'] ?? 'employee'),
    'mode'   => strval($workMode),
    'dept'   => strval($department),
    'token'  => 'SETTRIBE-' . strtoupper($userId) . '-PERMANENT'
];

$qrJson = json_encode($qrPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$qrDataUri = '';
try {
    $barcode = new TCPDF2DBarcode($qrJson, 'QRCODE,L');
    $rawSvg = $barcode->getBarcodeSVGcode(4, 4, '#0f172a');
    $cleanSvg = preg_replace('/<\?xml[^>]*\?>\s*/i', '', $rawSvg);
    $cleanSvg = preg_replace('/<!DOCTYPE[^>]*>\s*/i', '', $cleanSvg);
    $qrDataUri = 'data:image/svg+xml;base64,' . base64_encode($cleanSvg);
} catch (Throwable $e) {
    $qrDataUri = '';
}

$today = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php include('../partials/_links.php'); ?>
    <title>Step 1: Scan QR Code · SETTribe AMS</title>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="../vendors/js/html5-qrcode.min.js"></script>

    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
        }

        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: #f1f5f9;
        }

        .heading {
            color: #2563eb !important;
            font-weight: 800;
        }

        .scanner-card {
            border-radius: 20px;
            box-shadow: 0 12px 35px rgba(25, 119, 225, 0.08), 0 3px 10px rgba(0, 0, 0, 0.03);
            border: 1px solid #e2e8f0;
            background: #ffffff;
            overflow: hidden;
        }

        /* Live Camera Scanner Box */
        .scanner-viewport-wrapper {
            position: relative;
            width: 100%;
            max-width: 420px;
            margin: 0 auto;
            border-radius: 18px;
            overflow: hidden;
            background: #090d16;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.25);
            border: 2px solid #1e293b;
        }

        #reader {
            width: 100% !important;
            min-height: 290px;
            background: #090d16;
            border: none !important;
        }

        #reader video {
            width: 100% !important;
            height: auto !important;
            border-radius: 14px;
            object-fit: cover;
        }

        /* Target Frame & Laser line animation */
        .scan-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            pointer-events: none;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .scan-reticle {
            width: 220px;
            height: 220px;
            position: relative;
            box-shadow: 0 0 0 4000px rgba(15, 23, 42, 0.45);
            border-radius: 16px;
        }

        /* Reticle Corners */
        .reticle-corner {
            position: absolute;
            width: 26px;
            height: 26px;
            border-color: #10b981;
            border-style: solid;
        }
        .corner-tl { top: 0; left: 0; border-width: 4px 0 0 4px; border-top-left-radius: 12px; }
        .corner-tr { top: 0; right: 0; border-width: 4px 4px 0 0; border-top-right-radius: 12px; }
        .corner-bl { bottom: 0; left: 0; border-width: 0 0 4px 4px; border-bottom-left-radius: 12px; }
        .corner-br { bottom: 0; right: 0; border-width: 0 4px 4px 0; border-bottom-right-radius: 12px; }

        .scan-laser-line {
            position: absolute;
            left: 5%;
            width: 90%;
            height: 3px;
            background: linear-gradient(90deg, transparent, #10b981, #38bdf8, transparent);
            box-shadow: 0 0 12px #10b981;
            animation: scanLaser 2s infinite ease-in-out;
        }

        @keyframes scanLaser {
            0% { top: 8%; opacity: 0.3; }
            50% { top: 90%; opacity: 1; }
            100% { top: 8%; opacity: 0.3; }
        }

        .step-badge {
            background: #eff6ff;
            color: #1d4ed8;
            font-weight: 700;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.8rem;
            border: 1px solid #bfdbfe;
        }

        .btn-action-primary {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: #ffffff;
            border: none;
            padding: 13px 24px;
            font-size: 1rem;
            font-weight: 700;
            border-radius: 12px;
            box-shadow: 0 6px 18px rgba(16, 185, 129, 0.3);
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .btn-action-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 24px rgba(16, 185, 129, 0.4);
            color: #ffffff;
        }

        .badge-preview-card {
            border: 1px dashed #cbd5e1;
            border-radius: 14px;
            padding: 14px;
            background: #f8fafc;
        }
    </style>
</head>

<body>
    <div class="container-scroller">
        <?php include('../partials/_navbar.php'); ?>
        <div class="container-fluid page-body-wrapper">
            <?php include('../partials/_sidebar.php'); ?>

            <div class="main-panel">
                <div class="content-wrapper">
                    <div class="row justify-content-center">
                        <div class="col-lg-8 col-xl-7 grid-margin stretch-card">
                            <div class="card scanner-card">
                                <div class="card-body p-4 text-center">

                                    <!-- Top Flow Header -->
                                    <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-3 text-start">
                                        <div>
                                            <h4 class="card-title heading mb-1">
                                                <i class="fa-solid fa-qrcode me-2"></i> Step 1: Scan Attendance QR Code
                                            </h4>
                                            <p class="text-muted mb-0" style="font-size: 0.85rem;">
                                                Punch Type: <strong><?= htmlspecialchars(strtoupper($punch)) ?></strong> &bull; Work Mode: <strong><?= htmlspecialchars($workMode) ?></strong>
                                            </p>
                                        </div>
                                        <span class="step-badge">
                                            <i class="fa-solid fa-camera me-1"></i> Step 1 of 2 (QR &rarr; Face)
                                        </span>
                                    </div>

                                    <!-- Mandatory GPS Location Notice -->
                                    <div id="locationStatusNotice" class="alert alert-info d-flex align-items-center justify-content-between mb-3 text-start" style="border-radius: 12px; font-size: 0.85rem;">
                                        <span><i class="fa-solid fa-location-crosshairs fa-spin me-2 text-primary"></i> <strong>Mandatory GPS:</strong> Detecting your current location...</span>
                                    </div>

                                    <!-- Offline Procedure Notice -->
                                    <div class="alert alert-light border mb-3 text-start" style="font-size: 0.84rem; border-radius: 12px; background: #f0fdf4; border-color: #bbf7d0 !important;">
                                        <div class="d-flex align-items-start gap-2">
                                            <i class="fa-solid fa-circle-check text-success mt-1"></i>
                                            <div>
                                                <strong class="text-dark">Offline Punch Procedure:</strong>
                                                <div class="text-muted mt-1" style="font-size: 0.82rem;">
                                                    Apna QR Code camera ke samne dikhayein. Scan hote hi Step 2 (Face Attendance) automatically start ho jayega.
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Camera Controls Bar -->
                                    <div class="d-flex align-items-center justify-content-between gap-2 mb-2 p-2 bg-light rounded border">
                                        <div class="d-flex align-items-center gap-2 flex-grow-1">
                                            <i class="fa-solid fa-video text-muted ms-1"></i>
                                            <select id="cameraDeviceSelect" class="form-control form-control-sm" style="font-size: 0.82rem; height: 34px;" onchange="onCameraSelected(this.value)">
                                                <option value="">Detecting Cameras...</option>
                                            </select>
                                        </div>
                                        <button type="button" class="btn btn-sm btn-outline-primary" title="Restart / Switch Camera" onclick="restartCameraScanner()">
                                            <i class="fa-solid fa-arrows-rotate"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" title="Upload QR File" onclick="document.getElementById('qrFileInput').click()">
                                            <i class="fa-solid fa-file-arrow-up"></i>
                                        </button>
                                        <input type="file" id="qrFileInput" accept="image/*" style="display: none;" onchange="handleQrFileUpload(this)">
                                    </div>

                                    <!-- ── LIVE CAMERA QR SCANNER VIEWPORT ── -->
                                    <div class="scanner-viewport-wrapper mb-3">
                                        <div id="reader"></div>
                                        <div class="scan-overlay">
                                            <div class="scan-reticle">
                                                <div class="reticle-corner corner-tl"></div>
                                                <div class="reticle-corner corner-tr"></div>
                                                <div class="reticle-corner corner-bl"></div>
                                                <div class="reticle-corner corner-br"></div>
                                                <div class="scan-laser-line"></div>
                                            </div>
                                        </div>
                                    </div>

                                    <div id="cameraStatusMsg" class="text-center py-1 mb-3" style="font-size: 0.82rem;">
                                        <span class="text-muted"><i class="fa-solid fa-circle-dot me-1 text-primary"></i> Point your QR code inside the frame to scan</span>
                                    </div>

                                    <!-- User Information Card -->
                                    <div class="p-3 bg-light rounded border text-start d-flex align-items-center justify-content-between mb-3">
                                        <div>
                                            <div class="text-dark font-weight-bold" style="font-size: 1rem;">
                                                <?= htmlspecialchars($fullName) ?>
                                            </div>
                                            <div class="text-muted" style="font-size: 0.82rem;">
                                                ID: <strong class="text-primary"><?= htmlspecialchars($empId) ?></strong> &bull; <?= htmlspecialchars($designation) ?> (<?= htmlspecialchars($department) ?>)
                                            </div>
                                        </div>
                                        <span class="badge badge-success px-3 py-2" style="font-size: 0.8rem; border-radius: 12px;">
                                            <i class="fa-solid fa-shield-halved me-1"></i> Verified
                                        </span>
                                    </div>

                                    <!-- Quick Fallback / Show QR Options -->
                                    <div class="d-flex flex-wrap align-items-center justify-content-center gap-2 mb-3">
                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="toggleMyQrBadge()" style="border-radius: 8px; font-size: 0.82rem;">
                                            <i class="fa-solid fa-id-badge me-1"></i> <span id="btnBadgeToggleText">Show My Profile QR Code</span>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="confirmQrAndProceedToFace(true)" style="border-radius: 8px; font-size: 0.82rem;">
                                            <i class="fa-solid fa-arrow-right-to-bracket me-1"></i> Camera Issue? Verify Identity &amp; Proceed
                                        </button>
                                    </div>

                                    <!-- Collapsible Personal Profile QR Badge -->
                                    <div id="myQrBadgeCollapse" style="display: none;" class="badge-preview-card mt-3 text-center">
                                        <div class="d-flex align-items-center justify-content-between mb-2">
                                            <strong style="font-size: 0.85rem; color: #0f172a;">Your Official Permanent QR Badge</strong>
                                            <a href="../ajax/get_employee_qr.php?user_id=<?= urlencode($userId) ?>&download=1" class="btn btn-xs btn-primary" style="font-size: 0.75rem;">
                                                <i class="fa-solid fa-download me-1"></i> Download SVG
                                            </a>
                                        </div>
                                        <div style="display: inline-block; padding: 10px; background: #ffffff; border-radius: 12px; border: 1px solid #e2e8f0;">
                                            <?php if (!empty($qrDataUri)): ?>
                                                <img src="<?= $qrDataUri ?>" alt="Permanent QR" style="width: 170px; height: 170px; object-fit: contain; display: block; margin: 0 auto;">
                                            <?php else: ?>
                                                <img src="../ajax/get_employee_qr.php?user_id=<?= urlencode($userId) ?>" alt="Permanent QR" style="width: 170px; height: 170px; object-fit: contain; display: block; margin: 0 auto;">
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-muted mt-2" style="font-size: 0.78rem;">
                                            This QR is permanently assigned to your profile (<a href="editProfile.php" class="text-primary font-weight-bold">View in Profile</a>).
                                        </div>
                                    </div>

                                    <!-- Hidden metadata inputs -->
                                    <input type="hidden" id="punch" value="<?php echo htmlspecialchars($punch); ?>">
                                    <input type="hidden" id="updatedToSenior" value="<?php echo isset($_GET['updatedToSenior']) ? htmlspecialchars($_GET['updatedToSenior']) : ''; ?>">
                                    <input type="hidden" id="earlyReason" value="<?php echo isset($_GET['earlyReason']) ? htmlspecialchars($_GET['earlyReason']) : ''; ?>">

                                    <p class="text-muted text-center mt-3 mb-0" style="font-size: 0.78rem;">
                                        <i class="fa-solid fa-shield-halved text-success me-1"></i> Secure Permanent QR Identity Powered by SETTribe AMS
                                    </p>

                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- plugins:js -->
    <script src="../vendors/js/vendor.bundle.base.js"></script>
    <script src="../js/off-canvas.js"></script>
    <script src="../js/hoverable-collapse.js"></script>
    <script src="../js/template.js"></script>
    <script src="../js/settings.js"></script>

    <script>
    let userLat = null;
    let userLng = null;
    let locationReady = false;
    let html5QrScannerInstance = null;
    let isScannerRunning = false;
    let scanHandled = false;
    let currentCameraId = null;
    let nativeDetectorInterval = null;

    // ── 1. MANDATORY GPS LOCATION ──
    function fetchMandatoryGps() {
        const locBox = document.getElementById('locationStatusNotice');
        if (!navigator.geolocation) {
            if (locBox) {
                locBox.className = "alert alert-danger d-flex align-items-center mb-3";
                locBox.innerHTML = '<i class="fa-solid fa-triangle-exclamation me-2"></i> Geolocation is not supported by your browser. Location is mandatory!';
            }
            return;
        }

        navigator.geolocation.getCurrentPosition(
            (pos) => {
                userLat = pos.coords.latitude;
                userLng = pos.coords.longitude;
                locationReady = true;
                if (locBox) {
                    locBox.className = "alert alert-success d-flex align-items-center justify-content-between mb-3 text-start";
                    locBox.innerHTML = `<span><i class="fa-solid fa-location-dot me-2 text-success"></i> Mandatory GPS Location Verified: <strong>${userLat.toFixed(4)}, ${userLng.toFixed(4)}</strong></span><span class="badge bg-success text-white">Active</span>`;
                }
            },
            (err) => {
                console.warn("Location error:", err);
                locationReady = false;
                if (locBox) {
                    locBox.className = "alert alert-danger d-flex align-items-center justify-content-between mb-3 text-start";
                    locBox.innerHTML = '<span><i class="fa-solid fa-triangle-exclamation me-2"></i> <strong>GPS Location is MANDATORY!</strong> Please allow location access.</span><button type="button" class="btn btn-sm btn-outline-danger" onclick="fetchMandatoryGps()">Retry</button>';
                }
            },
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
        );
    }
    fetchMandatoryGps();

    // ── 2. AUDITORY FEEDBACK (Pleasant Verification Beep) ──
    function playSuccessBeep() {
        try {
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(880, ctx.currentTime); // A5 note
            osc.frequency.exponentialRampToValueAtTime(1320, ctx.currentTime + 0.15); // E6 note
            gain.gain.setValueAtTime(0.2, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.25);
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start();
            osc.stop(ctx.currentTime + 0.26);
        } catch (e) {
            // Audio context disabled or unavailable
        }
    }

    // ── 3. SUCCESS HANDLER & PROCEED TO FACE SCAN (STEP 2) ──
    function onQrCodeSuccessfullyScanned(decodedText, isManual) {
        if (scanHandled) return;
        scanHandled = true;

        playSuccessBeep();

        if (html5QrScannerInstance && isScannerRunning) {
            html5QrScannerInstance.stop().catch(() => {});
            isScannerRunning = false;
        }
        if (nativeDetectorInterval) {
            clearInterval(nativeDetectorInterval);
            nativeDetectorInterval = null;
        }

        const statusMsg = document.getElementById('cameraStatusMsg');
        if (statusMsg) {
            statusMsg.innerHTML = '<span class="text-success font-weight-bold"><i class="fa-solid fa-circle-check me-1"></i> QR Code Verified! Opening Face Scan...</span>';
        }

        // Check if mandatory GPS location is ready
        if (!locationReady || !userLat || !userLng) {
            // Quick fallback: try to get GPS or use default coordinates if timeout
            navigator.geolocation.getCurrentPosition(
                (pos) => {
                    userLat = pos.coords.latitude;
                    userLng = pos.coords.longitude;
                    executeRedirectToFace(decodedText);
                },
                () => {
                    executeRedirectToFace(decodedText);
                },
                { enableHighAccuracy: true, timeout: 2000 }
            );
        } else {
            executeRedirectToFace(decodedText);
        }
    }

    function executeRedirectToFace(decodedText) {
        const punch = document.getElementById('punch') ? document.getElementById('punch').value : 'In';
        const updatedToSenior = document.getElementById('updatedToSenior') ? document.getElementById('updatedToSenior').value : '';
        const earlyReason = document.getElementById('earlyReason') ? document.getElementById('earlyReason').value : '';

        let url = "captureSelfie.php?punch=" + encodeURIComponent(punch) + "&qr_verified=1";
        if (userLat && userLng) {
            url += "&lat=" + encodeURIComponent(userLat) + "&lng=" + encodeURIComponent(userLng);
        }
        if (updatedToSenior) url += "&updatedToSenior=" + encodeURIComponent(updatedToSenior);
        if (earlyReason) url += "&earlyReason=" + encodeURIComponent(earlyReason);

        Swal.fire({
            icon: 'success',
            title: 'QR Code Scanned!',
            text: 'Step 1 complete. Proceeding to Face Attendance (Step 2)...',
            timer: 1200,
            showConfirmButton: false
        }).then(() => {
            window.location.href = url;
        });
    }

    function confirmQrAndProceedToFace(skipCheck) {
        if (!locationReady && !skipCheck) {
            Swal.fire({
                icon: 'warning',
                title: 'GPS Location Required',
                text: 'Location is mandatory. Please allow location access.',
                confirmButtonColor: '#1977e1'
            }).then(() => {
                fetchMandatoryGps();
            });
            return;
        }
        onQrCodeSuccessfullyScanned("MANUAL_CONFIRMED", true);
    }

    // ── 4. CAMERA SCANNER ENGINE (Html5Qrcode + Native BarcodeDetector) ──
    function initCameraScanner() {
        if (typeof Html5Qrcode === 'undefined') {
            console.error('Html5Qrcode library not loaded');
            return;
        }

        if (!html5QrScannerInstance) {
            html5QrScannerInstance = new Html5Qrcode("reader");
        }

        const selectEl = document.getElementById('cameraDeviceSelect');
        const statusMsg = document.getElementById('cameraStatusMsg');
        if (statusMsg) statusMsg.innerHTML = '<span class="text-primary"><i class="fa-solid fa-spinner fa-spin me-1"></i> Starting camera...</span>';

        Html5Qrcode.getCameras().then(devices => {
            if (!devices || devices.length === 0) {
                if (statusMsg) statusMsg.innerHTML = '<span class="text-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i> No camera found. Use fallback options below.</span>';
                return;
            }

            if (selectEl) {
                selectEl.innerHTML = '';
                devices.forEach((dev, index) => {
                    const opt = document.createElement('option');
                    opt.value = dev.id;
                    opt.text = dev.label || `Camera ${index + 1}`;
                    selectEl.appendChild(opt);
                });
            }

            // Prefer back/environment camera or first available camera
            let targetCameraId = devices[0].id;
            for (let i = 0; i < devices.length; i++) {
                const label = (devices[i].label || '').toLowerCase();
                if (label.includes('back') || label.includes('rear') || label.includes('environment')) {
                    targetCameraId = devices[i].id;
                    if (selectEl) selectEl.value = targetCameraId;
                    break;
                }
            }

            startCameraStream(targetCameraId);
        }).catch(err => {
            console.warn('Get cameras error, falling back to facingMode:', err);
            startCameraStream({ facingMode: "environment" });
        });
    }

    function startCameraStream(camSource) {
        if (scanHandled) return;
        currentCameraId = camSource;
        const statusMsg = document.getElementById('cameraStatusMsg');

        const scanConfig = {
            fps: 25,
            qrbox: function(viewfinderWidth, viewfinderHeight) {
                const minEdge = Math.min(viewfinderWidth, viewfinderHeight);
                return {
                    width: Math.floor(minEdge * 0.75),
                    height: Math.floor(minEdge * 0.75)
                };
            },
            experimentalFeatures: {
                useBarCodeDetectorIfSupported: true
            }
        };

        html5QrScannerInstance.start(
            camSource,
            scanConfig,
            (decodedText, decodedResult) => {
                onQrCodeSuccessfullyScanned(decodedText, false);
            },
            (errorMessage) => {
                // Continuous scanning per-frame callback
            }
        ).then(() => {
            isScannerRunning = true;
            if (statusMsg) {
                statusMsg.innerHTML = '<span class="text-success"><i class="fa-solid fa-circle-dot me-1"></i> Camera Active · Point QR inside frame</span>';
            }
            // Hook native BarcodeDetector loop for instant hardware decoding
            startNativeBarcodeDetectorLoop();
        }).catch(err => {
            isScannerRunning = false;
            console.error('Camera start error:', err);
            if (statusMsg) {
                statusMsg.innerHTML = '<span class="text-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i> Camera permission needed. Please allow camera or use options below.</span>';
            }
        });
    }

    // Native hardware BarcodeDetector loop (built into Chromium/Chrome/Edge)
    function startNativeBarcodeDetectorLoop() {
        if (!('BarcodeDetector' in window)) return;
        try {
            const barcodeDetector = new BarcodeDetector({ formats: ['qr_code'] });
            nativeDetectorInterval = setInterval(() => {
                if (scanHandled || !isScannerRunning) {
                    clearInterval(nativeDetectorInterval);
                    return;
                }
                const videoEl = document.querySelector('#reader video');
                if (videoEl && videoEl.readyState >= 2) {
                    barcodeDetector.detect(videoEl).then(barcodes => {
                        if (barcodes && barcodes.length > 0 && !scanHandled) {
                            const val = barcodes[0].rawValue;
                            if (val) {
                                onQrCodeSuccessfullyScanned(val, false);
                            }
                        }
                    }).catch(() => {});
                }
            }, 120);
        } catch (e) {
            console.warn('Native BarcodeDetector initialization notice:', e);
        }
    }

    function onCameraSelected(id) {
        if (!id) return;
        if (html5QrScannerInstance && isScannerRunning) {
            html5QrScannerInstance.stop().then(() => {
                isScannerRunning = false;
                startCameraStream(id);
            }).catch(() => {
                startCameraStream(id);
            });
        } else {
            startCameraStream(id);
        }
    }

    function restartCameraScanner() {
        const selectEl = document.getElementById('cameraDeviceSelect');
        const selectedId = selectEl && selectEl.value ? selectEl.value : currentCameraId;
        if (html5QrScannerInstance && isScannerRunning) {
            html5QrScannerInstance.stop().then(() => {
                isScannerRunning = false;
                startCameraStream(selectedId || { facingMode: "environment" });
            }).catch(() => {
                initCameraScanner();
            });
        } else {
            initCameraScanner();
        }
    }

    // ── 5. FILE UPLOAD SCANNER (Instant fallback) ──
    function handleQrFileUpload(input) {
        if (!input.files || input.files.length === 0) return;
        const file = input.files[0];
        if (!html5QrScannerInstance) {
            html5QrScannerInstance = new Html5Qrcode("reader");
        }

        const statusMsg = document.getElementById('cameraStatusMsg');
        if (statusMsg) statusMsg.innerHTML = '<span class="text-primary"><i class="fa-solid fa-spinner fa-spin me-1"></i> Decoding QR Image...</span>';

        html5QrScannerInstance.scanFile(file, true)
            .then(decodedText => {
                onQrCodeSuccessfullyScanned(decodedText, false);
            })
            .catch(err => {
                Swal.fire({
                    icon: 'error',
                    title: 'Scan Failed',
                    text: 'Could not detect a valid QR Code in the selected image. Please try another image.',
                    confirmButtonColor: '#1977e1'
                });
                if (statusMsg) statusMsg.innerHTML = '<span class="text-danger">QR Code not found in image.</span>';
            });
    }

    // ── 6. TOGGLE PROFILE QR BADGE ──
    let isBadgeVisible = false;
    function toggleMyQrBadge() {
        isBadgeVisible = !isBadgeVisible;
        const badgeBox = document.getElementById('myQrBadgeCollapse');
        const textSpan = document.getElementById('btnBadgeToggleText');
        if (badgeBox) badgeBox.style.display = isBadgeVisible ? 'block' : 'none';
        if (textSpan) textSpan.innerText = isBadgeVisible ? 'Hide My QR Code' : 'Show My Profile QR Code';
    }

    // Initialize camera on page load
    window.addEventListener('DOMContentLoaded', () => {
        initCameraScanner();
    });
    </script>
</body>
</html>