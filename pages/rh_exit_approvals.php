<?php
session_start();
if(!isset($_SESSION['userId']) || empty($_SESSION['userId'])){ echo "<script>window.location.href = 'login.php';</script>"; exit; }
$role  = isset($_SESSION['userRole']) ? strtolower(trim($_SESSION['userRole'])) : '';
$desig = isset($_SESSION['designation']) ? strtolower(trim($_SESSION['designation'])) : '';
$allowed_roles = ['resourceallocator', 'resource_allocator', 'resource allocator', 'resource head', 'resourcehead', 'resource_head', 'admin', 'superadmin', 'hr'];
if(!in_array($role, $allowed_roles) && !in_array($desig, $allowed_roles)) { echo '<script>alert("Unauthorized"); window.location.href = "login.php";</script>'; exit; }
include('../include/db_config.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include('../partials/_links.php') ?>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap4.min.css">
    <!-- Responsive DataTables CSS for Collapsible rows -->
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.4.1/css/responsive.bootstrap4.min.css">
    <style>
        .heading { color: #1977e1 !important; font-weight: bold; }
        .table th, .table td { padding: 10px !important; font-size: 12px; vertical-align: middle !important; }
        .table th { background-color: #f8f9fa; text-transform: uppercase; font-weight: 600; white-space: nowrap; }
        
        /* Fix for DataTables + icon alignment (Collapsible feature) */
        table.dataTable.dtr-inline.collapsed>tbody>tr>td.dtr-control:before, 
        table.dataTable.dtr-inline.collapsed>tbody>tr>th.dtr-control:before { 
            background-color: #1977e1 !important; 
            top: 50%; 
            transform: translateY(-50%);
        }
        
        .truncate-text { max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: inline-block; }
    </style>
</head>
<body>
    <div class="container-scroller">
        <?php include('../partials/_navbar.php') ?>
        <div class="container-fluid page-body-wrapper">
            <?php include('../partials/_sidebar.php'); ?>
            
            <div class="main-panel">
                <div class="content-wrapper">
                    
                    <div class="row">
                        <div class="col-md-12 grid-margin stretch-card">
                            <div class="card shadow-sm border-0" style="border-radius: 12px;">
                                <div class="card-body p-3 p-md-4">
                                    <h5 class="card-title heading border-bottom pb-2 mb-3"><i class="ti-check-box mr-2"></i> Pending Exit Approvals</h5>
                                    
                                    <?php if(isset($_SESSION['msg'])): ?>
                                        <div class="alert alert-<?php echo $_SESSION['status'] ? 'success' : 'danger'; ?> alert-dismissible fade show" role="alert">
                                            <?php echo $_SESSION['msg']; ?>
                                            <button type="button" class="close" data-dismiss="alert"><span aria-hidden="true">&times;</span></button>
                                        </div>
                                        <?php unset($_SESSION['msg']); unset($_SESSION['status']); ?>
                                    <?php endif; ?>

                                    <div class="table-responsive" style="overflow: visible;">
                                        <!-- dt-responsive nowrap ensures the table collapses instead of overflowing -->
                                        <table class="table table-hover w-100 dt-responsive nowrap" id="rhTable">
                                            <thead>
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Name</th>
                                                    <th>Attd</th>
                                                    <th>Task</th>
                                                    <th class="text-center">Tech</th>
                                                    <th class="text-center">Access</th>
                                                    <th class="text-center">Interview & Feedback</th>
                                                    <th>Reason for Leaving</th>
                                                    <th>Feedback</th>
                                                    <th>Doc</th>
                                                    <th class="text-center">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                $q = mysqli_query($con, "SELECT e.*, COALESCE(s.fullName, 'Unknown User') as fullName FROM exit_process e LEFT JOIN signup s ON e.userId COLLATE utf8mb4_unicode_ci = s.userId COLLATE utf8mb4_unicode_ci WHERE e.status = 'Pending RH Approval' ORDER BY e.created_at DESC");
                                                if($q) {
                                                    while($r = mysqli_fetch_assoc($q)) {
                                                        $attHtml = ($r['attendance_percentage'] >= 80) ? "<span class='text-success font-weight-bold'>{$r['attendance_percentage']}%</span>" : "<span class='text-danger font-weight-bold'>{$r['attendance_percentage']}%</span>";
                                                        $taskHtml = ($r['tasks_completed'] >= 10) ? "<span class='text-success font-weight-bold'>{$r['tasks_completed']}</span>" : "<span class='text-danger font-weight-bold'>{$r['tasks_completed']}</span>";
                                                        
                                                        $techHtml = ($r['tech_handover'] == 1) ? "<span class='text-success font-weight-bold'><i class='ti-check'></i> Yes</span>" : "<span class='text-danger font-weight-bold'><i class='ti-close'></i> No</span>";
                                                        $accessHtml = ($r['access_revoked'] == 1) ? "<span class='text-success font-weight-bold'><i class='ti-check'></i> Yes</span>" : "<span class='text-danger font-weight-bold'><i class='ti-close'></i> No</span>";
                                                        
                                                        // New Interview & Feedback Logic (Checks if HR filled the reason & feedback fields)
                                                        $interviewHtml = (!empty($r['reason_for_leaving']) && !empty($r['intern_feedback'])) ? "<span class='text-success font-weight-bold'><i class='ti-check'></i> Yes</span>" : "<span class='text-danger font-weight-bold'><i class='ti-close'></i> No</span>";
                                                ?>
                                                <tr>
                                                    <td class="text-muted"><?php echo date('d M y', strtotime($r['created_at'])); ?></td>
                                                    <td class="font-weight-bold text-dark"><?php echo htmlspecialchars($r['fullName']); ?></td>
                                                    <td><?php echo $attHtml; ?></td>
                                                    <td><?php echo $taskHtml; ?></td>
                                                    <td class="text-center"><?php echo $techHtml; ?></td>
                                                    <td class="text-center"><?php echo $accessHtml; ?></td>
                                                    <td class="text-center"><?php echo $interviewHtml; ?></td>
                                                    
                                                    <td><span class="truncate-text" title="<?php echo htmlspecialchars($r['reason_for_leaving']); ?>"><?php echo htmlspecialchars($r['reason_for_leaving']); ?></span></td>
                                                    <td><span class="truncate-text" title="<?php echo htmlspecialchars($r['intern_feedback']); ?>"><?php echo htmlspecialchars($r['intern_feedback']); ?></span></td>
                                                    
                                                    <td><?php echo !empty($r['exp_letter_path']) ? '<a href="../uploads/'.htmlspecialchars($r['exp_letter_path']).'" target="_blank" class="btn btn-sm btn-outline-info px-2 py-1"><i class="ti-eye"></i></a>' : '<span class="text-muted small">None</span>'; ?></td>
                                                    <td class="text-center">
                                                        <form action="process_rh_action.php" method="POST" style="display:inline;">
                                                            <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                                                            <input type="hidden" name="userId" value="<?php echo $r['userId']; ?>">
                                                            <input type="hidden" name="action" value="approve">
                                                            <button type="submit" class="btn btn-sm btn-success shadow-sm font-weight-bold px-3 py-1" onclick="return confirm('Approve this request?');">Approve</button>
                                                        </form>
                                                        <button type="button" class="btn btn-sm btn-danger shadow-sm font-weight-bold px-3 py-1 ml-1" data-toggle="modal" data-target="#rejectModal<?php echo $r['id']; ?>">Reject</button>
                                                    </td>
                                                </tr>

                                                <!-- Reject Modal -->
                                                <div class="modal fade" id="rejectModal<?php echo $r['id']; ?>" tabindex="-1" role="dialog" aria-hidden="true">
                                                    <div class="modal-dialog" role="document">
                                                        <div class="modal-content border-0 shadow-lg" style="border-radius: 12px;">
                                                            <form action="process_rh_action.php" method="POST">
                                                                <div class="modal-header bg-light">
                                                                    <h5 class="modal-title font-weight-bold text-danger"><i class="ti-na mr-2"></i>Reject Exit Process</h5>
                                                                    <button type="button" class="close" data-dismiss="modal"><span aria-hidden="true">&times;</span></button>
                                                                </div>
                                                                <div class="modal-body p-4 text-left">
                                                                    <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                                                                    <input type="hidden" name="userId" value="<?php echo $r['userId']; ?>">
                                                                    <input type="hidden" name="action" value="reject">
                                                                    <p class="mb-3">Rejecting exit process for <strong><?php echo htmlspecialchars($r['fullName']); ?></strong>.</p>
                                                                    <textarea name="remark" class="form-control" rows="4" placeholder="Enter reason..." required style="resize:none;"></textarea>
                                                                </div>
                                                                <div class="modal-footer bg-light">
                                                                    <button type="button" class="btn btn-secondary font-weight-bold" data-dismiss="modal">Cancel</button>
                                                                    <button type="submit" class="btn btn-danger font-weight-bold">Reject</button>
                                                                </div>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>
                                                <?php } } ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- History Table -->
                    <div class="row mt-4">
                        <div class="col-md-12 grid-margin stretch-card">
                            <div class="card shadow-sm border-0" style="border-radius: 12px;">
                                <div class="card-body p-3 p-md-4">
                                    <h5 class="card-title heading border-bottom pb-2 mb-3"><i class="ti-time mr-2"></i> Your Processed History</h5>
                                    <div class="table-responsive" style="overflow: visible;">
                                        <table class="table table-hover w-100 dt-responsive nowrap" id="rhHistoryTable">
                                            <thead>
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Name</th>
                                                    <th>Status</th>
                                                    <th class="text-center">Tech</th>
                                                    <th class="text-center">Access</th>
                                                    <th class="text-center">Interview & Feedback</th>
                                                    <th>Reason</th>
                                                    <th>Feedback</th>
                                                    <th>Your Remark</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                $q2 = mysqli_query($con, "SELECT e.*, COALESCE(s.fullName, 'Unknown User') as fullName FROM exit_process e LEFT JOIN signup s ON e.userId COLLATE utf8mb4_unicode_ci = s.userId COLLATE utf8mb4_unicode_ci WHERE e.status != 'Pending RH Approval' ORDER BY e.updated_at DESC");
                                                if($q2) {
                                                    while($r2 = mysqli_fetch_assoc($q2)) {
                                                        $badge = ($r2['status'] == 'Approved') ? 'badge-success' : 'badge-danger';
                                                        $techHtml = ($r2['tech_handover'] == 1) ? "<span class='text-success font-weight-bold'><i class='ti-check'></i> Yes</span>" : "<span class='text-danger font-weight-bold'><i class='ti-close'></i> No</span>";
                                                        $accessHtml = ($r2['access_revoked'] == 1) ? "<span class='text-success font-weight-bold'><i class='ti-check'></i> Yes</span>" : "<span class='text-danger font-weight-bold'><i class='ti-close'></i> No</span>";
                                                        $interviewHtml = ($r2['exit_interview'] == 1) ? "<span class='text-success font-weight-bold'><i class='ti-check'></i> Yes</span>" : "<span class='text-danger font-weight-bold'><i class='ti-close'></i> No</span>";
                                                ?>
                                                <tr>
                                                    <td class="text-muted"><?php echo date('d M y, h:i A', strtotime($r2['updated_at'])); ?></td>
                                                    <td class="font-weight-bold text-dark"><?php echo htmlspecialchars($r2['fullName']); ?></td>
                                                    <td><span class="badge <?php echo $badge; ?>"><?php echo $r2['status']; ?></span></td>
                                                    <td class="text-center"><?php echo $techHtml; ?></td>
                                                    <td class="text-center"><?php echo $accessHtml; ?></td>
                                                    <td class="text-center"><?php echo $interviewHtml; ?></td>
                                                    <td><span class="truncate-text" title="<?php echo htmlspecialchars($r2['reason_for_leaving']); ?>"><?php echo htmlspecialchars($r2['reason_for_leaving']); ?></span></td>
                                                    <td><span class="truncate-text" title="<?php echo htmlspecialchars($r2['intern_feedback']); ?>"><?php echo htmlspecialchars($r2['intern_feedback']); ?></span></td>
                                                    <td class="text-danger font-weight-bold"><span class="truncate-text" title="<?php echo htmlspecialchars($r2['rejection_remark']); ?>"><?php echo !empty($r2['rejection_remark']) ? htmlspecialchars($r2['rejection_remark']) : '-'; ?></span></td>
                                                </tr>
                                                <?php } } ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php include("../partials/_footer.php") ?>
            </div>
        </div>
    </div>

    <script src="../vendors/js/vendor.bundle.base.js"></script>
    <script src="../vendors/datatables.net/jquery.dataTables.js"></script>
    <script src="../vendors/datatables.net-bs4/dataTables.bootstrap4.js"></script>
    
    <!-- Responsive DataTables Plugin (Makes the table collapsible) -->
    <script src="https://cdn.datatables.net/responsive/2.4.1/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.4.1/js/responsive.bootstrap4.min.js"></script>

    <!-- Theme Scripts (Fixes Logout) -->
    <script src="../js/off-canvas.js"></script>
    <script src="../js/hoverable-collapse.js"></script>
    <script src="../js/template.js"></script>
    <script src="../js/settings.js"></script>
    <script src="../js/todolist.js"></script>

    <script>
        $(document).ready(function() {
            if ($('#rhTable td.text-danger').length === 0) {
                // Initialize Collapsible DataTables
                $('#rhTable').DataTable({ 
                    "order": [[ 0, "desc" ]], 
                    "pageLength": 10,
                    "responsive": true 
                });
                $('#rhHistoryTable').DataTable({ 
                    "order": [[ 0, "desc" ]], 
                    "pageLength": 10,
                    "responsive": true 
                });
            }
        });
    </script>
</body>
</html>