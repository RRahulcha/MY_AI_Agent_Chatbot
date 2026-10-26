/**
 * SETTribe AMS - Offline QR Code Scanner
 * Validates employee identity QR codes and office QR codes.
 * Enforces mandatory GPS Geolocation before allowing selfie capture redirect.
 */
const html5QrCode = new Html5Qrcode("reader");

let userLat = null;
let userLng = null;
let locationReady = false;

// Request mandatory GPS Location
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
                locBox.className = "alert alert-success d-flex align-items-center justify-content-between mb-3";
                locBox.innerHTML = `<span><i class="fa-solid fa-location-dot me-2 text-success"></i> Mandatory GPS Location Verified: <strong>${userLat.toFixed(4)}, ${userLng.toFixed(4)}</strong></span><span class="badge bg-success text-white">Active</span>`;
            }
        },
        (err) => {
            console.warn("Location error:", err);
            locationReady = false;
            if (locBox) {
                locBox.className = "alert alert-danger d-flex align-items-center justify-content-between mb-3";
                locBox.innerHTML = '<span><i class="fa-solid fa-triangle-exclamation me-2"></i> <strong>GPS Location is MANDATORY!</strong> Please allow location access in your browser.</span><button type="button" class="btn btn-sm btn-outline-danger" onclick="fetchMandatoryGps()">Retry</button>';
            }
        },
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
    );
}

// Start location check immediately
fetchMandatoryGps();

const qrCodeSuccessCallback = (decodedText, decodedResult) => {
    let isValidQr = false;
    let qrEmpName = '';
    
    // Check 1: Contains SETTribe literal string
    if (decodedText.toUpperCase().includes("SETTRIBE")) {
        isValidQr = true;
    } 
    
    // Check 2: Structured JSON Employee QR Code
    try {
        const parsed = JSON.parse(decodedText);
        if (parsed && (parsed.system === "SETTribe AMS" || parsed.userId || parsed.emp_id || parsed.org === "SETTribe IT Solutions")) {
            isValidQr = true;
            qrEmpName = parsed.fullName || parsed.userId || '';
        }
    } catch (e) {
        // Not JSON string, but already checked SETTribe substring
    }

    if (isValidQr) {
        // Location is strictly mandatory!
        if (!locationReady || !userLat || !userLng) {
            Swal.fire({
                icon: 'warning',
                title: 'GPS Location Required',
                text: 'Location is mandatory for offline attendance. Please allow GPS location to proceed.',
                confirmButtonColor: '#2563eb'
            }).then(() => {
                fetchMandatoryGps();
            });
            return;
        }

        html5QrCode.stop().then(() => {
            let punch = document.getElementById('punch') ? document.getElementById('punch').value : 'In';
            let updatedToSenior = document.getElementById('updatedToSenior') ? document.getElementById('updatedToSenior').value : '';
            let earlyReason = document.getElementById('earlyReason') ? document.getElementById('earlyReason').value : '';

            let url = "captureSelfie.php?punch=" + encodeURIComponent(punch) + "&qr_verified=1";
            url += "&lat=" + encodeURIComponent(userLat) + "&lng=" + encodeURIComponent(userLng);
            if (updatedToSenior) url += "&updatedToSenior=" + encodeURIComponent(updatedToSenior);
            if (earlyReason) url += "&earlyReason=" + encodeURIComponent(earlyReason);

            Swal.fire({
                icon: 'success',
                title: 'QR Code Verified!',
                text: qrEmpName ? `Employee: ${qrEmpName}. Proceeding to Selfie Capture...` : 'QR Code Verified. Proceeding to Selfie Capture...',
                timer: 1500,
                showConfirmButton: false
            }).then(() => {
                window.location.href = url;
            });

        }).catch(err => console.error("Error stopping QR scanner:", err));
    }
};

const config = { fps: 12, qrbox: { width: 260, height: 260 } };

// Start camera stream for QR scanner
html5QrCode.start({ facingMode: { exact: "environment" } }, config, qrCodeSuccessCallback)
    .catch(() => {
        html5QrCode.start({ facingMode: "environment" }, config, qrCodeSuccessCallback)
            .catch(() => {
                // Fallback to user-facing camera if environment camera is unavailable
                html5QrCode.start({ facingMode: "user" }, config, qrCodeSuccessCallback)
                    .catch(err => console.error("Could not start QR scanner:", err));
            });
    });
