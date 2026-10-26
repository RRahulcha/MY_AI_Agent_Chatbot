<?php
session_start();
if (empty($_SESSION['userId'])) {
    echo "<script>window.location.href='login.php';</script>"; exit;
}

// Only HR and Admin can access this page
$sessionRole = $_SESSION['userRole'] ?? '';
$sessionDes  = strtolower($_SESSION['designation'] ?? '');
if ($sessionDes !== 'hr' && $sessionRole !== 'admin') {
    echo "<script>alert('Access Denied.'); window.history.back();</script>"; exit;
}

require_once('../include/db_config.php');
require_once('../include/short_leave_helper.php');

// Handle Approve / Reject action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action']) && !empty($_POST['sl_id'])) {
    $action = strtolower(trim($_POST['action']));
    $slId   = (int)$_POST['sl_id'];
    $approvedBy = $_SESSION['userId'];

    if ($action === 'approve') {
        mysqli_query($con, "
            UPDATE short_leaves
            SET status='Approved', approved_by='$approvedBy', approved_at=NOW()
            WHERE id=$slId AND status='Pending'
        ");
        $_SESSION['sla_msg'] = 'Short leave approved.';
    } elseif ($action === 'reject') {
        mysqli_query($con, "
            UPDATE short_leaves
            SET status='Rejected', approved_by='$approvedBy', approved_at=NOW()
            WHERE id=$slId AND status='Pending'
        ");
        $_SESSION['sla_msg'] = 'Short leave rejected.';
    }
    header('Location: short_leave_approval.php'); exit;
}

$flashMsg = $_SESSION['sla_msg'] ?? '';
unset($_SESSION['sla_msg']);

// Fetch all short leave requests
$requests = mysqli_query($con, "
    SELECT sl.*, s.fullName, s.userRole, s.designation
    FROM short_leaves sl
    LEFT JOIN signup s ON sl.userId = s.userId
    ORDER BY sl.created_at DESC
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include('../partials/_links.php'); ?>
    <meta charset="UTF-8">
    <title>Short Leave Approvals</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .sla-header {
            background: linear-gradient(135deg,#1977e1,#1254b0);
            color:#fff; padding:20px 28px; border-radius:14px 14px 0 0;
        }
        .sla-header h2 { margin:0; font-size:18px; font-weight:700; }
        .sla-card { background:#fff; border-radius:14px; box-shadow:0 4px 20px rgba(25,119,225,.1); overflow:hidden; }
        .badge-pending  { background:#fef3c7; color:#92400e; padding:3px 10px; border-radius:20px; font-size:12px; font-weight:700; }
        .badge-approved { background:#d1fae5; color:#065f46; padding:3px 10px; border-radius:20px; font-size:12px; font-weight:700; }
        .badge-rejected { background:#fee2e2; color:#991b1b; padding:3px 10px; border-radius:20px; font-size:12px; font-weight:700; }
        .btn-approve { background:#16a34a; color:#fff; border:none; padding:6px 14px; border-radius:6px; cursor:pointer; font-size:13px; font-weight:600; }
        .btn-reject  { background:#dc2626; color:#fff; border:none; padding:6px 14px; border-radius:6px; cursor:pointer; font-size:13px; font-weight:600; }
        .btn-approve:hover { background:#15803d; }
        .btn-reject:hover  { background:#b91c1c; }
        th { white-space: nowrap; }
    </style>
</head>
<body>
<div class="container-scroller">
    <?php include('../partials/_navbar.php'); ?>
    <div class="container-fluid page-body-wrapper">
        <?php include('../partials/_sidebar.php'); ?>
        <div class="main-content" style="padding:30px;">

            <div class="sla-card">
                <div class="sla-header">
                    <h2>⏱ Short Leave Approvals</h2>
                </div>
                <div style="padding:24px; overflow-x:auto;">
                    <table class="table table-bordered table-hover" id="slTable" style="width:100%;min-width:900px;">
                        <thead class="thead-light">
                            <tr>
                                <th>#</th>
                                <th>Employee</th>
                                <th>Date</th>
                                <th>From</th>
                                <th>To</th>
                                <th>Duration</th>
                                <th>Reason</th>
                                <th>Status</th>
                                <th>Applied On</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php $i=1; while($row = mysqli_fetch_assoc($requests)): ?>
                            <tr>
                                <td><?= $i++ ?></td>
                                <td><?= htmlspecialchars($row['fullName'] ?? $row['userId']) ?></td>
                                <td><?= htmlspecialchars($row['date']) ?></td>
                                <td><?= date('g:i A', strtotime($row['from_time'])) ?></td>
                                <td><?= date('g:i A', strtotime($row['to_time'])) ?></td>
                                <td><?= number_format($row['duration_hrs'], 1) ?> hrs</td>
                                <td><?= htmlspecialchars($row['reason'] ?? '-') ?></td>
                                <td>
                                    <?php
                                    $st = $row['status'];
                                    $cls = match($st) { 'Approved'=>'approved', 'Rejected'=>'rejected', default=>'pending' };
                                    echo "<span class='badge-{$cls}'>{$st}</span>";
                                    ?>
                                </td>
                                <td><?= date('d M Y, g:i A', strtotime($row['created_at'])) ?></td>
                                <td>
                                    <?php if ($row['status'] === 'Pending'): ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="sl_id" value="<?= $row['id'] ?>">
                                        <button type="submit" name="action" value="approve" class="btn-approve">✓ Approve</button>
                                        <button type="submit" name="action" value="reject"  class="btn-reject" style="margin-left:4px;">✗ Reject</button>
                                    </form>
                                    <?php else: ?>
                                    <span style="font-size:12px;color:#6b7280;">
                                        <?= $row['status'] ?> by <?= htmlspecialchars($row['approved_by'] ?? '-') ?>
                                    </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
<?php if (!empty($flashMsg)): ?>
Swal.fire({ icon: 'success', title: 'Done', text: <?= json_encode($flashMsg) ?>, confirmButtonColor: '#1977e1', timer: 2000, showConfirmButton: false });
<?php endif; ?>

// DataTable init if available
if (typeof $.fn.DataTable !== 'undefined') {
    $('#slTable').DataTable({ order: [[8,'desc']], pageLength: 25 });
}
</script>
<?php include('../partials/_footer.php'); ?>
</body>
</html>
