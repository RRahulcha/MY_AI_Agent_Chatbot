<?php
session_start();
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    header("Location: login.php");
    exit();
}

include('../include/db_config.php');

$userId = $_SESSION['userId'];
$userRole = strtolower($_SESSION['userRole'] ?? '');
$designation = strtolower($_SESSION['designation'] ?? '');

$isHrOrAdmin = ($userRole === 'admin' || $userRole === 'hr' || $designation === 'admin' || $designation === 'hr' || $userRole === 'resourceallocator');

if (!$isHrOrAdmin) {
    echo '<script>alert("Unauthorized access."); window.location.href="login.php";</script>';
    exit();
}

$message = '';
$messageType = 'success';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type'])) {
    $targetScope = $_POST['target_scope'] ?? 'single'; // 'single', 'batch', 'type'
    $updateMode  = $_POST['update_mode']  ?? 'set';    // 'set', 'add'
    
    $cl = isset($_POST['cl_count']) ? (int)$_POST['cl_count'] : 0;
    $sl = isset($_POST['sl_count']) ? (int)$_POST['sl_count'] : 0;
    $pl = isset($_POST['pl_count']) ? (int)$_POST['pl_count'] : 0;

    $whereClause = "";

    if ($targetScope === 'single') {
        $targetUser = mysqli_real_escape_string($con, $_POST['target_user'] ?? '');
        if (!empty($targetUser)) {
            $whereClause = "WHERE userId = '$targetUser'";
        }
    } elseif ($targetScope === 'batch') {
        $targetBatch = mysqli_real_escape_string($con, $_POST['target_batch'] ?? '');
        if (!empty($targetBatch)) {
            $whereClause = "WHERE batch_number = '$targetBatch'";
        }
    } elseif ($targetScope === 'type') {
        $targetType = mysqli_real_escape_string($con, $_POST['target_type'] ?? '');
        if (!empty($targetType)) {
            if ($targetType === 'offline' || $targetType === 'online') {
                $whereClause = "WHERE LOWER(type) = '$targetType'";
            } elseif ($targetType === 'pune' || $targetType === 'kinwat') {
                $whereClause = "WHERE LOWER(office) = '$targetType' OR LOWER(branch) = '$targetType'";
            } else {
                $whereClause = "WHERE LOWER(designation) = '$targetType'";
            }
        }
    }

    if (!empty($whereClause)) {
        if ($updateMode === 'set') {
            $sql = "UPDATE signup SET CL = '$cl', SL = '$sl', PL = '$pl' $whereClause AND designation NOT IN ('admin', 'developer', 'md')";
        } else {
            $sql = "UPDATE signup SET 
                    CL = GREATEST(0, CL + ($cl)), 
                    SL = GREATEST(0, SL + ($sl)), 
                    PL = GREATEST(0, PL + ($pl)) 
                    $whereClause AND designation NOT IN ('admin', 'developer', 'md')";
        }

        if (mysqli_query($con, $sql)) {
            $affected = mysqli_affected_rows($con);
            $message = "Successfully updated leave entitlements for $affected employee(s).";
            $messageType = "success";
        } else {
            $message = "Database update error: " . mysqli_error($con);
            $messageType = "danger";
        }
    } else {
        $message = "Please select a valid target scope.";
        $messageType = "warning";
    }
}

// Fetch all employees for table display
$empListQ = mysqli_query($con, "
    SELECT userId, fullName, email, type, designation, batch_number, CL, SL, PL 
    FROM signup 
    WHERE designation NOT IN ('admin', 'developer', 'md') 
    ORDER BY fullName ASC
");

// Fetch distinct batches
$batchQ = mysqli_query($con, "SELECT DISTINCT batch_number FROM signup WHERE batch_number IS NOT NULL AND batch_number != '' ORDER BY batch_number ASC");

// Fetch distinct users
$usersQ = mysqli_query($con, "SELECT userId, fullName, type, designation FROM signup WHERE designation NOT IN ('admin', 'developer', 'md') ORDER BY fullName ASC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>HR Leave Quota Management</title>
    <?php include('../partials/_links.php'); ?>
    <style>
        .card { border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 2px 10px rgba(0,0,0,0.03); margin-bottom: 20px; }
        .card-title { font-size: 1.15rem; font-weight: 700; color: #1e293b; }
        .form-control, .form-select { border-radius: 8px; border: 1px solid #cbd5e1; padding: 10px 14px; font-size: 0.88rem; }
        .nav-pills .nav-link.active { background-color: #4F46E5 !important; color: white !important; }
        .table-responsive { max-height: 500px; overflow-y: auto; }
        .badge-quota { font-size: 0.8rem; font-weight: 600; padding: 4px 8px; border-radius: 6px; }
    </style>
</head>
<body class="sidebar-icon-only">
    <div class="container-scroller">
        <?php include('../partials/_navbar.php'); ?>
        <div class="container-fluid page-body-wrapper">
            <?php include('../partials/_sidebar.php'); ?>
            <div class="main-panel">
                <div class="content-wrapper">
                    
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div>
                            <h3 class="font-weight-bold mb-1" style="color:#0F172A;">HR Leave Quota Management</h3>
                            <p class="text-muted mb-0">Adjust and manage Casual (CL), Sick (SL), and Privilege (PL/EL) leave entitlements for employees.</p>
                        </div>
                        <a href="hrDashboard.php" class="btn btn-outline-secondary btn-sm rounded-pill px-3"><i class="bi bi-arrow-left me-1"></i> Back to HR Dashboard</a>
                    </div>

                    <?php if (!empty($message)): ?>
                        <div class="alert alert-<?= $messageType ?> alert-dismissible fade show rounded-3" role="alert">
                            <i class="bi bi-info-circle-fill me-2"></i> <?= htmlspecialchars($message) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <div class="row">
                        <!-- Left Panel: Adjustment Form -->
                        <div class="col-lg-5 col-md-12">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title mb-3"><i class="bi bi-sliders text-primary me-2"></i>Update Entitlements</h4>
                                    
                                    <form method="POST" action="hr_leave_management.php">
                                        <input type="hidden" name="action_type" value="update_quota">

                                        <div class="mb-3">
                                            <label class="form-label font-weight-bold">1. Target Scope</label>
                                            <select class="form-select" name="target_scope" id="target_scope" onchange="toggleScopeInputs()">
                                                <option value="single">Specific User</option>
                                                <option value="batch">By Batch Number</option>
                                                <option value="type">By Employee Type / Mode</option>
                                            </select>
                                        </div>

                                        <!-- Scope Inputs -->
                                        <div class="mb-3 scope-input" id="scope_single">
                                            <label class="form-label font-weight-bold">Select Employee</label>
                                            <select class="form-control js-example-basic-single" name="target_user" style="width:100%;">
                                                <option value="">-- Choose Employee --</option>
                                                <?php while ($u = mysqli_fetch_assoc($usersQ)): ?>
                                                    <option value="<?= $u['userId'] ?>"><?= htmlspecialchars($u['fullName']) ?> (<?= $u['userId'] ?> - <?= $u['type'] ?>)</option>
                                                <?php endwhile; ?>
                                            </select>
                                        </div>

                                        <div class="mb-3 scope-input d-none" id="scope_batch">
                                            <label class="form-label font-weight-bold">Select Batch</label>
                                            <select class="form-select" name="target_batch">
                                                <option value="">-- Choose Batch --</option>
                                                <?php while ($b = mysqli_fetch_assoc($batchQ)): ?>
                                                    <option value="<?= htmlspecialchars($b['batch_number']) ?>">Batch <?= htmlspecialchars($b['batch_number']) ?></option>
                                                <?php endwhile; ?>
                                            </select>
                                        </div>

                                        <div class="mb-3 scope-input d-none" id="scope_type">
                                            <label class="form-label font-weight-bold">Select Type / Designation</label>
                                            <select class="form-select" name="target_type">
                                                <option value="">-- Choose Category --</option>
                                                <option value="offline">Offline Employees / Interns</option>
                                                <option value="online">Online Employees / Interns</option>
                                                <option value="juniorintern">Junior Interns</option>
                                                <option value="seniorintern">Senior Interns</option>
                                                <option value="pune">Pune Branch</option>
                                                <option value="kinwat">Kinwat Branch</option>
                                            </select>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label font-weight-bold">2. Update Mode</label>
                                            <select class="form-select" name="update_mode">
                                                <option value="set">Set Exact Quota (e.g. Set CL=4, SL=4, PL=5)</option>
                                                <option value="add">Add / Deduct Days (e.g. +1 or -1)</option>
                                            </select>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label font-weight-bold">3. New Leave Values</label>
                                            <div class="row g-2">
                                                <div class="col-4">
                                                    <label class="small text-muted">Casual (CL)</label>
                                                    <input type="number" class="form-control" name="cl_count" value="4" required>
                                                </div>
                                                <div class="col-4">
                                                    <label class="small text-muted">Sick (SL)</label>
                                                    <input type="number" class="form-control" name="sl_count" value="4" required>
                                                </div>
                                                <div class="col-4">
                                                    <label class="small text-muted">Privilege (PL/EL)</label>
                                                    <input type="number" class="form-control" name="pl_count" value="5" required>
                                                </div>
                                            </div>
                                        </div>

                                        <button type="submit" class="btn btn-primary w-100 font-weight-bold py-2 mt-2" style="background:#4F46E5; border:none; border-radius:8px;">
                                            <i class="bi bi-check-circle me-1"></i> Apply Quota Update
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- Right Panel: Employee List Table -->
                        <div class="col-lg-7 col-md-12">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title mb-3"><i class="bi bi-people text-success me-2"></i>Current Employee Entitlements</h4>
                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Employee</th>
                                                    <th>Type / Batch</th>
                                                    <th class="text-center">CL</th>
                                                    <th class="text-center">SL</th>
                                                    <th class="text-center">PL/EL</th>
                                                    <th class="text-center">Total Paid</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if ($empListQ && mysqli_num_rows($empListQ) > 0): ?>
                                                    <?php while ($emp = mysqli_fetch_assoc($empListQ)): 
                                                        $clVal = (int)($emp['CL'] ?? 0);
                                                        $slVal = (int)($emp['SL'] ?? 0);
                                                        $plVal = (int)($emp['PL'] ?? 0);
                                                        $tot = $clVal + $slVal + $plVal;
                                                    ?>
                                                        <tr>
                                                            <td>
                                                                <strong><?= htmlspecialchars($emp['fullName']) ?></strong>
                                                                <small class="d-block text-muted"><?= htmlspecialchars($emp['userId']) ?></small>
                                                            </td>
                                                            <td>
                                                                <span class="badge bg-light text-dark border"><?= htmlspecialchars($emp['type'] ?? 'N/A') ?></span>
                                                                <?php if (!empty($emp['batch_number'])): ?>
                                                                    <small class="d-block text-muted">Batch: <?= htmlspecialchars($emp['batch_number']) ?></small>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td class="text-center"><span class="badge bg-primary badge-quota"><?= $clVal ?></span></td>
                                                            <td class="text-center"><span class="badge bg-success badge-quota"><?= $slVal ?></span></td>
                                                            <td class="text-center"><span class="badge bg-warning text-dark badge-quota"><?= $plVal ?></span></td>
                                                            <td class="text-center"><strong style="color:#4F46E5;"><?= $tot ?> / 13</strong></td>
                                                        </tr>
                                                    <?php endwhile; ?>
                                                <?php else: ?>
                                                    <tr>
                                                        <td colspan="6" class="text-center text-muted py-4">No employees found.</td>
                                                    </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="../vendors/js/vendor.bundle.base.js"></script>
    <script src="../js/off-canvas.js"></script>
    <script src="../js/hoverable-collapse.js"></script>
    <script src="../js/template.js"></script>

    <script>
        function toggleScopeInputs() {
            const scope = document.getElementById('target_scope').value;
            document.querySelectorAll('.scope-input').forEach(el => el.classList.add('d-none'));
            if (scope === 'single') {
                document.getElementById('scope_single').classList.remove('d-none');
            } else if (scope === 'batch') {
                document.getElementById('scope_batch').classList.remove('d-none');
            } else if (scope === 'type') {
                document.getElementById('scope_type').classList.remove('d-none');
            }
        }
    </script>
</body>
</html>
