<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);

// Check if user is logged in
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    header("Location: login.php");
    exit;
}

// Role based access
if ($_SESSION['userRole'] !== 'senior_intern' && $_SESSION['userRole'] !== 'hr') {
    echo '<script>
        alert("You are not authorized to access this page.");
        window.location.href = "logout.php";
    </script>';
    die();
}

include('../include/db_config.php');

// Get user mode
$userId = $_SESSION['userId'];
$today = date('Y-m-d');

// Sync WFH status dynamically
$columnsResult = mysqli_query($con, "SHOW COLUMNS FROM wfhRequests");
$existingColumns = [];
if ($columnsResult) {
    while ($colRow = mysqli_fetch_assoc($columnsResult)) {
        $existingColumns[] = strtolower($colRow['Field']);
    }
}
$hasFromDate = in_array('fromdate', $existingColumns);
$hasToDate = in_array('todate', $existingColumns);
$hasWfhDate = in_array('wfhdate', $existingColumns);

$dateSelect = "wfhDate AS fromDate, wfhDate AS toDate";
if ($hasFromDate && $hasToDate) {
    $dateSelect = $hasWfhDate ? "COALESCE(fromDate, wfhDate) AS fromDate, COALESCE(toDate, wfhDate) AS toDate" : "fromDate, toDate";
} elseif ($hasWfhDate) {
    $dateSelect = "wfhDate AS fromDate, wfhDate AS toDate";
}

$wfhQuery = mysqli_query($con, "SELECT $dateSelect FROM wfhRequests WHERE userid = '$userId' AND status = 'Approved'");
$wfhToday = false;
if ($wfhQuery) {
    while ($wfhRow = mysqli_fetch_assoc($wfhQuery)) {
        $fDate = $wfhRow['fromDate'];
        $tDate = $wfhRow['toDate'];
        if ($today >= $fDate && $today <= $tDate) {
            $wfhToday = true;
            break;
        }
    }
}

if ($wfhToday) {
    mysqli_query($con, "UPDATE signup SET type = 'Online' WHERE userId = '$userId'");
} else {
    mysqli_query($con, "UPDATE signup SET type = mode WHERE userId = '$userId'");
}

$userQuery = mysqli_query($con, "SELECT type, expectedPunchIn FROM signup WHERE userId = '$userId'");
$userData = mysqli_fetch_assoc($userQuery);
$userMode = $userData['type'];  // "Online" or "Offline"
$expectedPunchIn = $userData['expectedPunchIn'];

// Check if user is on tour
$tourQuery = mysqli_query($con, "SELECT * FROM tour WHERE userId = '$userId' AND '$today' BETWEEN DATE(fromDate) AND DATE(toDate) AND status IN ('Approved', 'Pending');");
$onTour = mysqli_num_rows($tourQuery) > 0;

// Handle punch in form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_punch_in'])) {
    
    if ($onTour) {
        // Redirect to capture selfie for tour punch in
        header("Location: captureSelfie.php?punch=in&tour=1");
        exit();
    } else {
        // For normal punch in
        if ($userMode === 'Online') {
            header("Location: captureSelfie.php?punch=in");
        } else {
            header("Location: qrCodeScanner_copy.php?punch=In");
        }
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Punch In Process</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body {
            background: #f5f5f5;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .punchin-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            width: 100%;
            max-width: 500px;
            border: 1px solid #eee;
        }

        .header-section {
            background: #1977e1;
            color: white;
            padding: 25px;
            text-align: center;
        }

        .header-section h2 {
            margin: 0;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            font-size: 22px;
        }

        .step-indicator {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-top: 15px;
        }

        .step {
            width: 25px;
            height: 25px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.3);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 12px;
        }

        .step.active {
            background: white;
            color: #1977e1;
        }

        .content-section {
            padding: 25px;
        }

        .heading {
            color: #1977e1 !important;
            font-weight: 600;
            margin-bottom: 15px;
            text-align: center;
        }

        .info-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            border-left: 4px solid #1977e1;
            border: 1px solid #eee;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }

        .info-row:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .info-label {
            font-weight: 600;
            color: #555;
            font-size: 14px;
        }

        .info-value {
            color: #333;
            font-size: 14px;
        }

        .tour-badge {
            background: #ff6b6b;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-left: 10px;
            display: inline-block;
        }

        .alert-box {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            color: #856404;
            font-size: 14px;
        }

        .alert-box i {
            margin-right: 8px;
        }

        .btn-custom {
            padding: 12px 25px;
            border-radius: 6px;
            font-weight: 600;
            transition: all 0.3s;
            font-size: 14px;
        }

        .btn-custom-success {
            background: #28a745;
            border: 1px solid #28a745;
            color: white;
        }

        .btn-custom-success:hover {
            background: #218838;
            border-color: #1e7e34;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(40, 167, 69, 0.2);
        }

        .btn-custom-secondary {
            background: #6c757d;
            border: 1px solid #6c757d;
            color: white;
        }

        .btn-custom-secondary:hover {
            background: #5c636a;
            border-color: #565e64;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(108, 117, 125, 0.2);
        }

        .btn-outline-secondary {
            color: #6c757d;
            border-color: #6c757d;
        }

        .btn-outline-secondary:hover {
            background: #6c757d;
            border-color: #6c757d;
            color: white;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 13px;
        }

        .text-muted {
            color: #6c757d !important;
            font-size: 14px;
        }

        .d-flex {
            display: flex !important;
        }

        .justify-content-center {
            justify-content: center !important;
        }

        .gap-3 {
            gap: 15px;
        }

        .mt-4 {
            margin-top: 20px !important;
        }

        .mb-4 {
            margin-bottom: 20px !important;
        }

        .text-center {
            text-align: center;
        }

        .small {
            font-size: 13px;
        }

        /* Mobile adjustments */
        @media (max-width: 480px) {
            .punchin-container {
                max-width: 95%;
            }
            
            .content-section {
                padding: 15px;
            }
            
            .header-section {
                padding: 15px;
            }
            
            .header-section h2 {
                font-size: 18px;
            }
            
            .btn-custom {
                padding: 10px 15px;
                font-size: 13px;
            }
            
            .info-card {
                padding: 15px;
            }
            
            .info-label, .info-value {
                font-size: 13px;
            }
        }
    </style>
</head>

<body>
    <div class="punchin-container">
        <div class="header-section">
            <h2><i class="bi bi-clock"></i> Punch In Process</h2>
            <div class="step-indicator">
                <div class="step active">1</div>
                <div class="step">2</div>
                <div class="step">3</div>
            </div>
        </div>
        
        <div class="content-section">
            <?php if ($onTour): ?>
                <div class="alert-box">
                    <i class="bi bi-info-circle"></i>
                    You are currently on tour. This will be recorded as a tour punch in.
                </div>
            <?php endif; ?>
            
            <h4 class="heading">📋 Punch In Information</h4>
            
            <div class="info-card">
                <div class="info-row">
                    <span class="info-label">Date:</span>
                    <span class="info-value"><?php echo date('d M Y'); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Expected Punch In:</span>
                    <span class="info-value"><?php echo date('h:i A', strtotime($expectedPunchIn)); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Current Time:</span>
                    <span class="info-value"><?php echo date('h:i A'); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Mode:</span>
                    <span class="info-value">
                        <?php echo $userMode; ?>
                        <?php if ($onTour): ?>
                            <span class="tour-badge">On Tour</span>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
            
            <p class="text-center text-muted mb-4">
                <?php if ($onTour): ?>
                    You are on tour. Proceed with tour punch in.
                <?php elseif ($userMode === 'Online'): ?>
                    You are in Online mode. You will need to capture a selfie for punch in.
                <?php else: ?>
                    You are in Offline mode. You will need to scan QR code for punch in.
                <?php endif; ?>
            </p>
            
            <form method="POST" action="" id="punchInForm">
                <input type="hidden" name="process_punch_in" value="1">
                
                <div class="d-flex justify-content-center gap-3">
                    <button type="submit" class="btn btn-custom btn-custom-success">
                        <i class="bi bi-check-circle"></i> Proceed to Punch In
                    </button>
                    <a href="srEmpDashboard.php" class="btn btn-custom btn-custom-secondary">
                        <i class="bi bi-arrow-left"></i> Back
                    </a>
                </div>
            </form>
            
            <div class="mt-4">
                <p class="text-center small text-muted">
                    <i class="bi bi-info-circle"></i>
                    <?php if ($userMode === 'Online'): ?>
                        After clicking proceed, you'll be redirected to capture your selfie for punch in.
                    <?php else: ?>
                        After clicking proceed, you'll be redirected to scan QR code for punch in.
                    <?php endif; ?>
                </p>
            </div>
        </div>
    </div>

    <script>
        // Validate form submission
        document.getElementById('punchInForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const currentTime = new Date();
            const currentHour = currentTime.getHours();
            const currentMinute = currentTime.getMinutes();
            const expectedTime = "<?php echo $expectedPunchIn; ?>";
            const [expectedHour, expectedMinute] = expectedTime.split(':').map(Number);
            
            const currentTotalMinutes = currentHour * 60 + currentMinute;
            const expectedTotalMinutes = expectedHour * 60 + expectedMinute;
            
            // Check if punch in is more than 30 minutes late
            if (currentTotalMinutes > expectedTotalMinutes + 30) {
                Swal.fire({
                    title: 'Late Punch In',
                    text: 'You are punching in more than 30 minutes late. This may be marked as absent.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Continue Anyway',
                    cancelButtonText: 'Go Back',
                    confirmButtonColor: '#28a745',
                    cancelButtonColor: '#6c757d'
                }).then((result) => {
                    if (result.isConfirmed) {
                        this.submit();
                    }
                });
            } else {
                this.submit();
            }
        });
    </script>
</body>
</html>