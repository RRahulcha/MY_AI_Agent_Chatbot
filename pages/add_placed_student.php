<?php
session_start();
if (empty($_SESSION['userId'])) {
    echo "<script>window.location.href='login.php';</script>";
    exit;
}
include('../include/db_config.php');

// Role guard
$userId   = $_SESSION['userId'];
$userQuery = $conn->prepare("SELECT designation FROM signup WHERE userId = ?");
$userQuery->bind_param('s', $userId);
$userQuery->execute();
$userQuery->bind_result($designation);
$userQuery->fetch();
$userQuery->close();

$allowed = ['admin', 'hr', 'md'];
if (!in_array(strtolower($designation), array_map('strtolower', $allowed))) {
    echo '<script>alert("Not authorized.");window.location.href="logout.php";</script>';
    die();
}

// Fetch branches for filter
$branchQ = $conn->query("SELECT DISTINCT branch FROM signup WHERE branch IS NOT NULL AND branch != '' ORDER BY branch");
$branches = [];
while ($b = $branchQ->fetch_row()) $branches[] = $b[0];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Placed Students — SETTribe AMS</title>
    <?php include('../partials/_links.php'); ?>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-deep: #070b16;
            --bg-panel: #0f1830;
            --bg-panel-2: #131f3e;
            --accent-cyan: #36e2c9;
            --accent-blue: #4f8cff;
            --accent-amber: #ffb648;
            --accent-rose: #ff6b8b;
            --text-primary: #eef2fb;
            --text-muted: #8a97bd;
        }
        .ps-section-title {
            font-family: 'Sora', sans-serif;
            font-weight: 800;
            font-size: 1.3rem;
            color: #1a2340;
            margin-bottom: 0.5rem;
        }
        .ps-card {
            border-radius: 14px;
            border: none;
            box-shadow: 0 4px 20px rgba(0,0,0,0.07);
        }
        .ps-card .card-header {
            background: linear-gradient(135deg, #0f1830, #1a2a5e);
            color: #eef2fb;
            border-radius: 14px 14px 0 0;
            padding: 1rem 1.5rem;
            font-family: 'Sora', sans-serif;
            font-weight: 700;
            font-size: 1rem;
        }
        .avatar-preview {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #36e2c9;
            display: none;
        }
        .avatar-placeholder {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4f8cff, #36e2c9);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 22px;
            font-weight: 800;
            font-family: 'Sora', sans-serif;
        }
       /* Inline styles search karne ki zaroorat nahi, bas ye 1 rule CSS me daal do */
.form-control, .form-select {
    height: 38px !important;
    margin-bottom: 0 !important;
}
        .package-highlight {
            background: linear-gradient(135deg, #36e2c922, #4f8cff22);
            border: 1px solid #36e2c955;
            border-radius: 8px;
            padding: 4px 12px;
            font-weight: 700;
            color: #36e2c9;
            font-family: 'Sora', sans-serif;
        }
        .required { color: #dc3545; }
        #studentSearch { border-radius: 8px; }
    </style>
</head>
<body>
<div class="container-scroller">
    <?php include('../partials/_navbar.php'); ?>
    <div class="container-fluid page-body-wrapper">
        <?php include('../partials/_sidebar.php'); include('../partials/_settings-panel.php'); ?>
        <div class="main-panel">
            <div class="content-wrapper">
                <div class="row">

                    <!-- ── ADD / EDIT FORM ── -->
                    <div class="col-12 grid-margin">
                        <div class="card ps-card">
                            <div class="card-header d-flex align-items-center gap-2">
                                <i class="fa-solid fa-graduation-cap"></i>
                                <span>Add Placed Student</span>
                            </div>
                            <div class="card-body">
                                <form id="placedStudentForm" enctype="multipart/form-data">
                                    <input type="hidden" name="id" id="formId" value="">

                                    <div class="row g-3">

                                        <!-- Student Search / Link -->
                                        <div class="col-md-6" style="position:relative;">
                                            <label class="form-label fw-semibold">Link to Intern Record <span class="text-muted" style="font-weight:400;">(optional)</span></label>
                                            <div class="input-group">
                                                <input type="text" class="form-control" id="studentSearch" placeholder="Search intern by name..." autocomplete="off">
                                                <button class="btn btn-outline-secondary" type="button" id="clearStudentLink"><i class="fa fa-times"></i></button>
                                            </div>
                                            <input type="hidden" name="student_user_id" id="student_user_id">
                                            <div id="studentSuggestions" class="list-group mt-1" style="position:absolute;z-index:999;width:93%;max-height:200px;overflow-y:auto;display:none;"></div>
                                            <small class="text-muted">If found, student details auto-fill below.</small>
                                        </div>

                                        <!-- Photo Upload -->
                                        <div class="col-md-6">
                                            <label class="form-label fw-semibold">Student Photo</label>
                                            <div class="d-flex align-items-center gap-3">
                                                <div id="avatarHolder">
                                                    <div class="avatar-placeholder" id="avatarPlaceholder">📷</div>
                                                    <img id="avatarPreview" class="avatar-preview" src="" alt="Preview">
                                                </div>
                                                <div class="flex-grow-1">
                                                    <input type="file" class="form-control" name="photo" id="photoInput" accept="image/jpeg,image/png,image/gif,image/webp">
                                                    <small class="text-muted">JPG/PNG/WebP, max 5MB</small>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Student Name -->
                                        <div class="col-md-6">
                                            <label class="form-label fw-semibold">Student Full Name <span class="required">*</span></label>
                                            <input type="text" class="form-control" name="student_name" id="student_name" required placeholder="Full name">
                                        </div>

                                        <!-- Branch -->
                                        <div class="col-md-3">
                                            <label class="form-label fw-semibold">Branch</label>
                                            <select class="form-control form-select" name="branch" id="branch">
                                                <option value="">— Select Branch —</option>
                                                <?php foreach ($branches as $br): ?>
                                                    <option value="<?= htmlspecialchars($br) ?>"><?= htmlspecialchars($br) ?></option>
                                                <?php endforeach; ?>
                                                <option value="Other">Other</option>
                                            </select>
                                        </div>

                                        <!-- Placement Date -->
                                        <div class="col-md-3">
                                            <label class="form-label fw-semibold">Placement Date</label>
                                            <input type="date" class="form-control" name="placement_date" id="placement_date">
                                        </div>

                                        <!-- Company -->
                                        <div class="col-md-4">
                                            <label class="form-label fw-semibold">Company Name <span class="required">*</span></label>
                                            <input type="text" class="form-control" name="company_name" id="company_name" required placeholder="e.g. Infosys, TCS, Wipro">
                                        </div>

                                        <!-- Role -->
                                        <div class="col-md-4">
                                            <label class="form-label fw-semibold">Role Offered</label>
                                            <input type="text" class="form-control" name="role_offered" id="role_offered" placeholder="e.g. Software Engineer">
                                        </div>

                                        <!-- Package -->
                                        <div class="col-md-4">
                                            <label class="form-label fw-semibold">Package / CTC</label>
                                            <input type="text" class="form-control" name="package_ctc" id="package_ctc" placeholder="e.g. 4.5 LPA">
                                        </div>

                                        <!-- Remarks -->
                                        <div class="col-12">
                                            <label class="form-label fw-semibold">Remarks / Notes</label>
                                            <textarea class="form-control" name="remarks" id="remarks" rows="3" placeholder="Any additional notes..."></textarea>
                                        </div>

                                    </div>

                                    <div class="mt-4 d-flex gap-2 flex-wrap">
                                        <button type="submit" class="btn btn-primary" id="saveBtn">
                                            <i class="fa-solid fa-save me-1"></i> Save Record
                                        </button>
                                        <button type="button" class="btn btn-secondary" id="resetFormBtn">
                                            <i class="fa-solid fa-rotate-left me-1"></i> Reset
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- ── FILTER BAR ── -->
                    <div class="col-12 mb-3">
                        <div class="card ps-card">
                            <div class="card-body py-3">
                                <div class="row g-2 align-items-end">
                                    <div class="col-md-3">
                                        <label class="form-label mb-1 small fw-semibold">Filter by Branch</label>
                                        <select class="form-control form-select form-select-sm" id="filterBranch">
                                            <option value="">All Branches</option>
                                            <?php foreach ($branches as $br): ?>
                                                <option value="<?= htmlspecialchars($br) ?>"><?= htmlspecialchars($br) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label mb-1 small fw-semibold">From Date</label>
                                        <input type="date" class="form-control form-control-sm" id="filterFrom">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label mb-1 small fw-semibold">To Date</label>
                                        <input type="date" class="form-control form-control-sm" id="filterTo">
                                    </div>
                                    <div class="col-md-3">
                                        <button class="btn btn-sm btn-primary w-100" id="applyFilterBtn"><i class="fa fa-search me-1"></i>Apply Filter</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── LIST TABLE ── -->
                    <div class="col-12 grid-margin">
                        <div class="card ps-card">
                            <div class="card-header d-flex align-items-center gap-2">
                                <i class="fa-solid fa-list"></i>
                                <span>All Placed Students</span>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover" id="psTable" width="100%">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Photo</th>
                                                <th>Name</th>
                                                <th>Branch</th>
                                                <th>Company</th>
                                                <th>Role</th>
                                                <th>Package</th>
                                                <th>Date</th>
                                                <th>Status</th>
                                                <th>Actions</th>
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

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form id="editForm" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Placed Student</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="editModalBody">
                    <div class="text-center"><div class="spinner-border text-primary"></div></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="editSaveBtn">Save Changes</button>
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
$(function() {

    // ── DataTable init ──
    let psTable = $('#psTable').DataTable({
        ajax: { url: '../ajax/get_placed_students.php', dataSrc: '' },
        columns: [
            { data: 'sr_no' },
            { data: 'photo', orderable: false },
            { data: 'student_name' },
            { data: 'branch' },
            { data: 'company_name' },
            { data: 'role_offered' },
            { data: 'package_ctc' },
            { data: 'placement_date' },
            { data: 'status', orderable: false },
            { data: 'actions', orderable: false }
        ],
        order: [[0, 'desc']],
        language: { emptyTable: 'No placed students added yet.' }
    });

    // ── Filter ──
    $('#applyFilterBtn').click(function() {
        let params = new URLSearchParams({
            branch:    $('#filterBranch').val(),
            date_from: $('#filterFrom').val(),
            date_to:   $('#filterTo').val()
        });
        psTable.ajax.url('../ajax/get_placed_students.php?' + params.toString()).load();
    });

    // ── Photo preview ──
    $('#photoInput').change(function() {
        const file = this.files[0];
        if (file) {
            const url = URL.createObjectURL(file);
            $('#avatarPreview').attr('src', url).show();
            $('#avatarPlaceholder').hide();
        }
    });

    // ── Student search autocomplete ──
    let searchTimer;
    let employeeDataCache = null;

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function renderStudentSuggestions(data, q) {
        if (!data || !Array.isArray(data)) {
            $('#studentSuggestions').html('<div class="list-group-item text-muted">No matches found</div>').show();
            return;
        }
        const results = data.filter(e => e.fullName && e.fullName.toLowerCase().includes(q.toLowerCase())).slice(0, 10);
        let html = results.map(e => {
            const desigText = e.designation ? e.designation : 'Intern/Employee';
            const branchText = e.branch ? ' — ' + e.branch : '';
            return `<a href="javascript:void(0);" class="list-group-item list-group-item-action py-2 student-pick" data-id="${e.userId || ''}" data-name="${escapeHtml(e.fullName)}" data-branch="${escapeHtml(e.branch || '')}">
                <strong>${escapeHtml(e.fullName)}</strong> <small class="text-muted">(${escapeHtml(desigText)}${escapeHtml(branchText)})</small>
            </a>`;
        }).join('') || '<div class="list-group-item text-muted">No matches found</div>';
        $('#studentSuggestions').html(html).show();
    }

    $('#studentSearch').on('input focus', function() {
        clearTimeout(searchTimer);
        const q = $(this).val().trim();
        if (q.length < 2) { $('#studentSuggestions').hide(); return; }

        searchTimer = setTimeout(function() {
            if (employeeDataCache) {
                renderStudentSuggestions(employeeDataCache, q);
            } else {
                $.ajax({
                    url: '../ajax/get_employees_for_announcement.php',
                    type: 'GET',
                    dataType: 'json',
                    cache: false,
                    success: function(data) {
                        employeeDataCache = data;
                        renderStudentSuggestions(data, q);
                    },
                    error: function() {
                        $('#studentSuggestions').html('<div class="list-group-item text-danger small">Error loading records</div>').show();
                    }
                });
            }
        }, 150);
    });

    $(document).on('click', '.student-pick', function(e) {
        e.preventDefault();
        const id = $(this).data('id');
        const name = $(this).data('name');
        const branch = $(this).data('branch');

        $('#student_user_id').val(id);
        $('#studentSearch').val(name);
        $('#student_name').val(name);

        if (branch) {
            const $option = $('#branch option').filter(function() {
                return $(this).val().toLowerCase() === String(branch).toLowerCase();
            });
            if ($option.length > 0) {
                $('#branch').val($option.val());
            }
        }

        $('#studentSuggestions').hide();
    });

    $('#clearStudentLink').click(function() {
        $('#student_user_id').val('');
        $('#studentSearch').val('');
        $('#studentSuggestions').hide();
    });

    $(document).on('click', function(e) {
        if (!$(e.target).closest('#studentSearch, #studentSuggestions').length) {
            $('#studentSuggestions').hide();
        }
    });

    // ── Save form ──
    $('#placedStudentForm').submit(function(e) {
        e.preventDefault();
        const fd = new FormData(this);
        const btn = $('#saveBtn');
        btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Saving...');

        $.ajax({
            url: '../ajax/save_placed_student.php',
            type: 'POST', data: fd, processData: false, contentType: false, dataType: 'json',
            success: function(res) {
                if (res && res.success) {
                    Swal.fire('Saved!', res.msg, 'success');
                    $('#placedStudentForm')[0].reset();
                    $('#avatarPreview').hide();
                    $('#avatarPlaceholder').show();
                    $('#student_user_id').val('');
                    $('#studentSearch').val('');
                    psTable.ajax.reload();

                    if(typeof psTable !== 'undefined'){
                        psTable.ajax.reload();
                    }else{
                        Swal.fire('Saved!', res.msg, 'success');
                        window.location.href = 'ams_placed_students.php';
                    }
                } else {
                    Swal.fire('Error', d.msg, 'error');
                }
                btn.prop('disabled', false).html('<i class="fa-solid fa-save me-1"></i> Save Record');
            },
            error: function() {
                Swal.fire('Error', 'Server error.', 'error');
                btn.prop('disabled', false).html('<i class="fa-solid fa-save me-1"></i> Save Record');
            }
        });
    });

    // ── Reset ──
    $('#resetFormBtn').click(function() {
        $('#placedStudentForm')[0].reset();
        $('#formId').val('');
        $('#avatarPreview').hide();
        $('#avatarPlaceholder').show();
        $('#student_user_id').val('');
        $('#studentSearch').val('');
    });

    // ── Edit ──
    $(document).on('click', '.edit-student', function() {
        const id = $(this).data('id');
        $('#editModalBody').html('<div class="text-center p-4"><div class="spinner-border text-primary"></div></div>');
        $('#editModal').modal('show');

        $.getJSON('../ajax/get_placed_student_by_id.php', { id }, function(res) {
            if (!res.success) { $('#editModalBody').html('<p class="text-danger">Failed to load record.</p>'); return; }
            const r = res.data;
            const photoHtml = r.photo_path
                ? `<img src="../pages/${r.photo_path}" style="width:64px;height:64px;border-radius:50%;object-fit:cover;border:2px solid #36e2c9;" id="editPhotoPreview">`
                : `<div style="width:64px;height:64px;border-radius:50%;background:linear-gradient(135deg,#4f8cff,#36e2c9);display:flex;align-items:center;justify-content:center;color:#fff;font-size:22px;font-weight:800;" id="editPhotoPreview">${(r.student_name||'?')[0].toUpperCase()}</div>`;

            $('#editModalBody').html(`
                <input type="hidden" name="id" value="${r.id}">
                <div class="row g-3">
                    <div class="col-12 d-flex align-items-center gap-3">
                        ${photoHtml}
                        <div class="flex-grow-1">
                            <label class="form-label fw-semibold mb-1">Replace Photo</label>
                            <input type="file" class="form-control" name="photo" accept="image/jpeg,image/png,image/gif,image/webp" id="editPhotoInput">
                            <small class="text-muted">Leave empty to keep current photo</small>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Student Name *</label>
                        <input type="text" class="form-control" name="student_name" value="${r.student_name||''}" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Branch</label>
                        <select class="form-control form-select" name="branch">
                            <option value="">— Select Branch —</option>
                            <?php foreach ($branches as $br): ?>
                                <option value="<?= htmlspecialchars($br) ?>" ${r.branch==='<?= htmlspecialchars($br) ?>'?'selected':''}><?= htmlspecialchars($br) ?></option>
                            <?php endforeach; ?>
                            <option value="Other" ${r.branch==='Other'?'selected':''}>Other</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Placement Date</label>
                        <input type="date" class="form-control" name="placement_date" value="${r.placement_date||''}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Company *</label>
                        <input type="text" class="form-control" name="company_name" value="${r.company_name||''}" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Role Offered</label>
                        <input type="text" class="form-control" name="role_offered" value="${r.role_offered||''}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Package CTC</label>
                        <input type="text" class="form-control" name="package_ctc" value="${r.package_ctc||''}">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Status</label>
                        <select class="form-select" name="status">
                            <option value="active" ${r.status==='active'?'selected':''}>Active</option>
                            <option value="inactive" ${r.status==='inactive'?'selected':''}>Inactive</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Remarks</label>
                        <textarea class="form-control" name="remarks" rows="3">${r.remarks||''}</textarea>
                    </div>
                </div>
            `);

            // Preview on new photo select
            $('#editPhotoInput').change(function() {
                const f = this.files[0];
                if (f) {
                    const url = URL.createObjectURL(f);
                    $('#editPhotoPreview').replaceWith(`<img src="${url}" style="width:64px;height:64px;border-radius:50%;object-fit:cover;border:2px solid #36e2c9;" id="editPhotoPreview">`);
                }
            });
        });
    });

    // Edit form submit
    $('#editForm').submit(function(e) {
        e.preventDefault();
        const fd = new FormData(this);
        const btn = $('#editSaveBtn');
        btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Saving...');

        $.ajax({
            url: '../ajax/update_placed_student.php',
            type: 'POST', data: fd, processData: false, contentType: false,
            success: function(res) {
                const d = JSON.parse(res);
                if (d.success) {
                    Swal.fire('Updated!', d.msg, 'success');
                    $('#editModal').modal('hide');
                    psTable.ajax.reload(null, false);
                } else {
                    Swal.fire('Error', d.msg, 'error');
                }
                btn.prop('disabled', false).html('Save Changes');
            },
            error: function() {
                Swal.fire('Error', 'Server error.', 'error');
                btn.prop('disabled', false).html('Save Changes');
            }
        });
    });

    // ── Delete ──
    $(document).on('click', '.delete-student', function() {
        const id = $(this).data('id');
        Swal.fire({
            title: 'Delete this record?',
            text: 'This cannot be undone.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'Yes, delete!'
        }).then(result => {
            if (result.isConfirmed) {
                $.post('../ajax/delete_placed_student.php', { id }, function(res) {
                    const d = JSON.parse(res);
                    if (d.success) {
                        Swal.fire('Deleted!', d.msg, 'success');
                        psTable.ajax.reload(null, false);
                    } else {
                        Swal.fire('Error', d.msg, 'error');
                    }
                });
            }
        });
    });

});
</script>
</body>
</html>
