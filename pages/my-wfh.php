<?php
session_start();
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}

include('../include/db_config.php');

$currentUserIdStr = mysqli_real_escape_string($con, $_SESSION['userId']);

// Calculate KPI Analytics for the logged-in user
$kpiQ = mysqli_query($con, "
    SELECT 
        COUNT(*) AS totalWfh,
        SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) AS approvedWfh,
        SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) AS pendingWfh,
        SUM(CASE WHEN status = 'Approved' THEN (DATEDIFF(COALESCE(toDate, wfhDate, fromDate), COALESCE(fromDate, wfhDate, toDate)) + 1) ELSE 0 END) AS approvedDays
    FROM wfhRequests 
    WHERE userid = '$currentUserIdStr'
");
$kpi = $kpiQ ? mysqli_fetch_assoc($kpiQ) : [];
$totalWfh    = (int)($kpi['totalWfh'] ?? 0);
$approvedWfh = (int)($kpi['approvedWfh'] ?? 0);
$pendingWfh  = (int)($kpi['pendingWfh'] ?? 0);
$approvedDays = (int)($kpi['approvedDays'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>My WFH Requests</title>
    <?php include('../partials/_links.php'); ?>
    <style>
        .card { border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 2px 10px rgba(0,0,0,0.03); }
        .card-title { font-size: 1.15rem; font-weight: 700; color: #1e293b; }
        .form-control { border-radius: 8px; border: 1px solid #cbd5e1; padding: 10px 14px; font-size: 0.88rem; }
        
        /* Custom Select Dropdown Styling */
        select.form-control, select.form-select {
            height: 42px !important;
            padding: 8px 36px 8px 14px !important;
            border-radius: 8px !important;
            border: 1px solid #cbd5e1 !important;
            background-color: #ffffff !important;
            color: #1e293b !important;
            font-size: 0.88rem !important;
            font-weight: 500 !important;
            appearance: none !important;
            -webkit-appearance: none !important;
            -moz-appearance: none !important;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%3c475569' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m2 5 6 6 6-6'/%3e%3c/svg%3e") !important;
            background-repeat: no-repeat !important;
            background-position: right 14px center !important;
            background-size: 14px 10px !important;
            cursor: pointer;
        }

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

        #myWfhTable thead th {
            background: #f8fafc; color: #475569; font-weight: 700;
            font-size: 0.78rem; text-transform: uppercase; border-bottom: 2px solid #e2e8f0;
        }

        /* Document Overlay */
        .doc-preview-overlay {
            position: fixed; inset: 0; background: rgba(0,0,0,0.8);
            display: flex; justify-content: center; align-items: center; z-index: 99999;
        }
        .doc-preview-content {
            position: relative; width: 85%; height: 85%; background: #fff; border-radius: 12px;
            padding: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        .doc-preview-close {
            position: absolute; top: -12px; right: -12px; background: #ef4444; color: #fff;
            border: none; border-radius: 50%; width: 32px; height: 32px; font-size: 16px;
            cursor: pointer; display: flex; align-items: center; justify-content: center;
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.4.1/css/responsive.bootstrap4.min.css">
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
                                <i class="fa-solid fa-house-laptop text-primary me-2"></i> My WFH Requests
                            </h3>
                            <p class="text-muted small mb-0">Track Work From Home application statuses, date ranges, and approval remarks.</p>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <a href="apply-wfh.php" class="btn btn-primary px-3 py-2" style="border-radius:8px; font-weight:600;">
                                <i class="fa-solid fa-plus me-1"></i> Apply WFH
                            </a>
                        </div>
                    </div>

                    <!-- ── 1. KPI Analytics Summary Dashboard ── -->
                    <div class="row mb-4">
                        <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-lbl">Total Applications</div>
                                    <div class="kpi-val text-primary"><?= $totalWfh ?></div>
                                    <small class="text-muted" style="font-size:0.72rem;">All-time requests</small>
                                </div>
                                <div class="kpi-icon" style="background:#EEEDFE; color:#4b49ac;">
                                    <i class="fa-solid fa-laptop-code"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-lbl">Approved WFH</div>
                                    <div class="kpi-val text-success"><?= $approvedWfh ?></div>
                                    <small class="text-muted" style="font-size:0.72rem;">Granted requests</small>
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
                                    <div class="kpi-val text-warning" style="color:#b06000 !important;"><?= $pendingWfh ?></div>
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
                                    <div class="kpi-lbl">Approved WFH Days</div>
                                    <div class="kpi-val text-info" style="color:#0284C7 !important;"><?= $approvedDays ?> <span style="font-size:0.9rem;">days</span></div>
                                    <small class="text-muted" style="font-size:0.72rem;">Total remote work days</small>
                                </div>
                                <div class="kpi-icon" style="background:#E0F2FE; color:#0284C7;">
                                    <i class="fa-solid fa-house-user"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── 2. WFH Applications Table Section ── -->
                    <div class="card">
                        <div class="card-body p-4">
                            <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                                <div class="d-flex align-items-center gap-3">
                                    <h4 class="card-title mb-0">
                                        <i class="fa-solid fa-list-check text-primary me-2"></i> WFH Application Directory
                                    </h4>
                                    <!-- Status Filter -->
                                    <div class="d-flex align-items-center gap-2">
                                        <label for="statusFilter" class="text-muted fw-bold mb-0" style="font-size:0.8rem;">Status Filter:</label>
                                        <select id="statusFilter" class="form-control" style="width:160px;">
                                            <option value="">All Statuses</option>
                                            <option value="Approved">Approved</option>
                                            <option value="Pending">Pending</option>
                                            <option value="Rejected">Rejected</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="d-flex align-items-center gap-2">
                                    <button id="exportExcel" class="btn btn-success text-white btn-sm px-3 py-2" style="border-radius:6px;" title="Export to Excel" onclick="exportTableToExcel()">
                                        <i class="fa-solid fa-file-excel me-1"></i> Excel
                                    </button>
                                    <button id="downloadPdf" class="btn btn-danger text-white btn-sm px-3 py-2" style="border-radius:6px;" title="Download as PDF" onclick="exportTableToPDF()">
                                        <i class="fa-solid fa-file-pdf me-1"></i> PDF
                                    </button>
                                </div>
                            </div>
                            <hr class="text-muted opacity-25 mb-4">

                            <div class="table-responsive">
                                <table class="table table-hover align-middle w-100" id="myWfhTable" style="font-size:0.85rem;">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>WFH Duration</th>
                                            <th>Reason / Notes</th>
                                            <th>Status</th>
                                            <th>Rejection Reason</th>
                                            <th>Document Attachment</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        // Detect column names in wfhRequests
                                        $columnsResult = mysqli_query($con, "SHOW COLUMNS FROM wfhRequests");
                                        $existingColumns = [];
                                        if ($columnsResult) {
                                            while ($colRow = mysqli_fetch_assoc($columnsResult)) {
                                                $existingColumns[] = strtolower($colRow['Field']);
                                            }
                                        }

                                        $hasFromDate = in_array('fromdate', $existingColumns);
                                        $hasToDate   = in_array('todate', $existingColumns);
                                        $hasWfhDate  = in_array('wfhdate', $existingColumns);
                                        $hasFromDateUnderscore = in_array('from_date', $existingColumns);
                                        $hasToDateUnderscore   = in_array('to_date', $existingColumns);
                                        $hasRejectionReason    = in_array('rejectionreason', $existingColumns);

                                        $fromDateExpr = "NULL";
                                        if ($hasFromDate) {
                                            $fromDateExpr = $hasWfhDate ? "COALESCE(w.fromDate, w.wfhDate)" : "w.fromDate";
                                        } elseif ($hasFromDateUnderscore) {
                                            $fromDateExpr = $hasWfhDate ? "COALESCE(w.from_date, w.wfhDate)" : "w.from_date";
                                        } elseif ($hasWfhDate) {
                                            $fromDateExpr = "w.wfhDate";
                                        }

                                        $toDateExpr = "NULL";
                                        if ($hasToDate) {
                                            $toDateExpr = $hasWfhDate ? "COALESCE(w.toDate, w.wfhDate)" : "w.toDate";
                                        } elseif ($hasToDateUnderscore) {
                                            $toDateExpr = $hasWfhDate ? "COALESCE(w.to_date, w.wfhDate)" : "w.to_date";
                                        } elseif ($hasWfhDate) {
                                            $toDateExpr = "w.wfhDate";
                                        }

                                        $rejectionReasonExpr = "NULL";
                                        if ($hasRejectionReason) {
                                            $rejectionReasonExpr = "w.rejectionReason";
                                        } elseif (in_array('rejection_reason', $existingColumns)) {
                                            $rejectionReasonExpr = "w.rejection_reason";
                                        }

                                        $processedQuery = "
                                            SELECT w.wfh_id, 
                                                   $fromDateExpr AS fromDate,
                                                   $toDateExpr AS toDate,
                                                   w.status, w.reason,
                                                   w.updatedDate, 
                                                   $rejectionReasonExpr AS rejectionReason,
                                                   w.documentPath, 
                                                   w.userid,
                                                   s.fullName
                                            FROM wfhRequests w
                                            LEFT JOIN signup s ON s.userId = w.userid
                                            WHERE w.userid = '$currentUserIdStr'
                                            ORDER BY COALESCE(w.updatedDate, w.fromDate, w.wfhDate) DESC
                                        ";
                                        $processedResult = mysqli_query($con, $processedQuery);
                                        $sr = 1;

                                        if ($processedResult && mysqli_num_rows($processedResult) > 0) {
                                            while ($row = mysqli_fetch_assoc($processedResult)) {
                                                $st = $row['status'] ?? 'Pending';
                                                if ($st === 'Approved') {
                                                    $statusBadge = '<span class="badge badge-approved px-2 py-1"><i class="fa-solid fa-check me-1"></i> Approved</span>';
                                                } elseif ($st === 'Rejected') {
                                                    $statusBadge = '<span class="badge badge-rejected px-2 py-1"><i class="fa-solid fa-xmark me-1"></i> Rejected</span>';
                                                } else {
                                                    $statusBadge = '<span class="badge badge-pending px-2 py-1"><i class="fa-solid fa-clock me-1"></i> Pending</span>';
                                                }

                                                $fromVal = !empty($row['fromDate']) ? $row['fromDate'] : ($row['toDate'] ?? '');
                                                $toVal   = !empty($row['toDate'])   ? $row['toDate']   : $fromVal;

                                                $fromDateStr = !empty($fromVal) ? date('d M Y', strtotime($fromVal)) : '-';
                                                $toDateStr   = !empty($toVal)   ? date('d M Y', strtotime($toVal))   : '-';

                                                $fromTs = strtotime($fromVal);
                                                $toTs   = strtotime($toVal);
                                                $daysCount = ($fromTs && $toTs) ? (floor(($toTs - $fromTs) / 86400) + 1) : 1;

                                                $durationHtml = '<div><strong class="text-dark">' . $fromDateStr . '</strong> to <strong class="text-dark">' . $toDateStr . '</strong></div>';
                                                $durationHtml .= '<small class="text-primary fw-bold" style="font-size:0.75rem;"><i class="fa-solid fa-calendar-day me-1"></i>' . $daysCount . ($daysCount == 1 ? ' Day' : ' Days') . '</small>';

                                                $rejectionText = ($st === 'Rejected' && !empty($row['rejectionReason']))
                                                    ? '<span class="text-danger fw-bold">' . htmlspecialchars($row['rejectionReason']) . '</span>'
                                                    : '<span class="text-muted" style="font-style:italic;">—</span>';

                                                $documentBtn = '<span class="text-muted" style="font-style:italic;">—</span>';
                                                if (!empty($row['documentPath']) && $row['documentPath'] != '-') {
                                                    $filePath = htmlspecialchars($row['documentPath'], ENT_QUOTES);
                                                    $documentBtn = "<button onclick=\"viewDocument('$filePath')\" class='btn btn-outline-info btn-sm px-2 py-1' style='border-radius:6px;'><i class='fa-solid fa-file-medical me-1'></i> View Attachment</button>";
                                                }
                                        ?>
                                                <tr>
                                                    <td class="fw-bold text-secondary"><?= $sr++ ?></td>
                                                    <td><?= $durationHtml ?></td>
                                                    <td style="max-width:250px; word-break:break-word;"><?= htmlspecialchars($row['reason'] ?? '—') ?></td>
                                                    <td><?= $statusBadge ?></td>
                                                    <td style="max-width:200px; word-break:break-word;"><?= $rejectionText ?></td>
                                                    <td><?= $documentBtn ?></td>
                                                </tr>
                                        <?php 
                                            }
                                        }
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

    <!-- Scripts -->
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
        $(document).ready(function() {
            var table = $('#myWfhTable').DataTable({
                responsive: true,
                order: [],
                pageLength: 10,
                lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "All"]],
                language: {
                    search: "Filter Requests:",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries",
                    emptyTable: "No Work From Home requests recorded"
                }
            });

            $('#statusFilter').on('change', function() {
                var val = $(this).val();
                table.column(3).search(val).draw();
            });
        });

        function viewDocument(url) {
            const overlay = document.createElement('div');
            overlay.className = 'doc-preview-overlay';
            const content = document.createElement('div');
            content.className = 'doc-preview-content';
            const closeBtn = document.createElement('button');
            closeBtn.className = 'doc-preview-close';
            closeBtn.innerHTML = '✕';
            closeBtn.onclick = () => document.body.removeChild(overlay);

            if (url.toLowerCase().endsWith('.pdf')) {
                const iframe = document.createElement('iframe');
                iframe.src = url;
                iframe.style.cssText = 'width:100%; height:100%; border:none; border-radius:8px;';
                content.appendChild(closeBtn);
                content.appendChild(iframe);
            } else {
                const img = document.createElement('img');
                img.src = url;
                img.style.cssText = 'max-width:100%; max-height:100%; display:block; margin:auto; border-radius:8px;';
                content.appendChild(closeBtn);
                content.appendChild(img);
            }

            overlay.onclick = (e) => { if (e.target === overlay) document.body.removeChild(overlay); };
            overlay.appendChild(content);
            document.body.appendChild(overlay);
        }

        function exportTableToExcel() {
            var tableClone = document.createElement("table");
            var headerRow = tableClone.insertRow();
            var headers = ["Duration", "Reason", "Status", "Rejection Reason"];
            headers.forEach(h => {
                var th = document.createElement("th");
                th.innerText = h;
                headerRow.appendChild(th);
            });

            var table = $('#myWfhTable').DataTable();
            table.rows().data().each(function(row) {
                var tr = tableClone.insertRow();
                tr.innerHTML = `
                    <td>${$(row[1]).text().trim()}</td>
                    <td>${$(row[2]).text().trim()}</td>
                    <td>${$(row[3]).text().trim()}</td>
                    <td>${$(row[4]).text().trim()}</td>
                `;
            });

            var workbook = XLSX.utils.table_to_book(tableClone, { sheet: "My WFH Requests" });
            XLSX.writeFile(workbook, 'My_WFH_Requests.xlsx');
        }

        async function exportTableToPDF() {
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF('portrait');
            doc.text('My WFH Requests Report', 14, 15);

            const tableData = [];
            var table = $('#myWfhTable').DataTable();
            table.rows().data().each(function(row) {
                tableData.push([
                    $(row[1]).text().trim(),
                    $(row[2]).text().trim(),
                    $(row[3]).text().trim(),
                    $(row[4]).text().trim()
                ]);
            });

            doc.autoTable({
                head: [["Duration", "Reason", "Status", "Rejection Reason"]],
                body: tableData,
                startY: 25,
            });

            doc.save('My_WFH_Requests.pdf');
        }
    </script>
</body>
</html>