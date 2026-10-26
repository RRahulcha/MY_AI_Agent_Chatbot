<?php
session_start();
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', 0);

if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}

include('../include/db_config.php');

$userId = $_SESSION['userId'];

// Fetch user details for banner
$userRes = mysqli_query($con, "SELECT fullName FROM signup WHERE userId = '" . mysqli_real_escape_string($con, $userId) . "' LIMIT 1");
$userRow = mysqli_fetch_assoc($userRes);
$userName = htmlspecialchars($userRow['fullName'] ?? 'User');

// Handle POST Request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = mysqli_real_escape_string($con, $_POST['type']);
    $module = mysqli_real_escape_string($con, $_POST['module']);
    $description = mysqli_real_escape_string($con, $_POST['description']);
    $createdAt = date('Y-m-d H:i:s');

    $attachmentPath = null;
    $uploadOk = 1;

    // File Upload Logic
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] == 0) {
        $targetDir = "../uploads/issues/";
        if (!file_exists($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        $fileName = basename($_FILES["attachment"]["name"]);
        $fileType = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        $allowTypes = array('jpg', 'png', 'jpeg', 'gif', 'pdf');
        if (in_array($fileType, $allowTypes)) {
            $newFileName = uniqid() . '.' . $fileType;
            $targetFilePath = $targetDir . $newFileName;

            if (move_uploaded_file($_FILES["attachment"]["tmp_name"], $targetFilePath)) {
                $attachmentPath = $newFileName;
            } else {
                $_SESSION['msgType'] = "error";
                $_SESSION['message'] = "Sorry, there was an error uploading your file.";
                $uploadOk = 0;
            }
        } else {
            $_SESSION['msgType'] = "error";
            $_SESSION['message'] = "Sorry, only JPG, JPEG, PNG, GIF & PDF files are allowed.";
            $uploadOk = 0;
        }
    }

    if ($uploadOk == 1) {
        $attachmentSql = $attachmentPath ? "'$attachmentPath'" : "NULL";

        $sql = "INSERT INTO feedbackIssues (userId, type, module, description, attachment, createdAt) VALUES ('$userId', '$type', '$module', '$description', $attachmentSql, '$createdAt')";

        if (mysqli_query($con, $sql)) {
            $_SESSION['message'] = "Your " . htmlspecialchars($type) . " has been submitted successfully!";
            $_SESSION['msgType'] = "success";
        } else {
            $_SESSION['message'] = "Error: " . mysqli_error($con);
            $_SESSION['msgType'] = "error";
        }
    }

    // PRG Pattern
    header("Location: feedback.php");
    exit();
}

// Retrieve message from session if exists
$message = "";
$msgType = "";
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $msgType = $_SESSION['msgType'];
    unset($_SESSION['message'], $_SESSION['msgType']);
}

// Fetch analytics for user
$analyticsQ = mysqli_query($con, "
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN LOWER(status) = 'closed' OR LOWER(status) = 'resolved' THEN 1 ELSE 0 END) as resolved,
        SUM(CASE WHEN LOWER(status) != 'closed' AND LOWER(status) != 'resolved' AND status IS NOT NULL THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN LOWER(type) = 'feedback' THEN 1 ELSE 0 END) as feedbackCount,
        SUM(CASE WHEN LOWER(type) = 'issue' THEN 1 ELSE 0 END) as issueCount
    FROM feedbackIssues 
    WHERE userId = '" . mysqli_real_escape_string($con, $userId) . "'
");
$stats = mysqli_fetch_assoc($analyticsQ) ?? [];

$totalSubmissions = (int)($stats['total'] ?? 0);
$resolvedCount    = (int)($stats['resolved'] ?? 0);
$pendingCount     = (int)($stats['pending'] ?? 0);
$feedbackCount    = (int)($stats['feedbackCount'] ?? 0);
$issueCount       = (int)($stats['issueCount'] ?? 0);

// Parse description helper function
function parseDescription($text)
{
    $result = [
        'description' => '',
        'remark'      => '',
        'deadline'    => '',
        'attachment'  => ''
    ];

    if (!$text) return $result;

    if (preg_match('/Attachment:\s*(.+)$/m', $text, $m)) {
        $result['attachment'] = trim($m[1]);
    }

    $parts = preg_split('/---(REMARK|DEADLINE)---/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    $result['description'] = trim($parts[0]);

    for ($i = 1; $i < count($parts); $i += 2) {
        $key = $parts[$i];
        $value = trim($parts[$i + 1] ?? '');

        if ($key === 'REMARK') {
            $result['remark'] = trim(preg_replace('/Attachment:\s*.+$/m', '', $value));
        }
        if ($key === 'DEADLINE') {
            $result['deadline'] = $value;
        }
    }

    return $result;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <title>Feedback &amp; Issues</title>
    <?php include('../partials/_links.php'); ?>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap4.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        body { background-color: #f4f6fb !important; }

        /* ── Header Banner ────────────────────────────────────────── */
        .page-header-box {
            background: #fff;
            border-radius: 12px;
            padding: 20px 24px;
            border: 1px solid #e2e8f0;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
        }

        /* ── KPI Analytics Cards ────────────────────────────────── */
        .kpi-card {
            background: #fff;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            padding: 16px 20px;
            transition: all 0.2s ease;
            height: 100%;
            box-shadow: 0 2px 6px rgba(0,0,0,0.03);
        }
        .kpi-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 18px rgba(0,0,0,0.07);
        }
        .kpi-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
            flex-shrink: 0;
        }
        .kpi-val {
            font-size: 1.6rem;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.1;
            margin-top: 4px;
        }
        .kpi-lbl {
            font-size: 0.72rem;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .kpi-sub {
            font-size: 0.72rem;
            color: #94a3b8;
            margin-top: 3px;
        }

        /* ── Form Card ────────────────────────────────────────── */
        .form-card {
            background: #fff;
            border-radius: 14px;
            border: 2px solid #4b49ac;
            padding: 24px;
            box-shadow: 0 4px 14px rgba(75, 73, 172, 0.08);
            margin-bottom: 24px;
        }
        .form-control {
            border-radius: 8px !important;
            border: 1px solid #cbd5e1 !important;
            font-size: 0.85rem !important;
            padding: 10px 14px !important;
        }
        .form-control:focus {
            border-color: #4b49ac !important;
            box-shadow: 0 0 0 3px rgba(75, 73, 172, 0.15) !important;
        }

        /* ── Badges ────────────────────────────────────────────── */
        .badge-type-feedback { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; font-weight: 600; }
        .badge-type-issue    { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; font-weight: 600; }
        .badge-status-closed { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; font-weight: 600; }
        .badge-status-open   { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; font-weight: 600; }

        /* ── Table Styling ──────────────────────────────────────── */
        .card-table {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            background: #fff;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
        }
        .table thead th {
            background-color: #f8fafc !important;
            color: #475569 !important;
            font-size: 0.74rem !important;
            font-weight: 700 !important;
            text-transform: uppercase !important;
            letter-spacing: 0.04em !important;
            padding: 12px 14px !important;
            border-bottom: 2px solid #e2e8f0 !important;
        }
        .table tbody td {
            vertical-align: middle !important;
            font-size: 0.84rem !important;
            padding: 12px 14px !important;
            border-bottom: 1px solid #f1f5f9 !important;
            color: #334155;
        }
        .table tbody tr:hover { background-color: #f8fafc !important; }

        .btn-doc {
            background: #e0e7ff;
            color: #4338ca;
            border: 1px solid #c7d2fe;
            border-radius: 6px;
            padding: 4px 10px;
            font-size: 0.75rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.15s;
        }
        .btn-doc:hover {
            background: #4338ca;
            color: #fff;
            text-decoration: none;
        }
    </style>
</head>

<body>
    <div class="container-scroller">
        <?php include('../partials/_navbar.php'); ?>
        <div class="container-fluid page-body-wrapper">
            <?php include('../partials/_sidebar.php'); ?>

            <div class="main-panel">
                <div class="content-wrapper" style="background:#f4f6fb !important;">

                    <!-- ── Header Banner ────────────────────────────────────────────────── -->
                    <div class="page-header-box d-flex align-items-center justify-content-between flex-wrap gap-2">
                        <div>
                            <h3 class="fw-bold text-dark mb-1" style="font-size:1.35rem;">
                                <i class="fa-solid fa-comment-dots text-primary me-2"></i> Feedback &amp; Issue Tracking
                            </h3>
                            <p class="text-muted small mb-0">Report system issues, bugs, or share constructive suggestions with the development team.</p>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <button type="button" class="btn btn-primary" id="toggleFormBtn" onclick="toggleForm()" style="background:#4b49ac; border-color:#4b49ac; border-radius:8px; padding:8px 18px; font-size:0.85rem; font-weight:600;">
                                <i class="fa-solid fa-plus me-1" id="toggleFormIcon"></i> <span id="toggleFormText">New Submission</span>
                            </button>
                        </div>
                    </div>

                    <!-- ── Flash Alert ──────────────────────────────────────────────────── -->
                    <?php if (!empty($message)): ?>
                        <div class="alert alert-<?= ($msgType === 'success') ? 'success' : 'danger'; ?> alert-dismissible fade show mb-4" role="alert" style="border-radius:10px;">
                            <i class="fa-solid <?= ($msgType === 'success') ? 'fa-circle-check' : 'fa-circle-exclamation'; ?> me-2"></i>
                            <?= $message; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <!-- ── KPI Analytics Summary ────────────────────────────────────────── -->
                    <div class="row mb-4 g-3">
                        <div class="col-md-3 col-sm-6">
                            <div class="kpi-card">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div class="kpi-lbl">Total Submissions</div>
                                    <div class="kpi-icon" style="background:#EEF2FF; color:#4B49AC;">
                                        <i class="fa-solid fa-folder-open"></i>
                                    </div>
                                </div>
                                <div class="kpi-val"><?= $totalSubmissions ?></div>
                                <div class="kpi-sub">Feedback &amp; issues</div>
                            </div>
                        </div>

                        <div class="col-md-3 col-sm-6">
                            <div class="kpi-card">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div class="kpi-lbl">Open / Pending</div>
                                    <div class="kpi-icon" style="background:#FEF7E0; color:#B06000;">
                                        <i class="fa-solid fa-clock"></i>
                                    </div>
                                </div>
                                <div class="kpi-val" style="color:#B06000;"><?= $pendingCount ?></div>
                                <div class="kpi-sub">Under review</div>
                            </div>
                        </div>

                        <div class="col-md-3 col-sm-6">
                            <div class="kpi-card">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div class="kpi-lbl">Resolved / Closed</div>
                                    <div class="kpi-icon" style="background:#E6F4EA; color:#1E8E3E;">
                                        <i class="fa-solid fa-circle-check"></i>
                                    </div>
                                </div>
                                <div class="kpi-val text-success"><?= $resolvedCount ?></div>
                                <div class="kpi-sub">Addressed by team</div>
                            </div>
                        </div>

                        <div class="col-md-3 col-sm-6">
                            <div class="kpi-card">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div class="kpi-lbl">Ratio (Bug / Feedback)</div>
                                    <div class="kpi-icon" style="background:#E0F2FE; color:#0284C7;">
                                        <i class="fa-solid fa-bug"></i>
                                    </div>
                                </div>
                                <div class="kpi-val" style="color:#0284C7;"><?= $issueCount ?> / <?= $feedbackCount ?></div>
                                <div class="kpi-sub"><?= $issueCount ?> issues, <?= $feedbackCount ?> feedback</div>
                            </div>
                        </div>
                    </div>

                    <!-- ── Submission Form Card (Collapsible) ────────────────────────────── -->
                    <div id="feedbackFormBox" class="form-card" style="display:none;">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h4 class="fw-bold text-primary mb-0" style="font-size:1.15rem;">
                                <i class="fa-solid fa-paper-plane me-2"></i> Submit Feedback or Report Issue
                            </h4>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleForm()">
                                <i class="fa-solid fa-chevron-up me-1"></i> Close
                            </button>
                        </div>
                        <p class="text-muted small mb-4">Please fill in details below to help us improve the platform.</p>

                        <form class="forms-sample" method="POST" enctype="multipart/form-data">
                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small" for="type">Type <span class="text-danger">*</span></label>
                                    <select class="form-select form-control" id="type" name="type" required>
                                        <option value="feedback">Feedback / Suggestion</option>
                                        <option value="issue">Issue / Bug Report</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small" for="module">Module / Page Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="module" name="module" placeholder="e.g. Dashboard, Attendance, Resource Requests" required>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold small" for="description">Detailed Description <span class="text-danger">*</span></label>
                                <textarea class="form-control" id="description" name="description" rows="4" placeholder="Explain the issue or feedback in detail..." required></textarea>
                            </div>

                            <div class="mb-4">
                                <label class="form-label fw-bold small" for="attachment">Attachment Screenshot / Document <span class="text-muted">(Optional - JPG, PNG, GIF, PDF)</span></label>
                                <input type="file" class="form-control" id="attachment" name="attachment" accept="image/*,.pdf">
                            </div>

                            <div class="d-flex justify-content-end gap-2">
                                <button type="button" class="btn btn-light" onclick="toggleForm()">Cancel</button>
                                <button type="submit" class="btn btn-primary px-4" style="background:#4b49ac; border-color:#4b49ac;">
                                    <i class="fa-solid fa-paper-plane me-1"></i> Submit Report
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- ── History Table (User's Feedback) ──────────────────────────────── -->
                    <div class="card-table">
                        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                            <div>
                                <h4 class="fw-bold mb-0 text-dark" style="font-size:1.15rem;">
                                    <i class="fa-solid fa-clock-rotate-left me-2 text-primary"></i> Your Submissions History
                                </h4>
                                <small class="text-muted">Track resolution status and developer response notes.</small>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover w-100" id="userFeedbackTable">
                                <thead>
                                    <tr>
                                        <th style="width:5%">ID</th>
                                        <th style="width:15%">Module</th>
                                        <th style="width:10%">Type</th>
                                        <th style="width:10%">Status</th>
                                        <th style="width:15%">Target Deadline</th>
                                        <th style="width:25%">Description</th>
                                        <th style="width:15%">Admin Remark</th>
                                        <th style="width:5%">Attach</th>
                                        <th style="width:10%">Submitted On</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $q = mysqli_query($con, "
                                        SELECT * FROM feedbackIssues 
                                        WHERE userId='" . mysqli_real_escape_string($con, $userId) . "'
                                        ORDER BY createdAt DESC
                                    ");

                                    if ($q) {
                                        while ($r = mysqli_fetch_assoc($q)) {
                                            $parsed = parseDescription($r['description']);
                                            $status = $r['status'] ?? 'Open';
                                            $isClosed = (strtolower($status) === 'closed' || strtolower($status) === 'resolved');

                                            $deadlineDisplay = '—';
                                            if ($parsed['deadline']) {
                                                $dt = new DateTime($parsed['deadline']);
                                                $deadlineDisplay = $dt->format('d M Y, h:i A');
                                            }

                                            $typeBadge = (strtolower($r['type']) === 'issue') ? 'badge-type-issue' : 'badge-type-feedback';
                                            $statusBadge = $isClosed ? 'badge-status-closed' : 'badge-status-open';
                                    ?>
                                    <tr>
                                        <td class="fw-bold text-dark">#<?= $r['id'] ?></td>
                                        <td><span class="fw-bold text-dark"><?= htmlspecialchars($r['module']) ?></span></td>
                                        <td>
                                            <span class="badge <?= $typeBadge ?>">
                                                <?= ucfirst(htmlspecialchars($r['type'])) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge <?= $statusBadge ?>">
                                                <?= htmlspecialchars($status) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <small class="text-muted"><?= $deadlineDisplay ?></small>
                                        </td>
                                        <td>
                                            <div class="text-muted small" style="max-width:300px; word-wrap:break-word;">
                                                <?= nl2br(htmlspecialchars($parsed['description'])) ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($parsed['remark']): ?>
                                                <div class="text-dark small" style="max-width:200px; word-wrap:break-word;">
                                                    <i class="fa-solid fa-comment-dots text-primary me-1"></i>
                                                    <?= nl2br(htmlspecialchars($parsed['remark'])) ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted small">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php 
                                            $attach = !empty($r['attachment']) ? $r['attachment'] : $parsed['attachment'];
                                            if (!empty($attach)): 
                                            ?>
                                                <a href="../uploads/issues/<?= htmlspecialchars($attach) ?>" target="_blank" class="btn-doc" title="View Attachment">
                                                    <i class="fa-solid fa-paperclip me-1"></i>View
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted small">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <small class="text-muted"><?= date('d M Y, h:i A', strtotime($r['createdAt'])) ?></small>
                                        </td>
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
                <?php include('../partials/_footer.php'); ?>
            </div>
        </div>
    </div>

    <!-- Required JS -->
    <script src="../vendors/js/vendor.bundle.base.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>

    <script src="../js/off-canvas.js"></script>
    <script src="../js/hoverable-collapse.js"></script>
    <script src="../js/template.js"></script>

    <script>
        $(document).ready(function () {
            $('#userFeedbackTable').DataTable({
                "order": [[0, "desc"]],
                "pageLength": 10,
                "language": {
                    "search": "Search feedback/issues:",
                    "emptyTable": "No feedback or issues reported yet"
                }
            });
        });

        function toggleForm() {
            const box = document.getElementById('feedbackFormBox');
            const btnText = document.getElementById('toggleFormText');
            const btnIcon = document.getElementById('toggleFormIcon');

            if (box.style.display === 'none' || box.style.display === '') {
                box.style.display = 'block';
                btnText.textContent = 'Hide Form';
                btnIcon.className = 'fa-solid fa-chevron-up me-1';
            } else {
                box.style.display = 'none';
                btnText.textContent = 'New Submission';
                btnIcon.className = 'fa-solid fa-plus me-1';
            }
        }
    </script>
</body>

</html>