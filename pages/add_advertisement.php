<?php
session_start();
if(!isset($_SESSION['userId']) && empty($_SESSION['userId'])){
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Manage Advertisements & Placements</title>
    <?php include('../partials/_links.php'); ?>
    <style>
        .required { color: red; }
        .form-control { border-radius: 6px; }
        .card-header { font-size: 1.1rem; font-weight: 600; }
        .ad-block { border: 1px solid #e0e0e0; padding: 20px; margin-bottom: 20px; border-radius: 8px; position: relative; background: #fafafa; }
        .remove-btn { position: absolute; top: 10px; right: 10px; }
        .preview-img { max-width: 100px; max-height: 100px; border-radius: 6px; }
        .preview-vid { max-width: 150px; max-height: 100px; border-radius: 6px; }
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
                        
                        <!-- =========================
                             SECTION 1: ADVERTISEMENTS (MAIN BOARD) 
                             ========================= -->
                        <div class="col-12 grid-margin stretch-card">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title"><i class="fa-solid fa-bullhorn"></i> Add Advertisements (Main Board)</h4>
                                    <form id="adsForm" enctype="multipart/form-data">
                                        <input type="hidden" name="display_location" value="Main Display Center">
                                        <div id="adsContainer">
                                            <!-- Initial Block -->
                                            <div class="ad-block">
                                                <div class="row">
                                                    <div class="col-md-3">
                                                        <div class="form-group">
                                                            <label>Title <span class="required">*</span></label>
                                                            <input type="text" class="form-control" name="title[]" placeholder="Required" required>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <div class="form-group">
                                                            <label>Other Information</label>
                                                            <input type="text" class="form-control" name="other_info[]" placeholder="Optional">
                                                        </div>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <div class="form-group">
                                                            <label>Media Type <span class="required">*</span></label>
                                                            <select class="form-control media-type-select" name="media_type[]" required>
                                                                <option value="image">Image</option>
                                                                <option value="video">Video</option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <div class="form-group">
                                                            <label>Upload File <span class="required">*</span></label>
                                                            <input type="file" class="form-control file-input" name="file[]" accept="image/jpeg, image/png, image/webp" required>
                                                            <small class="text-muted format-help">JPG, PNG, WEBP allowed</small>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <button type="button" id="addMoreAdsBtn" class="btn btn-secondary mb-3"><i class="fa-solid fa-plus"></i> Add More</button>
                                        <hr>
                                        <button type="submit" id="saveAdsBtn" class="btn btn-primary"><i class="fa-solid fa-save"></i> Save Advertisements</button>
                                    </form>

                                    <h4 class="card-title mt-5">Existing Advertisements</h4>
                                    <div class="table-responsive">
                                        <table class="table table-hover" id="adsTable" width="100%">
                                            <thead>
                                                <tr>
                                                    <th>Sr No</th>
                                                    <th>Group ID</th>
                                                    <th>Preview</th>
                                                    <th>Media Count</th>
                                                    <th>Titles/Info</th>
                                                    <th>Created Date</th>
                                                    <th>Delete</th>
                                                </tr>
                                            </thead>
                                            <tbody></tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- =========================
                             SECTION 2: PLACEMENTS (FOOTER TICKER)
                             ========================= -->
                        <div class="col-12 grid-margin stretch-card mt-4">
                            <div class="card border-info">
                                <div class="card-body">
                                    <h4 class="card-title text-info"><i class="fa-solid fa-bars-staggered"></i> Manage Placements</h4>
                                    <form id="placementsForm" enctype="multipart/form-data">
                                        <input type="hidden" name="display_location" value="Bottom Footer Bar">
                                        <div id="placementsContainer">
                                            <!-- Initial Block -->
                                            <div class="ad-block">
                                                <div class="row">
                                                    <div class="col-md-3">
                                                        <div class="form-group">
                                                            <label>Title <span class="required">*</span></label>
                                                            <input type="text" class="form-control" name="title[]" placeholder="Required" required>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <div class="form-group">
                                                            <label>Other Information</label>
                                                            <input type="text" class="form-control" name="other_info[]" placeholder="Optional">
                                                        </div>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <div class="form-group">
                                                            <label>Media Type <span class="required">*</span></label>
                                                            <select class="form-control media-type-select" name="media_type[]" required>
                                                                <option value="image">Image</option>
                                                                <option value="video">Video</option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <div class="form-group">
                                                            <label>Upload File (Optional for Text)</label>
                                                            <input type="file" class="form-control file-input" name="file[]" accept="image/jpeg, image/png, image/webp">
                                                            <small class="text-muted format-help">JPG, PNG, WEBP allowed</small>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <button type="button" id="addMorePlacementsBtn" class="btn btn-secondary mb-3"><i class="fa-solid fa-plus"></i> Add More</button>
                                        <hr>
                                        <button type="submit" id="savePlacementsBtn" class="btn btn-info text-white"><i class="fa-solid fa-save"></i> Save Placements</button>
                                    </form>

                                    <h4 class="card-title mt-5">Existing Placements</h4>
                                    <div class="table-responsive">
                                        <table class="table table-hover" id="placementsTable" width="100%">
                                            <thead>
                                                <tr>
                                                    <th>Sr No</th>
                                                    <th>Group ID</th>
                                                    <th>Preview</th>
                                                    <th>Media Count</th>
                                                    <th>Titles/Info</th>
                                                    <th>Created Date</th>
                                                    <th>Action</th>
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
    <!-- Edit Advertisement Modal -->
    <div class="modal fade" id="editAdModal" tabindex="-1" role="dialog" aria-labelledby="editAdModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <form id="editAdForm" enctype="multipart/form-data">
                    <div class="modal-header">
                        <h5 class="modal-title" id="editAdModalLabel">Edit Advertisement</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="edit_group_id" id="edit_group_id">
                        <div id="editAdContainer">
                            <!-- Dynamic edit blocks will be injected here -->
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" id="updateAdBtn" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="../vendors/js/vendor.bundle.base.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../vendors/datatables.net/jquery.dataTables.js"></script>
    <script src="../vendors/datatables.net-bs4/dataTables.bootstrap4.js"></script>
    <script src="../js/off-canvas.js"></script>
    <script src="../js/hoverable-collapse.js"></script>
    <script src="../js/template.js"></script>
    <script src="../js/settings.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        $(document).ready(function() {

            // INITIALIZE DATATABLES
            let adTable = $('#adsTable').DataTable({
                ajax: {
                    url: '../ajax/get_advertisements.php?location=Main Display Center',
                    dataSrc: ''
                },
                columns: [
                    { data: 'sr_no' },
                    { data: 'advertisement_group_id' },
                    { data: 'preview' },
                    { data: 'media_count' },
                    { data: 'titles_info' },
                    { data: 'created_date' },
                    { data: 'delete_btn', orderable: false }
                ]
            });

            let pTable = $('#placementsTable').DataTable({
                ajax: {
                    url: '../ajax/get_advertisements.php?location=Bottom Footer Bar',
                    dataSrc: ''
                },
                columns: [
                    { data: 'sr_no' },
                    { data: 'advertisement_group_id' },
                    { data: 'preview' },
                    { data: 'media_count' },
                    { data: 'titles_info' },
                    { data: 'created_date' },
                    { data: 'delete_btn', orderable: false }
                ]
            });

            // ADD MORE BLOCKS
            function getBlockHtml(isRequired) {
                let reqAttr = isRequired ? 'required' : '';
                let reqStar = isRequired ? '<span class="required">*</span>' : '(Optional for Text)';
                return `
                <div class="ad-block">
                    <button type="button" class="btn btn-danger btn-sm remove-btn"><i class="fa-solid fa-times"></i></button>
                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Title <span class="required">*</span></label>
                                <input type="text" class="form-control" name="title[]" placeholder="Required" required>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Other Information</label>
                                <input type="text" class="form-control" name="other_info[]" placeholder="Optional">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Media Type <span class="required">*</span></label>
                                <select class="form-control media-type-select" name="media_type[]" required>
                                    <option value="image">Image</option>
                                    <option value="video">Video</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Upload File ${reqStar}</label>
                                <input type="file" class="form-control file-input" name="file[]" accept="image/jpeg, image/png, image/webp" ${reqAttr}>
                                <small class="text-muted format-help">JPG, PNG, WEBP allowed</small>
                            </div>
                        </div>
                    </div>
                </div>`;
            }

            $('#addMoreAdsBtn').click(function() {
                $('#adsContainer').append(getBlockHtml(true));
            });

            $('#addMorePlacementsBtn').click(function() {
                $('#placementsContainer').append(getBlockHtml(false));
            });

            $(document).on('click', '.remove-btn', function() {
                $(this).closest('.ad-block').remove();
            });

            // MEDIA TYPE SELECT
            $(document).on('change', '.media-type-select', function() {
                let type = $(this).val();
                let block = $(this).closest('.ad-block');
                let fileInput = block.find('.file-input');
                let helpText = block.find('.format-help');

                if (type === 'image') {
                    fileInput.attr('accept', 'image/jpeg, image/png, image/webp');
                    helpText.text('JPG, PNG, WEBP allowed');
                } else {
                    fileInput.attr('accept', 'video/mp4, video/webm, video/quicktime');
                    helpText.text('MP4, WEBM, MOV allowed');
                }
                fileInput.val('');
            });

            // FORM SUBMISSION HANDLER
            function handleFormSubmit(formId, btnId, btnText, containerId, table) {
                $(formId).submit(function(e) {
                    e.preventDefault();
                    let formData = new FormData(this);
                    
                    $(btnId).prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Saving...');
                    
                    $.ajax({
                        url: '../ajax/save_advertisements.php',
                        type: 'POST',
                        data: formData,
                        processData: false,
                        contentType: false,
                        success: function(res) {
                            let data = JSON.parse(res);
                            if (data.success) {
                                Swal.fire('Success', data.msg, 'success');
                                $(formId)[0].reset();
                                $(containerId + ' .ad-block:not(:first)').remove();
                                table.ajax.reload();
                            } else {
                                Swal.fire('Error', data.msg, 'error');
                            }
                            $(btnId).prop('disabled', false).html('<i class="fa-solid fa-save"></i> ' + btnText);
                        },
                        error: function() {
                            Swal.fire('Error', 'Server error occurred', 'error');
                            $(btnId).prop('disabled', false).html('<i class="fa-solid fa-save"></i> ' + btnText);
                        }
                    });
                });
            }

            handleFormSubmit('#adsForm', '#saveAdsBtn', 'Save Advertisements', '#adsContainer', adTable);
            handleFormSubmit('#placementsForm', '#savePlacementsBtn', 'Save Placements', '#placementsContainer', pTable);

            // DELETE HANDLER
            $(document).on('click', '.delete-ad', function() {
                let id = $(this).data('id');
                Swal.fire({
                    title: 'Are you sure?',
                    text: "You won't be able to revert this!",
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#d33',
                    cancelButtonColor: '#3085d6',
                    confirmButtonText: 'Yes, delete it!'
                }).then((result) => {
                    if (result.isConfirmed) {
                        $.post('../ajax/delete_advertisement.php', { id: id }, function(res) {
                            let data = JSON.parse(res);
                            if (data.success) {
                                Swal.fire('Deleted!', 'Item has been deleted.', 'success');
                                adTable.ajax.reload();
                                pTable.ajax.reload();
                            } else {
                                Swal.fire('Error', data.msg, 'error');
                            }
                        });
                    }
                })
            });

            // TOGGLE STATUS HANDLER
            $(document).on('click', '.toggle-status-ad', function() {
                let id = $(this).data('id');
                let newStatus = $(this).data('new-status');
                let statusText = newStatus === 1 ? 'Play (Activate)' : 'Pause (Deactivate)';
                
                Swal.fire({
                    title: 'Are you sure?',
                    text: `Do you want to ${statusText} this item?`,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#3085d6',
                    cancelButtonColor: '#d33',
                    confirmButtonText: `Yes, ${statusText} it!`
                }).then((result) => {
                    if (result.isConfirmed) {
                        $.post('../ajax/toggle_advertisement_status.php', { id: id, status: newStatus }, function(res) {
                            let data = JSON.parse(res);
                            if (data.success) {
                                Swal.fire('Updated!', 'Status has been updated successfully.', 'success');
                                adTable.ajax.reload(null, false);
                                pTable.ajax.reload(null, false);
                            } else {
                                Swal.fire('Error', data.msg, 'error');
                            }
                        });
                    }
                })
            });

            // EDIT HANDLER
            $(document).on('click', '.edit-ad', function() {
                let groupId = $(this).data('id');
                
                // Fetch advertisement details
                $.ajax({
                    url: '../ajax/get_advertisement_by_id.php',
                    type: 'GET',
                    data: { id: groupId },
                    success: function(res) {
                        let result = JSON.parse(res);
                        if (result.success) {
                            let items = result.data;
                            $('#edit_group_id').val(groupId);
                            
                            let html = '';
                            items.forEach(function(item) {
                                let mediaPreview = '';
                                if (item.media_type === 'image') {
                                    mediaPreview = `<img src="../${item.media_path}" style="height:50px; margin-top:5px; border-radius:4px;">`;
                                } else if (item.media_type === 'video') {
                                    mediaPreview = `<video src="../${item.media_path}" style="height:50px; margin-top:5px; border-radius:4px;" muted></video>`;
                                }
                                
                                html += `
                                <div class="ad-block p-3 mb-3 border rounded">
                                    <input type="hidden" name="edit_item_id[]" value="${item.id}">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label>Title <span class="required">*</span></label>
                                                <input type="text" class="form-control" name="edit_title[]" value="${item.title || ''}" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label>Other Information</label>
                                                <input type="text" class="form-control" name="edit_other_info[]" value="${item.description || ''}">
                                            </div>
                                        </div>
                                        <div class="col-md-12 mt-2">
                                            <div class="form-group">
                                                <label>Replace Media File (Optional) - Current: ${item.media_type}</label>
                                                <input type="file" class="form-control file-input" name="edit_file[]" accept="${item.media_type === 'image' ? 'image/jpeg, image/png, image/webp' : 'video/mp4, video/webm, video/quicktime'}">
                                                <div>${mediaPreview}</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>`;
                            });
                            
                            $('#editAdContainer').html(html);
                            $('#editAdModal').modal('show');
                        } else {
                            Swal.fire('Error', result.msg, 'error');
                        }
                    },
                    error: function() {
                        Swal.fire('Error', 'Failed to fetch advertisement data.', 'error');
                    }
                });
            });

            // UPDATE FORM SUBMISSION
            $('#editAdForm').submit(function(e) {
                e.preventDefault();
                let formData = new FormData(this);
                let btn = $('#updateAdBtn');
                
                btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Saving...');
                
                $.ajax({
                    url: '../ajax/update_advertisements.php',
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function(res) {
                        let data = JSON.parse(res);
                        if (data.success) {
                            Swal.fire('Success', data.msg, 'success');
                            $('#editAdModal').modal('hide');
                            adTable.ajax.reload(null, false);
                            pTable.ajax.reload(null, false);
                        } else {
                            Swal.fire('Error', data.msg, 'error');
                        }
                        btn.prop('disabled', false).html('Save Changes');
                    },
                    error: function() {
                        Swal.fire('Error', 'Server error occurred', 'error');
                        btn.prop('disabled', false).html('Save Changes');
                    }
                });
            });

        });
    </script>
</body>
</html>
