<?php
session_start();
if (!isset($_SESSION['userId']) && empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}
include('../include/db_config.php');

$userId     = $_SESSION['userId'];
$today_date = date('Y-m-d');

// Has the user punched in today?
$attQ   = mysqli_query($con, "SELECT punchInTime FROM attendance
                               WHERE userId = '" . mysqli_real_escape_string($con, $userId) . "'
                                 AND date = '$today_date'
                               LIMIT 1");
$attRow       = mysqli_fetch_assoc($attQ);
$hasPunchedIn = !empty($attRow['punchInTime']) && $attRow['punchInTime'] !== '00:00:00';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Task Entry</title>
    <?php include('../partials/_links.php') ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap4.min.css">

    <style>
        .btn-secondary { color: white; }
        #cancelBtn { display: none; }
        .form-control { height: 2.3rem; }
        .required { color: red; }

        .select2-container .select2-selection--single {
            height: 37px !important;
            display: flex;
            align-items: center;
        }

        .card {
            border: none;
            border-radius: 12px;
            background: #fff;
            box-shadow: 0 4px 12px rgba(0,0,0,0.06);
            padding: 15px;
        }

        /* ── DataTables integration ── */
        .dataTables_wrapper .dataTables_filter input {
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 4px 10px;
            font-size: 13px;
            outline: none;
        }
        .dataTables_wrapper .dataTables_filter input:focus {
            border-color: #103d9e;
            box-shadow: 0 0 0 2px rgba(16,61,158,.1);
        }
        .dataTables_wrapper .dataTables_length select {
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 3px 8px;
            font-size: 13px;
        }
        .dataTables_wrapper .dataTables_info { font-size: 12px; color: #6c757d; }
        .dataTables_wrapper .dataTables_paginate .paginate_button {
            border-radius: 5px !important;
            font-size: 12px;
        }
        .dataTables_wrapper .dataTables_paginate .paginate_button.current {
            background: #103d9e !important;
            border-color: #103d9e !important;
            color: #fff !important;
        }
        .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
            background: #e8eefb !important;
            border-color: #103d9e !important;
            color: #103d9e !important;
        }
        .dataTables_wrapper .dataTables_paginate .paginate_button.disabled,
        .dataTables_wrapper .dataTables_paginate .paginate_button.disabled:hover {
            color: #aaa !important; background: transparent !important; border-color: transparent !important;
        }

        /* Fix DataTables top-bar spacing */
        .dataTables_wrapper .row:first-child { margin-bottom: 10px; }
        #taskEntryTable_filter { margin-bottom: 0; }

        .table thead th {
            background-color: #103d9eff;
            color: #fff;
            font-weight: 600;
        }

        .table thead th.sorting::after,
        .table thead th.sorting_asc::after,
        .table thead th.sorting_desc::after {
            color: rgba(255,255,255,0.7) !important;
        }

        .table tbody tr:hover { background-color: #f1f5ff; }

        /* ── Filter Tabs ── */
        .filter-buttons {
            margin-bottom: 16px;
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
        }

        .filter-btn {
            padding: 7px 18px;
            border-radius: 6px;
            font-weight: 500;
            border: 1px solid #ddd;
            background: #f8f9fa;
            color: #495057;
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .filter-btn:hover { background: #e9ecef; transform: translateY(-1px); }

        .filter-btn.active {
            background: #103d9eff;
            color: white;
            border-color: #103d9eff;
        }

        .filter-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 20px;
            height: 20px;
            padding: 0 5px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 700;
            background: rgba(255,255,255,0.3);
            color: inherit;
        }

        .filter-btn:not(.active) .filter-badge {
            background: #103d9e;
            color: white;
        }

        .status-running { color: #28a745; font-weight: 600; }
        .status-pause   { color: #ffc107; font-weight: 600; }
        .status-end     { color: #dc3545; font-weight: 600; }

        .action-btn {
            padding: 5px 12px;
            font-size: 12px;
            border-radius: 4px;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            font-weight: 500;
        }

        .btn-running { background: #28a745; color: white; }
        .btn-pause   { background: #ffc107; color: #212529; }
        .btn-end     { background: #dc3545; color: white; }

        .action-btn:hover { opacity: 0.85; transform: translateY(-1px); }
        .action-btn:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }

        .live-timer {
            font-family: monospace;
            font-size: 13px;
            font-weight: 600;
            color: #28a745;
            background: #eafaf1;
            padding: 2px 7px;
            border-radius: 4px;
            letter-spacing: 1px;
        }

        #taskTableWrapper { min-height: 120px; }

        .toggle-btn {
            width: 22px; height: 22px;
            border-radius: 50%;
            border: 1.5px solid #103d9e;
            background: #fff;
            color: #103d9e;
            font-size: 14px;
            font-weight: 700;
            line-height: 1;
            cursor: pointer;
            padding: 0;
            flex-shrink: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }

        .toggle-btn:hover { background: #103d9e; color: #fff; }
        .toggle-btn.open { background: #dc3545; border-color: #dc3545; color: #fff; }
        .action-row td { padding: 4px 12px !important; background: transparent; border: none; }

        /* Punch-in warning banner */
        .punch-warning-banner {
            background: #fff8e1;
            border: 1.5px solid #ffc107;
            border-radius: 10px;
            padding: 12px 16px;
            margin-bottom: 16px;
            font-size: 13px;
            color: #856404;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .punch-warning-banner i { font-size: 18px; color: #ffc107; }

        /* Cache freshness indicator */
        .cache-hint {
            font-size: 11px;
            color: #adb5bd;
            margin-left: auto;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .cache-dot { width: 7px; height: 7px; border-radius: 50%; background: #28a745; display: inline-block; }
        .cache-dot.stale { background: #ffc107; }

        /* Action-rows are detached before DT init and reinserted after each draw */
        tr.action-row { display: none; }
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

                    <!-- ── FORM CARD ── -->
                    <div class="col-12 grid-margin stretch-card">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title">Add New Task</h4>

                                <?php if (!$hasPunchedIn): ?>
                                <div class="punch-warning-banner">
                                    <i class="mdi mdi-alert-circle-outline"></i>
                                    <span>You have <strong>not punched in</strong> yet today. Please punch in before starting a task.</span>
                                </div>
                                <?php endif; ?>

                                <div class="row">
                                    <!-- Task Type -->
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <label>Task Type <span class="required">*</span></label>
                                            <select class="js-example-basic-single w-100" id="taskType" name="taskType"
                                                <?php echo !$hasPunchedIn ? 'disabled' : ''; ?>>
                                                <option value="" selected disabled>-- Select Task Type --</option>
                                                <option value="Standup Meeting">Standup Meeting</option>
                                                <option value="Scrum Call">Scrum Call</option>
                                                <option value="Project Work">Project Work</option>
                                                <option value="Student Training">Student Training</option>
                                                <option value="Other">Other</option>
                                                <option value="Lunch Break">Lunch Break</option>
                                                <option value="Break">Break</option>
                                                <option value="CRM">CRM</option>
                                            </select>
                                        </div>
                                    </div>

                                    <!-- Dynamic Task -->
                                    <div class="col-md-4" id="taskContainer" style="display:none;">
                                        <div class="form-group">
                                            <label id="taskLabel">Select Task <span class="required">*</span></label>
                                            <select class="js-example-basic-single w-100" id="taskDropdown" name="task"></select>
                                            <input type="text" class="form-control" id="taskTextbox" name="task_other" placeholder="Enter other task" style="display:none;">
                                        </div>
                                    </div>

                                    <!-- Project -->
                                    <div class="col-md-4" id="projectContainer" style="display:none;">
                                        <div class="form-group">
                                            <label>Select Project <span class="required">*</span></label>
                                            <select class="js-example-basic-single w-100" id="projectId" name="projectId">
                                                <option value="" selected disabled>-- Select Project --</option>
                                                <optgroup label="Allocated Project">
                                                    <?php
                                                    $q = mysqli_query($con, "SELECT allocatedProject.projectId, projectMaster.projectName, projectMaster.client
                                                        FROM allocatedProject
                                                        LEFT JOIN projectMaster ON allocatedProject.projectId = projectMaster.projectId
                                                        WHERE userId = '$userId' AND projectMaster.status = 1 ORDER BY projectMaster.projectName ASC");
                                                    while ($r = mysqli_fetch_assoc($q)) {
                                                        $pName  = $r['projectName'];
                                                        $client = trim($r['client'] ?? '');
                                                        $label  = (!empty($client) && strtoupper($client) !== 'NA') ? "{$pName} - {$client}" : "{$pName}";
                                                        echo "<option value='{$r['projectId']}'>" . htmlspecialchars($label) . "</option>";
                                                    }
                                                    ?>
                                                </optgroup>
                                                <optgroup label="Unallocated Project">
                                                    <?php
                                                    $q = mysqli_query($con, "SELECT projectId, projectName, client FROM projectMaster WHERE status = 1 ORDER BY projectName ASC");
                                                    while ($r = mysqli_fetch_assoc($q)) {
                                                        $pName  = $r['projectName'];
                                                        $client = trim($r['client'] ?? '');
                                                        $label  = (!empty($client) && strtoupper($client) !== 'NA') ? "{$pName} - {$client}" : "{$pName}";
                                                        echo "<option value='{$r['projectId']}'>" . htmlspecialchars($label) . "</option>";
                                                    }
                                                    ?>
                                                </optgroup>
                                            </select>
                                        </div>
                                    </div>

                                    <!-- Remark -->
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <label>Remark</label>
                                            <textarea class="form-control" name="remark" id="remark" rows="1" placeholder="Enter brief remark (optional)..."></textarea>
                                        </div>
                                    </div>
                                </div>

                                <button type="button" id="startBtn" class="btn btn-primary mr-2"
                                    <?php echo !$hasPunchedIn ? 'disabled title="Please punch in first"' : ''; ?>>
                                    <i class="mdi mdi-play mr-1"></i> Start Task
                                </button>

                                <?php if (!$hasPunchedIn): ?>
                                <small class="text-muted ml-2">
                                    <i class="mdi mdi-information-outline"></i>
                                    Task entry is disabled until you punch in.
                                </small>
                                <?php endif; ?>

                            </div>
                        </div>
                    </div>

                    <!-- ── TASK TABLE CARD ── -->
                    <div class="col-lg-12 grid-margin stretch-card">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
                                    <h4 class="card-title mb-2 mb-md-0">Tasks</h4>
                                    <div class="d-flex align-items-center flex-wrap" style="gap: 8px;">
                                        <div class="d-flex align-items-center mr-2 my-1">
                                            <label for="filterFromDate" class="mb-0 text-muted small mr-2" style="font-weight: 600; white-space: nowrap;">From:</label>
                                            <input type="date" id="filterFromDate" class="form-control form-control-sm" max="<?php echo date('Y-m-d'); ?>" style="width: 145px; height: 34px; border-radius: 6px; border: 1px solid #ced4da;">
                                        </div>
                                        <div class="d-flex align-items-center mr-3 my-1">
                                            <label for="filterToDate" class="mb-0 text-muted small mr-2" style="font-weight: 600; white-space: nowrap;">To:</label>
                                            <input type="date" id="filterToDate" class="form-control form-control-sm" max="<?php echo date('Y-m-d'); ?>" style="width: 145px; height: 34px; border-radius: 6px; border: 1px solid #ced4da;">
                                        </div>
                                        <div class="d-flex align-items-center my-1">
                                            <button class="btn btn-sm btn-primary mr-2" id="filterApplyBtn" style="height: 34px; padding: 0 14px; border-radius: 6px; display: inline-flex; align-items: center; gap: 4px;" title="Apply Date Filter">
                                                <i class="mdi mdi-filter"></i> Filter
                                            </button>
                                            <button class="btn btn-sm btn-outline-secondary mr-2" id="filterClearBtn" style="height: 34px; padding: 0 14px; border-radius: 6px; display: inline-flex; align-items: center; gap: 4px;" title="Clear Date Filter">
                                                <i class="mdi mdi-close"></i> Clear
                                            </button>
                                            <button class="btn btn-sm btn-outline-secondary" id="refreshBtn" style="height: 34px; padding: 0 14px; border-radius: 6px; display: inline-flex; align-items: center; gap: 4px;" title="Refresh">
                                                <i class="mdi mdi-refresh"></i> Refresh
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <div class="filter-buttons">
                                    <button class="filter-btn active" data-filter="Running">
                                        <i class="mdi mdi-play-circle-outline"></i> Running
                                        <span class="filter-badge" id="badge-Running">0</span>
                                    </button>
                                    <button class="filter-btn" data-filter="Pause">
                                        <i class="mdi mdi-pause-circle-outline"></i> Paused
                                        <span class="filter-badge" id="badge-Pause">0</span>
                                    </button>
                                    <button class="filter-btn" data-filter="End">
                                        <i class="mdi mdi-stop-circle-outline"></i> Ended
                                        <span class="filter-badge" id="badge-End">0</span>
                                    </button>
                                </div>

                                <div id="taskTableWrapper">
                                    <!-- Empty/loading/error state shown here — never inside <tbody> so DT column count stays clean -->
                                    <div id="dtEmptyOverlay" style="display:none;"></div>
                                    <div class="table-responsive">
                                        <table class="table table-hover" id="taskEntryTable">
                                            <thead>
                                                <tr>
                                                    <th>Sr.</th>
                                                    <th>Work Type</th>
                                                    <th>Task</th>
                                                    <th>Project</th>
                                                    <th>Start Time</th>
                                                    <th>Man Hr.</th>
                                                    <th>End Time</th>
                                                    <th>Status</th>
                                                    <th>Remark</th>
                                                    <th>Action</th>
                                                </tr>
                                            </thead>
                                            <tbody id="taskTableBody">
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
</div>

<!-- Core JS -->
<script src="../vendors/js/vendor.bundle.base.js"></script>
<script src="../js/off-canvas.js"></script>
<script src="../js/hoverable-collapse.js"></script>
<script src="../js/template.js"></script>
<script src="../js/settings.js"></script>
<script src="../js/todolist.js"></script>
<!-- DataTables — vendor versions, consistent with _links.php CSS -->
<script src="../vendors/datatables.net/jquery.dataTables.js"></script>
<script src="../vendors/datatables.net-bs4/dataTables.bootstrap4.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
const PUNCHED_IN = <?php echo $hasPunchedIn ? 'true' : 'false'; ?>;

$(document).ready(function () {

    let currentFilter   = 'Running';
    let timerInterval   = null;
    let autoRefreshInterval = null;
    let dtInstance      = null;          // DataTables instance

    // ── CLIENT-SIDE CACHE ─────────────────────────────────────────────────────
    // Each entry: { html: string, running: n, pause: n, end: n, ts: Date.now() }
    // TTL: 45 s for Running (live timers tick), 90 s for Pause/End (static data)
    const CACHE_TTL = { Running: 45000, Pause: 90000, End: 90000 };
    const taskCache = {};                // keyed by filter name

    function cacheGet(filter) {
        const entry = taskCache[filter];
        if (!entry) return null;
        const ttl = CACHE_TTL[filter] ?? 60000;
        if (Date.now() - entry.ts > ttl) { delete taskCache[filter]; return null; }
        return entry;
    }

    function cacheSet(filter, html, running, pause, end) {
        taskCache[filter] = { html, running, pause, end, ts: Date.now() };
    }

    function cacheInvalidateAll() {
        Object.keys(taskCache).forEach(k => delete taskCache[k]);
    }

    function initOrReinitDT() {
        if (dtInstance) { try { dtInstance.destroy(); } catch(e) {} dtInstance = null; }

        dtInstance = $('#taskEntryTable').DataTable({
            paging:    true,
            pageLength: 10,
            lengthMenu: [[5, 10, 25, 50, -1], [5, 10, 25, 50, 'All']],
            searching:  true,
            ordering:   true,
            info:       true,
            responsive: true,
            autoWidth:  false,
            language: {
                emptyTable:  ' ',
                zeroRecords: 'No matching tasks found',
                info:        'Showing _START_ to _END_ of _TOTAL_ tasks',
                infoEmpty:   'No tasks available',
                search:      '<i class="mdi mdi-magnify"></i>',
                searchPlaceholder: 'Search tasks...',
                lengthMenu:  'Show _MENU_ tasks',
                paginate: { first: '«', last: '»', next: '›', previous: '‹' }
            },
            columnDefs: [
                { targets: [0, 5, 9], orderable: false },
                { targets: [0], width: '50px' }
            ],
            order: [[4, 'desc']],
            drawCallback: function() {
                startLiveTimers();
            }
        });
    }

    // ── APPLY HTML TO TABLE & REINIT DT ──────────────────────────────────────
    function applyTableHTML(html) {
        if (dtInstance) { try { dtInstance.destroy(); } catch(e) {} dtInstance = null; }

        const isEmptyState = html.indexOf('data-taskid') === -1;

        if (isEmptyState) {
            const inner = html.replace(/<tr[^>]*>|<\/tr>/gi, '').replace(/<td[^>]*>|<\/td>/gi, '');
            $('#taskTableBody').empty();
            $('#dtEmptyOverlay').html(inner).show();
            $('#taskEntryTable').hide();
        } else {
            $('#dtEmptyOverlay').hide().empty();
            $('#taskEntryTable').show();
            $('#taskTableBody').html(html);
        }

        initOrReinitDT();
        startLiveTimers();
    }

    // ── FETCH TASKS (with cache & date range) ─────────────────────────────────
    function loadTasks(filter, silent, forceRefresh) {
        const fromDate = $('#filterFromDate').val() || '';
        const toDate   = $('#filterToDate').val() || '';
        const cacheKey = filter + '_' + fromDate + '_' + toDate;

        const cached = forceRefresh ? null : cacheGet(cacheKey);

        if (cached) {
            // Serve from cache — instant render ONLY if this matches active filter tab
            if (filter === currentFilter) {
                applyTableHTML(cached.html);
                if (typeof showCacheHint === 'function') showCacheHint(true, cached.ts);
            }
            updateBadgeUI(cached.running, cached.pause, cached.end);
            checkRunningTasksForPunchOut(cached.running);
            return;
        }

        if (!silent && filter === currentFilter) {
            if (dtInstance) { try { dtInstance.destroy(); } catch(e) {} dtInstance = null; }
            $('#taskTableBody').empty();
            $('#taskEntryTable').hide();
            $('#dtEmptyOverlay').html('<div style="text-align:center;padding:40px 20px;color:#6c757d;"><i class="mdi mdi-loading mdi-spin" style="font-size:28px;"></i><p style="margin:8px 0 0;font-size:14px;">Loading...</p></div>').show();
        }
        if (filter === currentFilter) {
            clearInterval(timerInterval);
        }

        $.ajax({
            url: "../ajax/fetch_taskentrynew.php",
            type: "GET",
            data: { 
                filter: filter,
                fromDate: fromDate,
                toDate: toDate
            },
            success: function (data, textStatus, xhr) {
                const running = parseInt(xhr.getResponseHeader('X-Running-Count') ?? 0);
                const pause   = parseInt(xhr.getResponseHeader('X-Pause-Count')   ?? 0);
                const end     = parseInt(xhr.getResponseHeader('X-End-Count')     ?? 0);

                cacheSet(cacheKey, data, running, pause, end);
                updateBadgeUI(running, pause, end);
                checkRunningTasksForPunchOut(running);

                if (filter === currentFilter) {
                    applyTableHTML(data);
                    if (typeof showCacheHint === 'function') showCacheHint(false, Date.now());
                }
            },
            error: function () {
                if (filter === currentFilter) {
                    if (dtInstance) { try { dtInstance.destroy(); } catch(e) {} dtInstance = null; }
                    $('#taskTableBody').empty();
                    $('#taskEntryTable').hide();
                    $('#dtEmptyOverlay').html('<div style="text-align:center;padding:30px 20px;color:#dc3545;"><i class="mdi mdi-alert-circle-outline" style="font-size:28px;display:block;margin-bottom:8px;"></i>Failed to load tasks. <button class="btn btn-sm btn-outline-primary ml-2" onclick="loadTasks(\'' + filter + '\', false, true)">Retry</button></div>').show();
                }
            }
        });
    }

    // ── BADGE UPDATE ─────────────────────────────────────────────────────────
    function updateBadgeUI(running, pause, end) {
        $('#badge-Running').text(running);
        $('#badge-Pause').text(pause);
        $('#badge-End').text(end);
    }

    // ── PUNCH-OUT PREVENTION ──────────────────────────────────────────────────
    function checkRunningTasksForPunchOut(runningCount) {
        const punchOutButtons = $('button:contains("Punch Out"), a:contains("Punch Out"), [onclick*="Punch"], [onclick*="punch"], #punchOutBtn');
        if (runningCount > 0) {
            punchOutButtons.off('click').on('click', function (e) {
                e.preventDefault();
                e.stopImmediatePropagation();
                Swal.fire({
                    icon: 'warning',
                    title: 'Cannot Punch Out',
                    html: 'You have <strong>' + runningCount + '</strong> running task(s).<br>Please pause or end all running tasks before punching out.',
                    confirmButtonColor: '#103d9e',
                    confirmButtonText: 'OK'
                });
                return false;
            });
            punchOutButtons.css({ 'opacity': '0.6', 'cursor': 'not-allowed' });
        } else {
            punchOutButtons.off('click');
            punchOutButtons.css({ 'opacity': '1', 'cursor': 'pointer' });
        }
    }

    // ── LIVE TIMERS ───────────────────────────────────────────────────────────
    function startLiveTimers() {
        clearInterval(timerInterval);
        const timers = document.querySelectorAll('.live-timer');
        if (timers.length === 0) return;
        timerInterval = setInterval(function () {
            timers.forEach(function (el) {
                let secs = parseInt(el.getAttribute('data-seconds')) + 1;
                el.setAttribute('data-seconds', secs);
                const h = String(Math.floor(secs / 3600)).padStart(2, '0');
                const m = String(Math.floor((secs % 3600) / 60)).padStart(2, '0');
                const s = String(secs % 60).padStart(2, '0');
                el.textContent = h + ':' + m + ':' + s;
            });
        }, 1000);
    }

    // ── TOGGLE ACTION ROW ─────────────────────────────────────────────────────
    // Must work even after DT redraws — attached to document (event delegation)
    window.toggleActions = function (rowId, btn) {
        const row = document.getElementById(rowId);
        if (!row) return;
        const open = row.style.display === 'table-row';
        row.style.display = open ? 'none' : 'table-row';
        btn.classList.toggle('open', !open);
        btn.textContent = open ? '+' : '−';
    };

    // ── PUNCH-IN GUARD ────────────────────────────────────────────────────────
    function punchInGuard() {
        if (!PUNCHED_IN) {
            Swal.fire({
                icon: 'warning',
                title: 'Punch In Required',
                html: 'You have not punched in today.<br>Please <strong>punch in</strong> before performing any task actions.',
                confirmButtonColor: '#103d9e',
                confirmButtonText: 'OK'
            });
            return true;
        }
        return false;
    }

    // ── UPDATE STATUS (Pause / Resume / End) ──────────────────────────────────
    window.updateStatus = function (id, status) {
        if (punchInGuard()) return;

        const labels = { Paused: 'Pause', Running: 'Resume', Finished: 'End' };
        Swal.fire({
            title: 'Confirm',
            text: 'Are you sure you want to ' + (labels[status] || status) + ' this task?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#103d9e',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, proceed!'
        }).then(function (result) {
            if (!result.isConfirmed) return;
            $('.action-btn').prop('disabled', true);
            $.ajax({
                url: "../ajax/task_actions.php",
                type: "POST",
                dataType: "json",
                data: { action: 'updateStatus', id: id, status: status },
                success: function (res) {
                    if (res.success) {
                        let newFilter = currentFilter;
                        if (status === 'Paused')   newFilter = 'Pause';
                        if (status === 'Running')  newFilter = 'Running';
                        if (status === 'Finished') newFilter = 'End';
                        currentFilter = newFilter;
                        $('.filter-btn').removeClass('active');
                        $('.filter-btn[data-filter="' + newFilter + '"]').addClass('active');
                        // Invalidate entire cache — status change affects all tabs
                        cacheInvalidateAll();
                        Swal.fire({ icon: 'success', title: 'Done!', text: res.message, timer: 1200, showConfirmButton: false });
                        loadTasks(newFilter, false, true);
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: res.message });
                        $('.action-btn').prop('disabled', false);
                    }
                },
                error: function () {
                    Swal.fire({ icon: 'error', title: 'Server Error', text: 'Something went wrong.' });
                    $('.action-btn').prop('disabled', false);
                }
            });
        });
    };

    // ── FILTER BUTTONS ────────────────────────────────────────────────────────
    $('.filter-btn').click(function () {
        currentFilter = $(this).data('filter');
        $('.filter-btn').removeClass('active');
        $(this).addClass('active');
        loadTasks(currentFilter);   // will serve from cache if fresh
    });

    // ── REFRESH BUTTON ────────────────────────────────────────────────────────
    $('#refreshBtn').click(function () {
        const icon = $(this).find('i');
        icon.addClass('mdi-spin');
        cacheInvalidateAll();       // force network fetch
        loadTasks(currentFilter, false, true);
        setTimeout(() => icon.removeClass('mdi-spin'), 800);
    });

    // ── DATE FILTER CONTROLS & VALIDATION ─────────────────────────────────────
    const TODAY_DATE = '<?php echo date('Y-m-d'); ?>';

    function validateDateRange() {
        let fDate = $('#filterFromDate').val();
        let tDate = $('#filterToDate').val();
        let valid = true;

        // Prevent selecting future dates beyond today
        if (fDate && fDate > TODAY_DATE) {
            Swal.fire({ icon: 'warning', title: 'Invalid Date', text: 'Future dates are not allowed. Only dates up to today can be selected.', confirmButtonColor: '#103d9e' });
            $('#filterFromDate').val(TODAY_DATE);
            fDate = TODAY_DATE;
            valid = false;
        }

        if (tDate && tDate > TODAY_DATE) {
            if (valid) {
                Swal.fire({ icon: 'warning', title: 'Invalid Date', text: 'Future dates are not allowed. Only dates up to today can be selected.', confirmButtonColor: '#103d9e' });
            }
            $('#filterToDate').val(TODAY_DATE);
            tDate = TODAY_DATE;
            valid = false;
        }

        // Prevent From Date > To Date
        if (fDate && tDate && fDate > tDate) {
            Swal.fire({ icon: 'warning', title: 'Invalid Range', text: 'From Date cannot be greater than To Date.', confirmButtonColor: '#103d9e' });
            $('#filterToDate').val(fDate);
            valid = false;
        }

        // Adjust min/max bounds dynamically
        if (fDate) $('#filterToDate').attr('min', fDate);
        if (tDate) $('#filterFromDate').attr('max', tDate < TODAY_DATE ? tDate : TODAY_DATE);

        return valid;
    }

    $('#filterFromDate, #filterToDate').change(function () {
        validateDateRange();
        cacheInvalidateAll();
        loadTasks(currentFilter, false, true);
    });

    $('#filterApplyBtn').click(function () {
        validateDateRange();
        cacheInvalidateAll();
        loadTasks(currentFilter, false, true);
    });

    $('#filterClearBtn').click(function () {
        $('#filterFromDate').val('').removeAttr('min').attr('max', TODAY_DATE);
        $('#filterToDate').val('').removeAttr('min').attr('max', TODAY_DATE);
        cacheInvalidateAll();
        loadTasks(currentFilter, false, true);
    });

    // ── TASK TYPE CHANGE ──────────────────────────────────────────────────────
    $('#taskType').change(function () {
        let taskType = $(this).val();
        $('#taskContainer').hide();
        $('#taskTextbox').hide();
        $('#taskDropdown').hide();
        $('#projectContainer').hide();
        if (!taskType) return;
        if (taskType === 'Lunch Break' || taskType === 'Break' ||
            taskType === 'Standup Meeting' || taskType === 'Scrum Call' || taskType === 'CRM') {
            return;
        }
        if (taskType === 'Other') {
            $('#taskContainer').show();
            $('#taskLabel').html('Task <span class="required">*</span>');
            $('#taskTextbox').show();
            if ($('#taskDropdown').hasClass('select2-hidden-accessible')) $('#taskDropdown').select2('destroy');
            $('#taskDropdown').hide();
            return;
        }
        $('#taskContainer').show();
        $('#taskDropdown').show();
        $('#taskLabel').html('Select Task <span class="required">*</span>');
        if (!$('#taskDropdown').hasClass('select2-hidden-accessible')) {
            $('#taskDropdown').select2({ width: '100%' });
        }
        fetchTasks(taskType);
        if (taskType === 'Project Work') {
            $('#projectContainer').show();
        }
    });

    function fetchTasks(type) {
        $.ajax({
            url: "../ajax/fetch_tasks.php",
            type: "POST",
            data: { category: type },
            success: function (data) { $('#taskDropdown').html(data); }
        });
    }

    // ── START TASK ────────────────────────────────────────────────────────────
    $('#startBtn').click(function () {
        if (punchInGuard()) return;

        let workType  = $('#taskType').val();
        let task      = $('#taskDropdown').val() || $('#taskTextbox').val();
        let remark    = $('#remark').val();
        let projectId = $('#projectId').val();

        if (!workType) {
            Swal.fire({ icon: 'warning', title: 'Required', text: 'Please select a Task Type.' });
            return;
        }

        if (workType === 'Standup Meeting' || workType === 'Scrum Call' || workType === 'CRM') task = workType;
        if (workType === 'Lunch Break' || workType === 'Break') task = workType;

        if (!task) {
            Swal.fire({ icon: 'warning', title: 'Required', text: 'Please select or enter a task.' });
            return;
        }

        if (workType === 'Project Work' && !projectId) {
            Swal.fire({ icon: 'warning', title: 'Required', text: 'Please select a project.' });
            return;
        }

        $('#startBtn').prop('disabled', true).html('<i class="mdi mdi-loading mdi-spin"></i> Starting...');

        $.ajax({
            url: "../ajax/task_actions.php",
            type: "POST",
            dataType: "json",
            data: { action: 'start', workType: workType, task: task, remark: remark, projectId: projectId },
            success: function (res) {
                $('#startBtn').prop('disabled', false).html('<i class="mdi mdi-play mr-1"></i> Start Task');
                if (res.success) {
                    Swal.fire({ icon: 'success', title: 'Started!', text: res.message, timer: 1500, showConfirmButton: false });
                    $('#taskType').val(null).trigger('change');
                    $('#taskTextbox').val('').hide();
                    $('#taskDropdown').val(null);
                    $('#remark').val('');
                    $('#taskContainer, #projectContainer').hide();
                    $('.js-example-basic-single').val(null).trigger('change');
                    currentFilter = 'Running';
                    $('.filter-btn').removeClass('active');
                    $('.filter-btn[data-filter="Running"]').addClass('active');
                    cacheInvalidateAll();           // new task — bust cache
                    loadTasks('Running', false, true);
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: res.message });
                }
            },
            error: function () {
                $('#startBtn').prop('disabled', false).html('<i class="mdi mdi-play mr-1"></i> Start Task');
                Swal.fire({ icon: 'error', title: 'Server Error', text: 'Something went wrong.' });
            }
        });
    });

    // ── INIT SELECT2 ──────────────────────────────────────────────────────────
    $('.js-example-basic-single').select2({ placeholder: "-- Select Option --", allowClear: true, width: '100%' });

    // ── INITIAL LOAD ──────────────────────────────────────────────────────────
    loadTasks('Running');

    // ── PREFETCH other tabs after 3s (background, populates cache) ────────────
    setTimeout(function() {
        if (cacheGet('Pause') === null)  loadTasks('Pause',  true);
    }, 3000);
    // setTimeout(function() {
    //     if (cacheGet('End') === null)    loadTasks('End',    true);
    // }, 5000);

});
</script>

<?php
require_once('../include/sweetAlert.php');
if (isset($_SESSION['status'])) {
    $status = $_SESSION['status'];
    $msg    = $_SESSION['msg'];
    echo "<script>toastMsg('" . ($status ? 'success' : 'error') . "', '$msg');</script>";
    unset($_SESSION['status'], $_SESSION['msg']);
}
?>

</body>
</html>