<?php
session_start();
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}

include('../include/db_config.php');
include('../include/sweetAlert.php');

$today = date("Y-m-d");
$userId = $_SESSION['userId'];

// ✅ Handle Cancel via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel') {
    $tourId       = intval($_POST['tour_id']);
    $cancelReason = mysqli_real_escape_string($con, trim($_POST['cancel_reason']));

    if (empty($cancelReason)) {
        $_SESSION['status'] = false;
        $_SESSION['msg']    = "Cancel reason is required.";
        echo "<script>window.location.href='display_tour.php';</script>";
        exit;
    }

    $upd = $con->prepare("UPDATE tour SET status='Cancelled', cancelReason=? WHERE id=? AND userId=? AND toDate >= ?");
    $upd->bind_param("siss", $cancelReason, $tourId, $userId, $today);
    if ($upd->execute() && $upd->affected_rows > 0) {
        $_SESSION['status'] = true;
        $_SESSION['msg']    = "Tour cancelled successfully.";
    } else {
        $_SESSION['status'] = false;
        $_SESSION['msg']    = "Could not cancel the tour.";
    }
    $upd->close();
    echo "<script>window.location.href='display_tour.php';</script>";
    exit;
}

// ✅ Handle Update via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {
    $tourId   = intval($_POST['tour_id']);
    $fromDate = $_POST['from_date'];
    $toDate   = $_POST['to_date'];
    $reason   = mysqli_real_escape_string($con, trim($_POST['reason']));

    $upd = $con->prepare("UPDATE tour SET fromDate=?, toDate=?, reason=? WHERE id=? AND userId=? AND toDate >= ?");
    $upd->bind_param("sssiss", $fromDate, $toDate, $reason, $tourId, $userId, $today);
    if ($upd->execute() && $upd->affected_rows > 0) {
        $_SESSION['status'] = true;
        $_SESSION['msg']    = "Tour updated successfully.";
    } else {
        $_SESSION['status'] = false;
        $_SESSION['msg']    = "Could not update the tour.";
    }
    $upd->close();
    echo "<script>window.location.href='display_tour.php';</script>";
    exit;
}

// Fetch KPI statistics for the user
$kpiQ = mysqli_query($con, "
    SELECT 
        COUNT(*) AS totalTours,
        SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) AS approvedTours,
        SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) AS pendingTours,
        SUM(CASE WHEN status = 'Approved' THEN (DATEDIFF(toDate, fromDate) + 1) ELSE 0 END) AS totalDays
    FROM tour 
    WHERE userId = '$userId'
");
$kpi = $kpiQ ? mysqli_fetch_assoc($kpiQ) : [];
$totalTours    = (int)($kpi['totalTours'] ?? 0);
$approvedTours = (int)($kpi['approvedTours'] ?? 0);
$pendingTours  = (int)($kpi['pendingTours'] ?? 0);
$totalDays     = (int)($kpi['totalDays'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>My Tour Applications</title>
    <?php include('../partials/_links.php'); ?>
    <style>
        .card { border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 2px 10px rgba(0,0,0,0.03); }
        .card-title { font-size: 1.15rem; font-weight: 700; color: #1e293b; }
        .form-control { border-radius: 8px; border: 1px solid #cbd5e1; padding: 10px 14px; font-size: 0.88rem; }
        .form-control:focus { border-color: #4b49ac; box-shadow: 0 0 0 3px rgba(75,73,172,0.12); }
        
        /* KPI Cards */
        .kpi-card {
            background: #fff; border-radius: 12px; border: 1px solid #e2e8f0;
            padding: 18px 20px; transition: transform 0.2s, box-shadow 0.2s;
        }
        .kpi-card:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(0,0,0,0.06); }
        .kpi-icon {
            width: 48px; height: 48px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.3rem; flex-shrink: 0;
        }
        .kpi-val { font-size: 1.6rem; font-weight: 800; color: #0f172a; line-height: 1.1; margin-top: 4px; }
        .kpi-lbl { font-size: 0.78rem; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.04em; }

        .badge-pending { background: #FEF3C7; color: #92400E; border: 1px solid #FCD34D; }
        .badge-approved { background: #D1FAE5; color: #065F46; border: 1px solid #6EE7B7; }
        .badge-rejected { background: #FEE2E2; color: #991B1B; border: 1px solid #FCA5A5; }
        .badge-cancelled { background: #F1F5F9; color: #475569; border: 1px solid #CBD5E1; }

        #tourTable thead th {
            background: #f8fafc; color: #475569; font-weight: 700;
            font-size: 0.78rem; text-transform: uppercase; border-bottom: 2px solid #e2e8f0;
        }

        .export-btn-group {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .btn-export-excel {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%) !important;
            color: #ffffff !important;
            border: none !important;
            font-weight: 600 !important;
            font-size: 0.8rem !important;
            padding: 7px 16px !important;
            border-radius: 8px !important;
            transition: all 0.2s ease !important;
            box-shadow: 0 2px 6px rgba(16, 185, 129, 0.25) !important;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .btn-export-excel:hover {
            background: linear-gradient(135deg, #059669 0%, #047857 100%) !important;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.35) !important;
            transform: translateY(-1px);
            color: #ffffff !important;
        }
        .btn-export-pdf {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%) !important;
            color: #ffffff !important;
            border: none !important;
            font-weight: 600 !important;
            font-size: 0.8rem !important;
            padding: 7px 16px !important;
            border-radius: 8px !important;
            transition: all 0.2s ease !important;
            box-shadow: 0 2px 6px rgba(239, 68, 68, 0.25) !important;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .btn-export-pdf:hover {
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%) !important;
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.35) !important;
            transform: translateY(-1px);
            color: #ffffff !important;
        }
        @media (max-width: 576px) {
            .export-btn-group {
                width: 100%;
                justify-content: space-between;
                margin-top: 8px;
            }
            .export-btn-group .btn {
                flex: 1;
                text-align: center;
                justify-content: center;
            }
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.4.1/css/responsive.bootstrap4.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.17.0/xlsx.full.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.4.0/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.23/jspdf.plugin.autotable.min.js"></script>
</head>
<body>
    <div class="container-scroller">
        <?php include('../partials/_navbar.php'); ?>
        <div class="container-fluid page-body-wrapper">
            <?php
            include('../partials/_sidebar.php');
            include('../partials/_settings-panel.php');
            ?>

            <div class="main-panel">
                <div class="content-wrapper" style="background:#f4f6fb; padding: 24px;">

                    <!-- Page Title & Header -->
                    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
                        <div>
                            <h3 class="fw-bold text-dark mb-1">
                                <i class="fa-solid fa-map-location-dot text-primary me-2"></i> My Tour Applications
                            </h3>
                            <p class="text-muted small mb-0">Track live tour application statuses, approval remarks, and travel duration.</p>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <a href="apply_tour.php" class="btn btn-primary px-3 py-2" style="border-radius:8px; font-weight:600;">
                                <i class="fa-solid fa-plus me-1"></i> Apply New Tour
                            </a>
                        </div>
                    </div>

                    <!-- ── 1. KPI Summary Cards ── -->
                    <div class="row mb-4">
                        <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-lbl">Total Applications</div>
                                    <div class="kpi-val text-primary"><?= $totalTours ?></div>
                                    <small class="text-muted" style="font-size:0.72rem;">All time requests</small>
                                </div>
                                <div class="kpi-icon" style="background:#EEEDFE; color:#4b49ac;">
                                    <i class="fa-solid fa-paper-plane"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-lbl">Approved Tours</div>
                                    <div class="kpi-val text-success"><?= $approvedTours ?></div>
                                    <small class="text-muted" style="font-size:0.72rem;">Successfully approved</small>
                                </div>
                                <div class="kpi-icon" style="background:#E6F4EA; color:#1E8E3E;">
                                    <i class="fa-solid fa-circle-check"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-lbl">Pending Review</div>
                                    <div class="kpi-val text-warning" style="color:#b06000 !important;"><?= $pendingTours ?></div>
                                    <small class="text-muted" style="font-size:0.72rem;">Awaiting manager review</small>
                                </div>
                                <div class="kpi-icon" style="background:#FEF7E0; color:#B06000;">
                                    <i class="fa-solid fa-clock"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-lbl">Total Tour Days</div>
                                    <div class="kpi-val text-info" style="color:#0284C7 !important;"><?= $totalDays ?> <span style="font-size:0.9rem;">days</span></div>
                                    <small class="text-muted" style="font-size:0.72rem;">Combined travel days</small>
                                </div>
                                <div class="kpi-icon" style="background:#E0F2FE; color:#0284C7;">
                                    <i class="fa-solid fa-suitcase-rolling"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── 2. Tour Applications Table Card ── -->
                    <div class="card">
                        <div class="card-body p-4">
                            <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                                <div>
                                    <h4 class="card-title mb-0">
                                        <i class="fa-solid fa-list-check text-primary me-2"></i> Tour History & Application Status
                                    </h4>
                                    <p class="text-muted small mb-0">Manage tour dates, cancellation reasons, and approval remarks.</p>
                                </div>
                                <div class="export-btn-group">
                                    <button id="exportExcel" class="btn btn-export-excel" title="Export to Excel" onclick="exportTableToExcel()">
                                        <i class="fa-solid fa-file-excel me-1"></i> Excel
                                    </button>
                                    <button id="downloadPdf" class="btn btn-export-pdf" title="Download as PDF" onclick="exportTableToPDF()">
                                        <i class="fa-solid fa-file-pdf me-1"></i> PDF
                                    </button>
                                </div>
                            </div>
                            <hr class="text-muted opacity-25 mb-4">

                            <div class="table-responsive">
                                <table class="table table-hover align-middle w-100" id="tourTable" style="font-size:0.85rem;">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Associated Project(s)</th>
                                            <th>Tour Reason</th>
                                            <th>Tour Duration</th>
                                            <th>Status</th>
                                            <th>Approved By</th>
                                            <th>Rejection / Cancel Reason</th>
                                            <th>Applied On</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $sql = "SELECT t.*, s.fullName AS approverName
                                                FROM tour t
                                                LEFT JOIN signup s ON t.approvedBy = s.userId
                                                WHERE t.userId = ?
                                                ORDER BY t.dateTime DESC";

                                        $stmt = $con->prepare($sql);
                                        $stmt->bind_param("s", $userId);
                                        $stmt->execute();
                                        $result = $stmt->get_result();

                                        $sr = 1;
                                        while ($row = $result->fetch_assoc()) {

                                            // Project names & client details
                                            $projectNames = [];
                                            if (!empty($row['projectId'])) {
                                                $cleanIds = implode(',', array_map('intval', array_filter(explode(',', $row['projectId']))));
                                                if (!empty($cleanIds)) {
                                                    $res = $con->query("SELECT projectName, client FROM projectMaster WHERE projectId IN ($cleanIds)");
                                                    if ($res) {
                                                        while ($p = $res->fetch_assoc()) {
                                                            $projectNames[] = htmlspecialchars($p['projectName']) . ' <span class="text-muted">(' . htmlspecialchars($p['client']) . ')</span>';
                                                        }
                                                    }
                                                }
                                            }
                                            $projectsFormatted = !empty($projectNames) ? implode('<br>', $projectNames) : '<span class="text-muted" style="font-style:italic;">General / Other</span>';

                                            // Status Badge
                                            $st = strtolower($row['status'] ?? 'pending');
                                            if ($st === 'approved') {
                                                $statusBadge = '<span class="badge badge-approved px-2 py-1"><i class="fa-solid fa-check me-1"></i> Approved</span>';
                                            } elseif ($st === 'rejected') {
                                                $statusBadge = '<span class="badge badge-rejected px-2 py-1"><i class="fa-solid fa-xmark me-1"></i> Rejected</span>';
                                            } elseif ($st === 'cancelled') {
                                                $statusBadge = '<span class="badge badge-cancelled px-2 py-1"><i class="fa-solid fa-ban me-1"></i> Cancelled</span>';
                                            } else {
                                                $statusBadge = '<span class="badge badge-pending px-2 py-1"><i class="fa-solid fa-clock me-1"></i> Pending</span>';
                                            }

                                            // Duration calculation
                                            $fromTs = strtotime($row['fromDate']);
                                            $toTs   = strtotime($row['toDate']);
                                            $daysCount = floor(($toTs - $fromTs) / 86400) + 1;
                                            $durationHtml = '<div><strong class="text-dark">' . date('d M Y', $fromTs) . '</strong> to <strong class="text-dark">' . date('d M Y', $toTs) . '</strong></div>';
                                            $durationHtml .= '<small class="text-primary fw-bold" style="font-size:0.75rem;"><i class="fa-solid fa-calendar-day me-1"></i>' . $daysCount . ($daysCount == 1 ? ' Day' : ' Days') . '</small>';

                                            // Update and Cancel authorization flags
                                            $canUpdate = in_array($row['status'], ['Pending', 'Approved']) && $row['fromDate'] >= $today;
                                            $canCancel = !in_array($row['status'], ['Rejected', 'Cancelled']) && $row['toDate'] >= $today;

                                            $jsData = htmlspecialchars(json_encode([
                                                'id'       => $row['id'],
                                                'fromDate' => $row['fromDate'],
                                                'toDate'   => $row['toDate'],
                                                'reason'   => $row['reason'],
                                            ]), ENT_QUOTES);

                                            $approver = ($row['status'] === 'Approved' && !empty($row['approverName'])) ? '<span class="text-dark fw-bold"><i class="fa-solid fa-user-check me-1 text-success"></i> ' . htmlspecialchars($row['approverName']) . '</span>' : '<span class="text-muted">—</span>';
                                            
                                            $reasonNote = '—';
                                            if ($row['status'] == 'Rejected' && !empty($row['rejectionReason'])) {
                                                $reasonNote = '<span class="text-danger fw-bold">' . htmlspecialchars($row['rejectionReason']) . '</span>';
                                            } elseif ($row['status'] == 'Cancelled' && !empty($row['cancelReason'])) {
                                                $reasonNote = '<span class="text-secondary">' . htmlspecialchars($row['cancelReason']) . '</span>';
                                            }

                                            $appliedOn = !empty($row['dateTime']) ? date('d M Y, h:i A', strtotime($row['dateTime'])) : '-';
                                            ?>
                                            <tr>
                                                <td class="fw-bold text-secondary"><?= $sr++ ?></td>
                                                <td style="max-width:200px; word-break:break-word;"><?= $projectsFormatted ?></td>
                                                <td style="max-width:180px; word-break:break-word;"><?= htmlspecialchars($row['reason'] ?? '') ?></td>
                                                <td><?= $durationHtml ?></td>
                                                <td><?= $statusBadge ?></td>
                                                <td><?= $approver ?></td>
                                                <td style="max-width:160px; word-break:break-word;"><?= $reasonNote ?></td>
                                                <td class="text-muted" style="font-size:0.78rem;"><?= $appliedOn ?></td>
                                                <td>
                                                    <div class="d-flex align-items-center gap-1">
                                                        <?php if ($canUpdate): ?>
                                                            <button class="btn btn-primary btn-sm px-2 py-1 updateBtn" data-row="<?= $jsData ?>" title="Update Tour Dates">
                                                                <i class="fa-solid fa-pen-to-square"></i> Edit
                                                            </button>
                                                        <?php endif; ?>
                                                        <?php if ($canCancel): ?>
                                                            <button class="btn btn-outline-danger btn-sm px-2 py-1 cancelBtn" data-id="<?= $row['id'] ?>" title="Cancel Tour">
                                                                <i class="fa-solid fa-ban"></i> Cancel
                                                            </button>
                                                        <?php endif; ?>
                                                        <?php if (!$canUpdate && !$canCancel): ?>
                                                            <span class="text-muted" style="font-size:0.8rem;">—</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php
                                        }
                                        $stmt->close();
                                        ?>
                                    </tbody>
                                </table>
                            </div>

                        </div>
                    </div>

                </div> <!-- Content Wrapper -->
            </div> <!-- Main Panel -->

        </div>
    </div>

    <!-- ── UPDATE TOUR MODAL ── -->
    <div class="modal fade" id="updateModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content" style="border-radius:12px; border:none; box-shadow: 0 10px 30px rgba(0,0,0,0.15);">
                <form method="POST" action="display_tour.php">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="tour_id" id="update_tour_id">
                    
                    <div class="modal-header border-bottom-0 pb-0">
                        <h5 class="modal-title fw-bold text-dark">
                            <i class="fa-solid fa-pen-to-square text-primary me-2"></i> Update Tour Details
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" data-dismiss="modal" aria-label="Close"></button>
                    </div>
                    
                    <div class="modal-body p-4">
                        <div class="form-group mb-3">
                            <label for="update_from_date" class="fw-bold text-secondary mb-1">From Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="from_date" id="update_from_date" min="<?= $today ?>" required>
                        </div>
                        <div class="form-group mb-3">
                            <label for="update_to_date" class="fw-bold text-secondary mb-1">To Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="to_date" id="update_to_date" min="<?= $today ?>" required>
                        </div>
                        <div class="form-group mb-0">
                            <label for="update_reason" class="fw-bold text-secondary mb-1">Reason <span class="text-danger">*</span></label>
                            <textarea class="form-control" name="reason" id="update_reason" rows="3" required></textarea>
                        </div>
                    </div>
                    
                    <div class="modal-footer border-top-0 pt-0">
                        <button type="button" class="btn btn-light px-3" data-bs-dismiss="modal" data-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary px-4" style="border-radius:8px; font-weight:600;">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ── CANCEL TOUR MODAL ── -->
    <div class="modal fade" id="cancelModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content" style="border-radius:12px; border:none; box-shadow: 0 10px 30px rgba(0,0,0,0.15);">
                <form method="POST" action="display_tour.php">
                    <input type="hidden" name="action" value="cancel">
                    <input type="hidden" name="tour_id" id="cancel_tour_id">
                    
                    <div class="modal-header border-bottom-0 pb-0">
                        <h5 class="modal-title fw-bold text-dark">
                            <i class="fa-solid fa-ban text-danger me-2"></i> Cancel Tour Application
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" data-dismiss="modal" aria-label="Close"></button>
                    </div>
                    
                    <div class="modal-body p-4">
                        <div class="form-group mb-0">
                            <label for="cancel_reason" class="fw-bold text-secondary mb-1">Reason for Cancellation <span class="text-danger">*</span></label>
                            <textarea class="form-control" name="cancel_reason" id="cancel_reason" rows="3" placeholder="State reason for cancelling this tour application..." required></textarea>
                        </div>
                    </div>
                    
                    <div class="modal-footer border-top-0 pt-0">
                        <button type="button" class="btn btn-light px-3" data-bs-dismiss="modal" data-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-danger px-4" style="border-radius:8px; font-weight:600;">Confirm Cancellation</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Vendors & Scripts -->
    <script src="../vendors/js/vendor.bundle.base.js"></script>
    <script src="../js/off-canvas.js"></script>
    <script src="../js/hoverable-collapse.js"></script>
    <script src="../js/template.js"></script>
    <script src="../js/settings.js"></script>

    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.17.0/xlsx.full.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.4.0/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.23/jspdf.plugin.autotable.min.js"></script>

    <script>
        $(document).ready(function () {
            $('#tourTable').DataTable({
                responsive: true,
                order: [],
                language: {
                    search: "Filter Applications:",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries",
                    emptyTable: "No tour applications recorded"
                }
            });

            // Update button click
            $(document).on('click', '.updateBtn', function () {
                const data = $(this).data('row');
                $('#update_tour_id').val(data.id);
                $('#update_from_date').val(data.fromDate);
                $('#update_to_date').val(data.toDate).attr('min', data.fromDate);
                $('#update_reason').val(data.reason);
                $('#updateModal').modal('show');
            });

            $('#update_from_date').on('change', function () {
                const fd = $(this).val();
                $('#update_to_date').attr('min', fd);
                if ($('#update_to_date').val() < fd) {
                    $('#update_to_date').val(fd);
                }
            });

            // Cancel button click
            $(document).on('click', '.cancelBtn', function () {
                const id = $(this).data('id');
                $('#cancel_tour_id').val(id);
                $('#cancel_reason').val('');
                $('#cancelModal').modal('show');
            });
        });

        function exportTableToExcel() {
            var tableClone = document.createElement("table");
            var headerRow = tableClone.insertRow();
            var headers = ["Project(s)", "Reason", "Duration", "Status", "Approved By", "Rejection/Cancel Reason", "Applied On"];
            headers.forEach(h => {
                var th = document.createElement("th");
                th.innerText = h;
                headerRow.appendChild(th);
            });

            var table = $('#tourTable').DataTable();
            table.rows({ filter: 'applied' }).nodes().each(function(node) {
                var cells = $(node).find('td');
                if (cells.length >= 8) {
                    var tr = tableClone.insertRow();
                    tr.innerHTML = `
                        <td>${$(cells[1]).text().trim()}</td>
                        <td>${$(cells[2]).text().trim()}</td>
                        <td>${$(cells[3]).text().trim()}</td>
                        <td>${$(cells[4]).text().trim()}</td>
                        <td>${$(cells[5]).text().trim()}</td>
                        <td>${$(cells[6]).text().trim()}</td>
                        <td>${$(cells[7]).text().trim()}</td>
                    `;
                }
            });

            var workbook = XLSX.utils.table_to_book(tableClone, { sheet: "Tour Applications Report" });
            XLSX.writeFile(workbook, 'My_Tour_Applications.xlsx');
        }

        async function exportTableToPDF() {
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF('landscape');
            doc.setFontSize(14);
            doc.setTextColor(75, 73, 172);
            doc.text('My Tour Applications Report', 14, 15);

            const tableData = [];
            var table = $('#tourTable').DataTable();
            table.rows({ filter: 'applied' }).nodes().each(function(node) {
                var cells = $(node).find('td');
                if (cells.length >= 8) {
                    tableData.push([
                        $(cells[1]).text().trim().replace(/\s+/g, ' '),
                        $(cells[2]).text().trim().replace(/\s+/g, ' '),
                        $(cells[3]).text().trim().replace(/\s+/g, ' '),
                        $(cells[4]).text().trim().replace(/\s+/g, ' '),
                        $(cells[5]).text().trim().replace(/\s+/g, ' '),
                        $(cells[6]).text().trim().replace(/\s+/g, ' '),
                        $(cells[7]).text().trim().replace(/\s+/g, ' ')
                    ]);
                }
            });

            doc.autoTable({
                head: [["Project(s)", "Reason", "Duration", "Status", "Approved By", "Rejection/Cancel Reason", "Applied On"]],
                body: tableData,
                startY: 22,
                headStyles: { fillColor: [75, 73, 172], fontSize: 8, halign: 'center' },
                bodyStyles: { fontSize: 8, halign: 'center' },
                columnStyles: { 0:{halign:'left'}, 1:{halign:'left'}, 5:{halign:'left'} },
                alternateRowStyles: { fillColor: [248, 249, 251] },
                styles: { cellPadding: 2.5 }
            });

            doc.save('My_Tour_Applications.pdf');
        }
    </script>

    <?php
    if (isset($_SESSION['status'])) {
        toastMsg($_SESSION['status'] ? 'success' : 'error', $_SESSION['msg']);
        unset($_SESSION['status'], $_SESSION['msg']);
    }
    ?>
</body>
</html>