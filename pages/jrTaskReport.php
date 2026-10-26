<?php
session_start();
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
} elseif (isset($_SESSION['userRole']) && strtolower($_SESSION['userRole']) === 'junior_intern') {
    echo '<script>
        alert("You are not authorized to access this page.");
        window.location.href = "login.php";
        </script>';
    exit;
}

include('../include/db_config.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Junior Intern Task Report</title>
    <?php include('../partials/_links.php'); ?>
    <style>
        .card { border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 2px 10px rgba(0,0,0,0.03); }
        .card-title { font-size: 1.15rem; font-weight: 700; color: #1e293b; }
        .form-control { border-radius: 8px; border: 1px solid #cbd5e1; padding: 10px 14px; font-size: 0.88rem; }
        .form-control:focus { border-color: #4b49ac; box-shadow: 0 0 0 3px rgba(75,73,172,0.12); }
        
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

        #taskEntryTable thead th {
            background: #f8fafc; color: #475569; font-weight: 700;
            font-size: 0.78rem; text-transform: uppercase; border-bottom: 2px solid #e2e8f0;
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
                                <i class="fa-solid fa-clipboard-list text-primary me-2"></i> Junior Intern Task Report
                            </h3>
                            <p class="text-muted small mb-0">Track and review task entries, work duration, and live progress of junior interns.</p>
                        </div>
                    </div>

                    <!-- ── 1. KPI Summary Cards ── -->
                    <div class="row mb-4">
                        <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-lbl">Total Tasks</div>
                                    <div class="kpi-val text-primary" id="kpiTotalTasks">—</div>
                                    <small class="text-muted" style="font-size:0.72rem;">Filtered task entries</small>
                                </div>
                                <div class="kpi-icon" style="background:#EEEDFE; color:#4b49ac;">
                                    <i class="fa-solid fa-list-check"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-lbl">Finished Tasks</div>
                                    <div class="kpi-val text-success" id="kpiFinishedTasks">—</div>
                                    <small class="text-muted" style="font-size:0.72rem;">Completed work</small>
                                </div>
                                <div class="kpi-icon" style="background:#E6F4EA; color:#1E8E3E;">
                                    <i class="fa-solid fa-circle-check"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-lbl">Running Tasks</div>
                                    <div class="kpi-val text-info" id="kpiRunningTasks">—</div>
                                    <small class="text-muted" style="font-size:0.72rem;">Currently active</small>
                                </div>
                                <div class="kpi-icon" style="background:#E0F2FE; color:#0284C7;">
                                    <i class="fa-solid fa-play"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-lbl">Total Man-Hours</div>
                                    <div class="kpi-val text-warning" style="color:#b06000 !important;" id="kpiTotalHours">—</div>
                                    <small class="text-muted" style="font-size:0.72rem;">Combined work duration</small>
                                </div>
                                <div class="kpi-icon" style="background:#FEF7E0; color:#B06000;">
                                    <i class="fa-solid fa-stopwatch"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── 2. Filter Form Section ── -->
                    <div class="card mb-4">
                        <div class="card-body p-4">
                            <h4 class="card-title mb-3">
                                <i class="fa-solid fa-filter text-primary me-2"></i> Filter Task Reports
                            </h4>
                            <hr class="text-muted opacity-25 mb-4">

                            <form class="forms-sample" id="filterForm" method="post" onsubmit="return false;">
                                <div class="row align-items-end">
                                    <div class="col-md-4 mb-3 mb-md-0">
                                        <div class="form-group mb-0">
                                            <label for="internSelect" class="fw-bold text-secondary mb-1">Select Intern Name</label>
                                            <select class="form-control" id="internSelect" name="intern">
                                                <option value="" selected>-- All Junior Interns --</option>
                                                <?php
                                                $q = mysqli_query($con, "SELECT userId, fullName, internId, emp_id FROM signup WHERE LOWER(designation)='juniorintern' OR LOWER(userRole)='junior_intern' ORDER BY fullName ASC");
                                                if ($q) {
                                                    while ($r = mysqli_fetch_assoc($q)) {
                                                        echo "<option value='" . htmlspecialchars($r['userId']) . "'>" . htmlspecialchars($r['fullName']) . "</option>";
                                                    }
                                                }
                                                ?>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="col-md-3 mb-3 mb-md-0">
                                        <div class="form-group mb-0">
                                            <label for="fdate" class="fw-bold text-secondary mb-1">From Date</label>
                                            <input type="date" class="form-control" id="fdate" name="fdate">
                                        </div>
                                    </div>

                                    <div class="col-md-3 mb-3 mb-md-0">
                                        <div class="form-group mb-0">
                                            <label for="tdate" class="fw-bold text-secondary mb-1">To Date</label>
                                            <input type="date" class="form-control" id="tdate" name="tdate">
                                        </div>
                                    </div>

                                    <div class="col-md-2">
                                        <div class="d-flex align-items-center gap-2">
                                            <button type="button" id="searchBtn" class="btn btn-primary px-3 py-2 w-100" style="border-radius:8px; font-weight:600;">
                                                <i class="fa-solid fa-magnifying-glass me-1"></i> Search
                                            </button>
                                            <button type="button" id="clearBtn" class="btn btn-light px-3 py-2" style="border-radius:8px;" title="Reset Filters">
                                                <i class="fa-solid fa-rotate-left"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- ── 3. Task Audit Directory Table Card ── -->
                    <div class="card">
                        <div class="card-body p-4">
                            <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                                <div>
                                    <h4 class="card-title mb-0">
                                        <i class="fa-solid fa-tasks text-primary me-2"></i> Task Entry Logs
                                    </h4>
                                    <p class="text-muted small mb-0">Live task logs submitted by junior interns.</p>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <button type="button" class="btn btn-success text-white btn-sm px-3 py-2" id="exportExcelBtn" style="border-radius:6px;" title="Export to Excel">
                                        <i class="fa-solid fa-file-excel me-1"></i> Excel
                                    </button>
                                    <button type="button" class="btn btn-danger text-white btn-sm px-3 py-2" id="exportPdfBtn" style="border-radius:6px;" title="Download as PDF">
                                        <i class="fa-solid fa-file-pdf me-1"></i> PDF
                                    </button>
                                </div>
                            </div>
                            <hr class="text-muted opacity-25 mb-4">

                            <div class="table-responsive">
                                <table class="table table-hover align-middle w-100" id="taskEntryTable" style="font-size:0.85rem;">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Intern Name</th>
                                            <th>Project</th>
                                            <th>Work Type</th>
                                            <th>Task Description</th>
                                            <th>Man-Hours</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody></tbody>
                                </table>
                            </div>

                        </div>
                    </div>

                </div> <!-- Content Wrapper -->
            </div> <!-- Main Panel -->

        </div>
    </div>

    <!-- Vendors & Scripts -->
    <script src="../vendors/js/vendor.bundle.base.js"></script>
    <script src="../js/off-canvas.js"></script>
    <script src="../js/hoverable-collapse.js"></script>
    <script src="../js/template.js"></script>
    <script src="../js/settings.js"></script>

    <script src="../vendors/select2/select2.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.6.0/jspdf.plugin.autotable.min.js"></script>

    <script>
        $(document).ready(function () {
            if ($.fn.select2) {
                $('#internSelect').select2({
                    placeholder: "-- All Junior Interns --",
                    allowClear: true,
                    width: '100%'
                });
            }

            loadKPIStats();

            var table = $('#taskEntryTable').DataTable({
                processing: true,
                serverSide: true,
                serverMethod: 'post',
                ajax: {
                    url: '../ajax/jrTaskReport.php',
                    data: function (d) {
                        d.intern = $('#internSelect').val();
                        d.fdate  = $('#fdate').val();
                        d.tdate  = $('#tdate').val();
                    }
                },
                columns: [
                    { data: 'srNo' },
                    { data: 'fullName' },
                    { data: 'projectName' },
                    { data: 'workType' },
                    { data: 'task' },
                    { data: 'manHr' },
                    { data: 'status', orderable: false, searchable: false }
                ],
                order: [[0, 'asc']],
                pageLength: 10,
                lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
                responsive: true,
                language: {
                    search: "Filter Logs:",
                    lengthMenu: "Show _MENU_ logs",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries",
                    emptyTable: "No task logs found"
                }
            });

            $('#searchBtn').on('click', function () {
                table.ajax.reload();
                loadKPIStats();
            });

            $('#clearBtn').on('click', function () {
                $('#internSelect').val('').trigger('change');
                $('#fdate').val('');
                $('#tdate').val('');
                table.ajax.reload();
                loadKPIStats();
            });

            function loadKPIStats() {
                var intern = $('#internSelect').val() || '';
                var fdate  = $('#fdate').val() || '';
                var tdate  = $('#tdate').val() || '';

                $.ajax({
                    url: '../ajax/jrTaskReport.php?fetchStats=1',
                    type: 'GET',
                    data: { intern: intern, fdate: fdate, tdate: tdate },
                    success: function (res) {
                        $('#kpiTotalTasks').text(res.totalTasks.toLocaleString());
                        $('#kpiFinishedTasks').text(res.finishedTasks.toLocaleString());
                        $('#kpiRunningTasks').text(res.runningTasks.toLocaleString());
                        $('#kpiTotalHours').text(res.totalHours.toLocaleString() + ' hrs');
                    },
                    error: function () {
                        $('#kpiTotalTasks, #kpiFinishedTasks, #kpiRunningTasks, #kpiTotalHours').text('—');
                    }
                });
            }

            async function fetchAllTasksForExport() {
                const intern = encodeURIComponent($('#internSelect').val() || '');
                const fdate  = encodeURIComponent($('#fdate').val() || '');
                const tdate  = encodeURIComponent($('#tdate').val() || '');
                const url    = `../ajax/jrTaskReport.php?exportAll=1&intern=${intern}&fdate=${fdate}&tdate=${tdate}`;

                const resp = await fetch(url, { credentials: 'same-origin' });
                if (!resp.ok) throw new Error('Network response failed');
                return await resp.json();
            }

            $('#exportExcelBtn').on('click', async function () {
                try {
                    const all = await fetchAllTasksForExport();
                    if (!all || !all.length) {
                        alert('No records to export');
                        return;
                    }
                    const worksheet = XLSX.utils.json_to_sheet(all);
                    const workbook  = XLSX.utils.book_new();
                    XLSX.utils.book_append_sheet(workbook, worksheet, "Jr Task Report");
                    XLSX.writeFile(workbook, "Jr_Task_Report.xlsx");
                } catch (err) {
                    console.error(err);
                    alert('Export failed.');
                }
            });

            $('#exportPdfBtn').on('click', async function () {
                try {
                    const all = await fetchAllTasksForExport();
                    if (!all || !all.length) {
                        alert('No records to export');
                        return;
                    }
                    const { jsPDF } = window.jspdf;
                    const doc = new jsPDF('landscape');
                    doc.text("Junior Intern Task Report", 14, 15);

                    const tableData = all.map((r, idx) => [
                        idx + 1,
                        r.fullName,
                        r.projectName,
                        r.workType,
                        r.task,
                        r.manHr,
                        r.status
                    ]);

                    doc.autoTable({
                        head: [['#', 'Intern Name', 'Project Name', 'Work Type', 'Task Description', 'Man-Hours', 'Status']],
                        body: tableData,
                        startY: 25,
                    });

                    doc.save("Jr_Task_Report.pdf");
                } catch (err) {
                    console.error(err);
                    alert('PDF export failed.');
                }
            });
        });
    </script>
</body>
</html>