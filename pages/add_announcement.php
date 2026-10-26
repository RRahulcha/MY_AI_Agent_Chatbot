<?php
session_start();
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}

include('../include/db_config.php');

// Restrict access based on designation/role
$userId = $_SESSION['userId'];
$userQuery = $conn->query("SELECT designation FROM signup WHERE userId = '$userId'");
$userRow = $userQuery->fetch_assoc();
$designation = $userRow['designation'] ?? '';

$can_manage_ads_ann = ['admin', 'hr', 'md', 'resourceallocator', 'resourcehead', 'resourcemanager', 'seniorintern', 'teamlead', 'departmenthead', 'manager'];
$formattedDesig = strtolower(str_replace([' ', '_'], '', $designation));

if (!in_array($formattedDesig, $can_manage_ads_ann)) {
    echo '<script>alert("You are not authorized to access this page.");window.location.href="logout.php";</script>';
    die();
}

// Fetch all active employees/interns directly for instant Select2 searching
$employeesQuery = $conn->query("
    SELECT userId, fullName, designation, batch_number, emp_id, internId 
    FROM signup 
    WHERE regstatus='Approved' AND status='Active' AND userId IS NOT NULL AND userId != ''
    ORDER BY fullName ASC
");
$allEmployees = [];
if ($employeesQuery) {
    while ($emp = $employeesQuery->fetch_assoc()) {
        $allEmployees[] = $emp;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Add Announcement — SETTribe AMS</title>
    <?php include('../partials/_links.php'); ?>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        .required { color: #e3342f; font-weight: bold; }
        
        .card {
            border: none;
            border-radius: 14px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        
        .form-control {
            border-radius: 8px;
            padding: 0.75rem 1rem;
            border: 1px solid #cbd5e1;
            font-size: 0.95rem;
        }
        .form-control:focus {
            border-color: #4f46e5;
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1);
            background-color: #fff !important;
        }
        
        /* Select2 Premium Styling */
        .select2-container .select2-selection--multiple {
            min-height: 48px;
            border: 1px solid #cbd5e1 !important;
            border-radius: 8px !important;
            background-color: #f8fafc !important;
            padding: 4px 8px !important;
        }
        .select2-container--default.select2-container--focus .select2-selection--multiple {
            border-color: #4f46e5 !important;
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1) !important;
            background-color: #fff !important;
        }
        .select2-container--default .select2-selection--multiple .select2-selection__choice {
            background-color: #e0e7ff !important;
            border: 1px solid #c7d2fe !important;
            color: #3730a3 !important;
            border-radius: 6px !important;
            padding: 3px 10px !important;
            font-weight: 600 !important;
            font-size: 0.85rem !important;
            margin-top: 4px !important;
        }
        .select2-container--default .select2-selection--multiple .select2-selection__choice__remove {
            color: #4338ca !important;
            margin-right: 5px !important;
        }
        .select2-container { width: 100% !important; }
        
        /* Buttons */
        .btn-primary {
            background: linear-gradient(135deg, #4f46e5 0%, #3b82f6 100%);
            border: none;
            font-family: 'Sora', sans-serif;
            font-weight: 700;
        }
        .btn-primary:hover {
            background: linear-gradient(135deg, #4338ca 0%, #2563eb 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 14px rgba(79, 70, 229, 0.3);
        }
        
        /* Target Audience Section */
        .target-audience-box {
            background: linear-gradient(to right bottom, #f8fafc, #f1f5f9);
            border: 1.5px solid #e2e8f0;
            border-radius: 12px;
            padding: 1.5rem;
        }
    </style>
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
                <div class="content-wrapper">
                    <div class="row">
                        
                        <!-- ADD ANNOUNCEMENT FORM -->
                        <div class="col-12 grid-margin stretch-card">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title text-primary mb-4" style="font-weight: 800; font-size: 1.4rem; font-family: 'Sora', sans-serif;">
                                        <i class="fa-solid fa-bullhorn mr-2"></i> Add New Announcement
                                    </h4>

                                    <form id="announcementForm">
                                        
                                        <!-- Row 1: Title, Priority, Expiry Date -->
                                        <div class="row mb-3">
                                            <div class="col-md-6 form-group">
                                                <label class="font-weight-bold text-dark">Title <span class="required">*</span></label>
                                                <input type="text" class="form-control bg-light border-0" name="title" id="title" required placeholder="Enter announcement title">
                                            </div>
                                            <div class="col-md-3 form-group">
                                                <label class="font-weight-bold text-dark">Priority / Urgency</label>
                                                <select class="form-control" name="priority" id="priority">
                                                    <option value="normal">🟢 Normal</option>
                                                    <option value="important">🟡 Important</option>
                                                    <option value="urgent">🔴 Urgent</option>
                                                </select>
                                            </div>
                                            <div class="col-md-3 form-group">
                                                <label class="font-weight-bold text-dark">Expiry Date (Optional)</label>
                                                <input type="date" class="form-control bg-light border-0" name="expiry_date" id="expiry_date" min="<?= date('Y-m-d') ?>">
                                                <small class="text-muted">Stop showing after this date</small>
                                            </div>
                                        </div>

                                        <!-- Row 2: Scheduled Date & Time, Display Board Toggle -->
                                        <div class="row mb-3">
                                            <div class="col-md-6 form-group">
                                                <label class="font-weight-bold text-dark">Scheduled Date &amp; Time (Optional)</label>
                                                <input type="datetime-local" class="form-control bg-light border-0" name="scheduled_date" id="scheduled_date" min="<?= date('Y-m-d\TH:i') ?>">
                                                <small class="text-muted"><i class="fas fa-info-circle"></i> Leave empty to send immediately</small>
                                            </div>
                                            <div class="col-md-6 form-group d-flex align-items-center" style="margin-top: 1.6rem;">
                                                <div class="form-check form-switch" style="padding-left: 2.5rem;">
                                                    <input class="form-check-input" type="checkbox" name="show_on_board" id="show_on_board" value="1" checked style="width:2.5rem;height:1.3rem;cursor:pointer;">
                                                    <label class="form-check-label font-weight-bold text-dark" for="show_on_board" style="margin-top:2px;cursor:pointer;font-size:1rem;">
                                                        📺 Show on Live Display Board (Kiosk)
                                                    </label>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Row 3: Description -->
                                        <div class="row mb-4">
                                            <div class="col-md-12 form-group mb-0">
                                                <label class="font-weight-bold text-dark">Description / Message Body</label>
                                                <textarea class="form-control bg-light border-0" name="description" id="description" rows="4" placeholder="Write the announcement description here..."></textarea>
                                            </div>
                                        </div>
                                        
                                        <!-- Row 4: Target Audience Selection (Convenient Search Dropdown) -->
                                        <div class="target-audience-box mb-4">
                                            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                                                <h5 class="mb-0 font-weight-bold text-dark" style="font-family: 'Sora', sans-serif;">
                                                    <i class="fa-solid fa-users text-primary mr-2"></i> Select Recipients / Target Audience
                                                </h5>
                                                <div class="d-flex align-items-center gap-2">
                                                    <button type="button" class="btn btn-sm btn-primary px-3 shadow-sm rounded-pill" id="selectAllBtn">
                                                        <i class="fa-solid fa-check-double mr-1"></i> Select All
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary px-3 shadow-sm rounded-pill" id="clearAllBtn">
                                                        <i class="fa-solid fa-eraser mr-1"></i> Clear All
                                                    </button>
                                                </div>
                                            </div>

                                            <div class="form-check mb-3">
                                                <input class="form-check-input" type="checkbox" name="send_to_all" id="sendToAllCheckbox" style="width:1.2rem;height:1.2rem;cursor:pointer;">
                                                <label class="form-check-label font-weight-bold text-primary ml-2" for="sendToAllCheckbox" style="font-size: 1.05rem; cursor: pointer;">
                                                    📢 Send to Entire Company (All Active Employees &amp; Interns)
                                                </label>
                                            </div>

                                            <div class="form-group mb-0" id="employeeSelectWrapper">
                                                <label class="font-weight-bold text-dark mb-1">Search &amp; Select Employees / Interns <span class="required">*</span></label>
                                                <select class="form-control select2 shadow-sm" id="employeeSelect" name="employees[]" multiple="multiple">
                                                    <?php foreach ($allEmployees as $emp): 
                                                        $dispId = !empty($emp['emp_id']) ? $emp['emp_id'] : ($emp['internId'] ?? '');
                                                        $metaInfo = array_filter([$emp['designation'] ?? '', $emp['batch_number'] ? 'Batch ' . $emp['batch_number'] : '', $dispId]);
                                                        $label = htmlspecialchars($emp['fullName']) . ' (' . htmlspecialchars(implode(' • ', $metaInfo)) . ')';
                                                    ?>
                                                        <option value="<?= htmlspecialchars($emp['userId']) ?>"><?= $label ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <small class="form-text text-muted">Type any employee name, ID, designation, or batch to search and select.</small>
                                            </div>
                                        </div>
                                        
                                        <!-- Submit Button -->
                                        <div class="row mt-4">
                                            <div class="col-md-12 text-right">
                                                <button type="submit" id="saveBtn" class="btn btn-primary btn-lg rounded-pill shadow px-5">
                                                    <i class="fa-solid fa-paper-plane mr-2"></i> Send Announcement
                                                </button>
                                            </div>
                                        </div>

                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- ANNOUNCEMENTS HISTORY TABLE -->
                        <div class="col-12 grid-margin stretch-card">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title text-primary mb-4" style="font-weight: 800; font-size: 1.4rem; font-family: 'Sora', sans-serif;">
                                        <i class="fa-solid fa-clock-rotate-left mr-2"></i> Past Announcements &amp; Notice History
                                    </h4>
                                    <div class="table-responsive">
                                        <table class="table table-hover" id="annTable" width="100%">
                                            <thead>
                                                <tr>
                                                    <th>#</th>
                                                    <th>Title</th>
                                                    <th>Priority</th>
                                                    <th>Board</th>
                                                    <th>Expiry</th>
                                                    <th>Recipients</th>
                                                    <th>Acknowledged</th>
                                                    <th>Created Date</th>
                                                    <th>View Reads</th>
                                                    <th>Delete</th>
                                                </tr>
                                            </thead>
                                            <tbody></tbody>
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
    
    <!-- READ STATUS MODAL -->
    <div class="modal fade" id="readStatusModal" tabindex="-1" role="dialog" aria-hidden="true">
      <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title font-weight-bold"><i class="fa-solid fa-eye text-primary mr-2"></i> Announcement Read Status</h5>
            <button type="button" class="close btn-close" data-dismiss="modal" data-bs-dismiss="modal" aria-label="Close">
              <span aria-hidden="true">&times;</span>
            </button>
          </div>
          <div class="modal-body">
            <div class="table-responsive">
                <table class="table table-bordered table-striped" id="readStatusTable" width="100%">
                    <thead>
                        <tr>
                            <th>Employee Name</th>
                            <th>Designation</th>
                            <th>Batch</th>
                            <th>Read Status</th>
                            <th>Read Date</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
          </div>
        </div>
      </div>
    </div>

    <script src="../vendors/js/vendor.bundle.base.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="../vendors/datatables.net/jquery.dataTables.js"></script>
    <script src="../vendors/datatables.net-bs4/dataTables.bootstrap4.js"></script>
    <script src="../js/off-canvas.js"></script>
    <script src="../js/hoverable-collapse.js"></script>
    <script src="../js/template.js"></script>
    <script src="../js/settings.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        $(document).ready(function() {
            
            // Initialize Select2
            $('#employeeSelect').select2({
                placeholder: "Type a name or ID to search employees...",
                allowClear: true
            });

            // Send to entire company toggle logic
            $('#sendToAllCheckbox').change(function() {
                if ($(this).is(':checked')) {
                    $('#employeeSelect > option').prop("selected", true);
                    $('#employeeSelect').trigger("change").prop("disabled", true);
                } else {
                    $('#employeeSelect').prop("disabled", false).val(null).trigger("change");
                }
            });

            // Select All button
            $('#selectAllBtn').click(function() {
                $('#sendToAllCheckbox').prop("checked", false);
                $('#employeeSelect').prop("disabled", false);
                $('#employeeSelect > option').prop("selected", true);
                $('#employeeSelect').trigger("change");
            });

            // Clear All button
            $('#clearAllBtn').click(function() {
                $('#sendToAllCheckbox').prop("checked", false);
                $('#employeeSelect').prop("disabled", false).val(null).trigger("change");
            });

            // DataTable Init
            let annTable = $('#annTable').DataTable({
                ajax: {
                    url: '../ajax/get_announcements.php',
                    dataSrc: ''
                },
                columns: [
                    { data: 'sr_no' },
                    { data: 'title' },
                    { data: 'priority', orderable: false },
                    { data: 'board', orderable: false },
                    { data: 'expiry' },
                    { data: 'emp_count' },
                    { data: 'ack_status', orderable: false },
                    { data: 'created_date' },
                    { data: 'view_btn', orderable: false },
                    { data: 'delete_btn', orderable: false }
                ],
                order: [[7, 'desc']],
                language: { emptyTable: 'No announcements posted yet.' }
            });
            
            let readTable = $('#readStatusTable').DataTable();

            // Submit Announcement Form
            $('#announcementForm').submit(function(e) {
                e.preventDefault();
                
                // Enable select for serialize/FormData if disabled
                $('#employeeSelect').prop("disabled", false);
                let formData = new FormData(this);
                
                $('#saveBtn').prop('disabled', true).html('<i class="fa fa-spinner fa-spin mr-2"></i> Sending...');
                
                $.ajax({
                    url: '../ajax/save_announcement.php',
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function(res) {
                        let data = typeof res === 'object' ? res : JSON.parse(res);
                        if (data.success) {
                            Swal.fire('Sent!', data.msg, 'success');
                            $('#announcementForm')[0].reset();
                            $('#employeeSelect').val(null).trigger('change');
                            annTable.ajax.reload(null, false);
                        } else {
                            Swal.fire('Error', data.msg || 'Failed to send announcement.', 'error');
                        }
                        $('#saveBtn').prop('disabled', false).html('<i class="fa-solid fa-paper-plane mr-2"></i> Send Announcement');
                    },
                    error: function(xhr, status, error) {
                        console.error("AJAX Error Output:", xhr.responseText);
                        let errStr = xhr.responseText ? xhr.responseText.replace(/<[^>]*>?/gm, '').trim() : error;
                        Swal.fire('Error', 'Server Error: ' + (errStr.length > 150 ? errStr.substring(0, 150) + '...' : errStr), 'error');
                        $('#saveBtn').prop('disabled', false).html('<i class="fa-solid fa-paper-plane mr-2"></i> Send Announcement');
                    }
                });
            });

            // View Reads Handler
            $(document).on('click', '.view-reads', function() {
                let id = $(this).data('id');
                readTable.clear().draw();
                
                $.getJSON('../ajax/get_announcement_reads.php', { id: id }, function(data) {
                    $.each(data, function(i, row) {
                        readTable.row.add([
                            row.emp_name,
                            row.designation,
                            row.batch,
                            row.read_status,
                            row.read_date
                        ]).draw(false);
                    });
                    
                    try {
                        let readModal = new bootstrap.Modal(document.getElementById('readStatusModal'));
                        readModal.show();
                    } catch(e) {
                        $('#readStatusModal').modal('show');
                    }
                });
            });

            // Delete Handler
            $(document).on('click', '.delete-ann', function() {
                let id = $(this).data('id');
                Swal.fire({
                    title: 'Delete this announcement?',
                    text: 'This will remove the announcement and read history.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#dc3545',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: 'Yes, delete it!'
                }).then((result) => {
                    if (result.isConfirmed) {
                        $.post('../ajax/delete_announcement.php', { id: id }, function(res) {
                            let data = typeof res === 'object' ? res : JSON.parse(res);
                            if (data.success) {
                                Swal.fire('Deleted!', 'Announcement deleted successfully.', 'success');
                                annTable.ajax.reload(null, false);
                            } else {
                                Swal.fire('Error', data.msg, 'error');
                            }
                        });
                    }
                });
            });

        });
    </script>
</body>
</html>
