<?php
session_start();
if(!isset($_SESSION['userId']) && empty($_SESSION['userId'])){
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}
// role based access
else if($_SESSION['userRole']!=='senior_intern' && $_SESSION['userRole']!=='hr' && $_SESSION['userRole']!=='admin' && $_SESSION['userRole']!=='resourceAllocator' && $_SESSION['userRole']!=='mentor') {
    echo '<script>
        alert("You are not authorized to access this page.");
        window.location.href = "login.php";
        </script>';
    die();
}
include('../include/db_config.php');

// Pre-fetch Analytics & Data for KPIs and Charts
$userRole = $_SESSION['userRole'] ?? '';
$currentUserId = $_SESSION['userId'] ?? '';

// Fetch all active projects with their latest progress
$analyticsSql = "
SELECT 
    pm.projectId, 
    pm.projectName, 
    pm.deadline,
    pm.priority,
    COALESCE(pp.progress, 0) as progress,
    pp.remark,
    pp.modificationDate,
    pp.userId
FROM projectMaster pm
LEFT JOIN projectProgress pp ON pm.projectId = pp.projectId 
    AND pp.modificationDate = (
        SELECT MAX(modificationDate) 
        FROM projectProgress 
        WHERE projectId = pm.projectId
    )
WHERE (pm.status = 'Active' OR pm.status = 'active' OR pm.status = 1)
ORDER BY pm.projectName ASC";

$analyticsRes = mysqli_query($con, $analyticsSql);
$projectsData = [];
$totalProjects = 0;
$totalProgressSum = 0;
$completedCount = 0;
$onTrackCount = 0;
$behindCount = 0;
$notStartedCount = 0;

if ($analyticsRes) {
    while ($row = mysqli_fetch_assoc($analyticsRes)) {
        $prog = floatval($row['progress']);
        $totalProjects++;
        $totalProgressSum += $prog;

        // Health & deadline calculation logic
        $deadlineTime = !empty($row['deadline']) ? strtotime($row['deadline']) : null;
        $isOverdueOrNear = $deadlineTime && ($deadlineTime < time() || ($deadlineTime - time()) < (5 * 86400));

        if ($prog >= 100) {
            $completedCount++;
            $statusTag = 'Completed';
            $statusBadgeClass = 'badge-success';
        } elseif ($prog == 0) {
            $notStartedCount++;
            $statusTag = 'Not Started';
            $statusBadgeClass = 'badge-secondary';
        } elseif ($prog < 50 && $isOverdueOrNear) {
            $behindCount++;
            $statusTag = 'Needs Attention';
            $statusBadgeClass = 'badge-danger';
        } else {
            $onTrackCount++;
            $statusTag = 'On Track';
            $statusBadgeClass = 'badge-info';
        }

        $row['healthStatus'] = $statusTag;
        $row['statusBadgeClass'] = $statusBadgeClass;
        $projectsData[] = $row;
    }
}

$avgProgress = $totalProjects > 0 ? round($totalProgressSum / $totalProjects, 1) : 0;
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>Project Progress Analytics</title>
    <?php include('../partials/_links.php'); ?>

    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-beta.1/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.1/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.4.1/css/responsive.bootstrap4.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
    .analytics-card {
        border-radius: 12px;
        border: none;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
        background: #fff;
    }

    .analytics-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
    }

    .stat-icon-wrapper {
        width: 48px;
        height: 48px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
    }

    /* Checklist Card Style */
    .checklist-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
        gap: 12px;
    }

    .checklist-item-card {
        background: #f8f9fa;
        border: 1px solid #e9ecef;
        border-radius: 8px;
        padding: 10px 14px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        cursor: pointer;
        transition: all 0.2s ease;
        user-select: none;
    }

    .checklist-item-card:hover {
        background: #f1f3f5;
        border-color: #ced4da;
    }

    .checklist-item-card input[type="checkbox"] {
        width: 18px;
        height: 18px;
        cursor: pointer;
        accent-color: #4b49ac;
    }

    .checklist-item-card.checked {
        background: #eef2ff;
        border-color: #4b49ac;
    }

    /* Select2 custom styling */
    .select2-container--default .select2-selection--single {
        height: 44px !important;
        border: 1px solid #ced4da !important;
        border-radius: 6px !important;
        padding: 8px 12px;
    }

    .select2-container--default .select2-selection--single .select2-selection__rendered {
        line-height: 26px !important;
        color: #495057;
    }

    .select2-container--default .select2-selection--single .select2-selection__arrow {
        height: 42px !important;
    }

    /* Table styling */
    #projectStatusTable {
        width: 100% !important;
        border-collapse: collapse;
    }

    #projectStatusTable thead th {
        background-color: #4b49ac;
        color: #ffffff;
        font-weight: 600;
        text-align: center;
        border: none;
        padding: 12px;
    }

    #projectStatusTable td {
        vertical-align: middle;
        padding: 12px;
    }

    .progress-bar-custom {
        height: 10px;
        border-radius: 5px;
        background-color: #e9ecef;
        overflow: hidden;
    }

    .progress-bar-custom .fill {
        height: 100%;
        transition: width 0.4s ease-in-out;
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
                
                <!-- Page Title -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h3 class="font-weight-bold text-dark mb-1" id="pageTitle">Project Progress & Analytics</h3>
                        <p class="text-muted mb-0">Track live progress, complete task checklists, and analyze project health.</p>
                    </div>
                    <div>
                        <span class="badge badge-outline-primary p-2"><i class="fa-solid fa-clock-rotate-left mr-1"></i> Real-time Dashboard</span>
                    </div>
                </div>

                <!-- KPI Metric Cards Row -->
                <div class="row mb-4">
                    <div class="col-md-3 grid-margin stretch-card mb-3 mb-md-0">
                        <div class="card analytics-card">
                            <div class="card-body d-flex align-items-center justify-content-between p-3">
                                <div>
                                    <p class="text-muted font-weight-medium mb-1">Total Active Projects</p>
                                    <h3 class="font-weight-bold mb-0 text-dark"><?php echo $totalProjects; ?></h3>
                                </div>
                                <div class="stat-icon-wrapper bg-light-primary text-primary">
                                    <i class="fa-solid fa-diagram-project"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3 grid-margin stretch-card mb-3 mb-md-0">
                        <div class="card analytics-card">
                            <div class="card-body d-flex align-items-center justify-content-between p-3">
                                <div>
                                    <p class="text-muted font-weight-medium mb-1">Avg Completion Rate</p>
                                    <h3 class="font-weight-bold mb-0 text-info"><?php echo $avgProgress; ?>%</h3>
                                </div>
                                <div class="stat-icon-wrapper bg-light-info text-info">
                                    <i class="fa-solid fa-chart-line"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3 grid-margin stretch-card mb-3 mb-md-0">
                        <div class="card analytics-card">
                            <div class="card-body d-flex align-items-center justify-content-between p-3">
                                <div>
                                    <p class="text-muted font-weight-medium mb-1">On Track & Done</p>
                                    <h3 class="font-weight-bold mb-0 text-success"><?php echo ($onTrackCount + $completedCount); ?></h3>
                                </div>
                                <div class="stat-icon-wrapper bg-light-success text-success">
                                    <i class="fa-solid fa-circle-check"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3 grid-margin stretch-card">
                        <div class="card analytics-card">
                            <div class="card-body d-flex align-items-center justify-content-between p-3">
                                <div>
                                    <p class="text-muted font-weight-medium mb-1">Attention Required</p>
                                    <h3 class="font-weight-bold mb-0 text-danger"><?php echo $behindCount; ?></h3>
                                </div>
                                <div class="stat-icon-wrapper bg-light-danger text-danger">
                                    <i class="fa-solid fa-triangle-exclamation"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Visual Analytics Charts Row -->
                <div class="row mb-4">
                    <div class="col-lg-7 grid-margin stretch-card mb-4 mb-lg-0">
                        <div class="card analytics-card h-100">
                            <div class="card-body">
                                <h4 class="card-title mb-3"><i class="fa-solid fa-chart-column mr-2 text-primary"></i>Project Progress Comparison</h4>
                                <div style="height: 250px; position: relative;">
                                    <canvas id="progressComparisonChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-5 grid-margin stretch-card">
                        <div class="card analytics-card h-100">
                            <div class="card-body">
                                <h4 class="card-title mb-3"><i class="fa-solid fa-chart-pie mr-2 text-primary"></i>Health Status Distribution</h4>
                                <div style="height: 250px; position: relative;" class="d-flex justify-content-center">
                                    <canvas id="statusDistributionChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Project Progress Form Card -->
                <div class="row">
                    <div class="col-12 grid-margin stretch-card mb-4">
                        <div class="card analytics-card">
                            <div class="card-body">
                                <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-2">
                                    <h4 class="card-title mb-0"><i class="fa-solid fa-pen-to-square mr-2 text-primary"></i>Update Project Progress</h4>
                                    <span class="text-muted small">Select a project to load its checklist and update completion status.</span>
                                </div>
                                
                                <form class="forms-sample" action="projectProgress-db.php" method="post" onsubmit="return validateForm()">
                                    <div class="row g-3">

                                        <!-- Select Project -->
                                        <div class="col-md-6 form-group">
                                            <label class="font-weight-bold text-dark">Select Project <span class="text-danger">*</span></label>
                                            <select class="js-example-basic-single w-100" required name="projectId" id="projectSelect" onchange="fetchProjectData()">
                                                <option selected disabled value="">-- Select Active Project --</option>
                                                <?php
                                                $projectSql = "
                                                SELECT DISTINCT pm.projectId, pm.projectName, pm.client, pm.status
                                                FROM projectMaster pm
                                                WHERE (pm.status = 'Active' OR pm.status = 'active' OR pm.status = 1)
                                                ORDER BY pm.projectName ASC";
                                                $q = mysqli_query($con, $projectSql);
                                                while ($r = mysqli_fetch_assoc($q)) {
                                                    $pName = $r['projectName'];
                                                    $pClient = trim($r['client'] ?? '');
                                                    $pLabel = (!empty($pClient) && strtoupper($pClient) !== 'NA') ? ($pName . ' - ' . $pClient) : $pName;
                                                    echo "<option value='{$r['projectId']}'>" . htmlspecialchars($pLabel) . "</option>";
                                                }
                                                ?>
                                            </select>
                                        </div>

                                        <!-- Calculated Progress Display -->
                                        <div class="col-md-6 form-group">
                                            <label class="font-weight-bold text-dark">Calculated Completion Progress</label>
                                            <div class="d-flex align-items-center mt-1">
                                                <div class="progress flex-grow-1 mr-3" style="height: 18px; border-radius: 9px; background: #e9ecef;">
                                                    <div id="progressBar" class="progress-bar bg-danger" role="progressbar" 
                                                        style="width: 0%; transition: width 0.4s ease;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
                                                    </div>
                                                </div>
                                                <span class="h4 font-weight-bold mb-0 text-dark" id="progressValue">0%</span>
                                            </div>
                                            <input type="hidden" name="progress" id="progress" value="0">
                                        </div>

                                        <!-- Progress Checklist Card Grid -->
                                        <div class="col-12 form-group">
                                            <label class="font-weight-bold text-dark mb-2">Progress Checklist Tasks</label>
                                            <input type="hidden" id="checklistArray" name="checklistArray" value="">
                                            
                                            <div id="progressChecklist" class="checklist-grid p-2 border rounded bg-light">
                                                <?php
                                                $checklistSql = "SELECT id, task_name, weight FROM checklist ORDER BY id ASC";
                                                $checklistResult = mysqli_query($con, $checklistSql);
                                                if ($checklistResult && mysqli_num_rows($checklistResult) > 0) {
                                                    while ($row = mysqli_fetch_assoc($checklistResult)) {
                                                        $taskId = htmlspecialchars($row['id']);
                                                        $taskName = htmlspecialchars($row['task_name']);
                                                        $weight = htmlspecialchars($row['weight']);
                                                        echo "
                                                        <div class='checklist-item-card' onclick='toggleCheckCard(this)'>
                                                            <div class='d-flex align-items-center'>
                                                                <input type='checkbox' class='progress-item mr-2' 
                                                                    data-task-id='{$taskId}' 
                                                                    data-weight='{$weight}' 
                                                                    value='{$taskId}' 
                                                                    name='checkedTasks[]'
                                                                    onclick='event.stopPropagation(); updateChecklistArray(); calculateProgress();'>
                                                                <span class='small font-weight-medium text-dark'>{$taskName}</span>
                                                            </div>
                                                            <span class='badge badge-light text-muted small ml-1'>{$weight}%</span>
                                                        </div>";
                                                    }
                                                } else {
                                                    echo "<p class='text-muted small p-2 mb-0'>No checklist items found in database.</p>";
                                                }
                                                ?>
                                            </div>
                                        </div>
                                        
                                        <!-- Remarks Textarea -->
                                        <div class="col-12 form-group">
                                            <label class="font-weight-bold text-dark">Remarks / Recent Updates</label>
                                            <textarea class="form-control" name="remark" id="remark" rows="3" placeholder="Enter key accomplishments, blockers, or notes for this project..."></textarea>
                                        </div>

                                        <!-- Hidden User ID -->
                                        <input type="hidden" name="userId" value="<?php echo htmlspecialchars($_SESSION['userId']); ?>">
                                        
                                    </div>

                                    <div class="mt-3">
                                        <button type="submit" id="submitBtn" class="btn btn-primary mr-2 px-4 shadow-sm" name="create">
                                            <i class="fa-solid fa-floppy-disk mr-1"></i> Save Progress Update
                                        </button>
                                        <button type="reset" class="btn btn-light border px-3" onclick="resetProgressForm()">
                                            <i class="fa-solid fa-rotate-left mr-1"></i> Reset
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Project Status Table -->
                    <div class="col-12 grid-margin stretch-card">
                        <div class="card analytics-card">
                            <div class="card-body">
                                <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-2">
                                    <h4 class="card-title mb-0"><i class="fa-solid fa-list-check mr-2 text-primary"></i>Active Projects Status & History</h4>
                                    <span class="text-muted small">Overview of current progress across active projects</span>
                                </div>

                                <div class="table-responsive">
                                    <table class="table table-hover table-striped" id="projectStatusTable">
                                        <thead>
                                            <tr>
                                                <th style="width: 5%;">Sr No.</th>
                                                <th style="width: 20%;">Project</th>
                                                <th style="width: 15%;">Health Status</th>
                                                <th style="width: 25%;">Progress Bar</th>
                                                <th style="width: 15%;">Deadline</th>
                                                <th style="width: 15%;">Last Remark</th>
                                                <th style="width: 5%;">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $sr_no = 1;
                                            foreach ($projectsData as $p) {
                                                $pId = htmlspecialchars($p['projectId']);
                                                $pName = htmlspecialchars($p['projectName']);
                                                $progVal = floatval($p['progress']);
                                                $rem = !empty($p['remark']) ? htmlspecialchars($p['remark']) : '-';
                                                
                                                // Color theme for progress bar
                                                $barBg = 'bg-danger';
                                                if ($progVal >= 80) {
                                                    $barBg = 'bg-success';
                                                } elseif ($progVal >= 40) {
                                                    $barBg = 'bg-warning';
                                                }

                                                // Deadline formatting
                                                $deadlineText = '-';
                                                $urgencyBadge = '';
                                                if (!empty($p['deadline'])) {
                                                    $dTime = strtotime($p['deadline']);
                                                    $deadlineText = date('d M Y', $dTime);
                                                    $now = time();
                                                    if ($dTime < $now && $progVal < 100) {
                                                        $urgencyBadge = "<span class='badge badge-danger ml-1 font-weight-normal'>Overdue</span>";
                                                    } elseif (($dTime - $now) <= (5 * 86400) && $progVal < 100) {
                                                        $daysLeft = ceil(($dTime - $now) / 86400);
                                                        $urgencyBadge = "<span class='badge badge-warning ml-1 font-weight-normal'>{$daysLeft}d left</span>";
                                                    }
                                                }

                                                echo "<tr>";
                                                echo "<td class='text-center font-weight-bold'>" . $sr_no++ . "</td>";
                                                echo "<td><div><strong>" . $pName . "</strong><br><small class='text-muted'>ID: " . $pId . "</small></div></td>";
                                                echo "<td class='text-center'><span class='badge " . $p['statusBadgeClass'] . " p-2'>" . $p['healthStatus'] . "</span></td>";
                                                echo "<td>
                                                        <div class='d-flex align-items-center'>
                                                            <div class='progress flex-grow-1 mr-2' style='height: 10px;'>
                                                                <div class='progress-bar {$barBg}' style='width: {$progVal}%;'></div>
                                                            </div>
                                                            <span class='font-weight-bold small'>{$progVal}%</span>
                                                        </div>
                                                      </td>";
                                                echo "<td>" . $deadlineText . " " . $urgencyBadge . "</td>";
                                                echo "<td><small class='text-wrap'>" . $rem . "</small></td>";
                                                echo "<td class='text-center'>
                                                        <button type='button' class='btn btn-outline-primary btn-sm rounded-circle' title='Quick Update' onclick='quickSelectProject(\"{$pId}\")'>
                                                            <i class='fa-solid fa-pen'></i>
                                                        </button>
                                                      </td>";
                                                echo "</tr>";
                                            }
                                            ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                </div> <!-- end of row -->
            </div>
        </div>
    </div>
</div>

    <!-- Script Links -->
    <script src="../vendors/js/vendor.bundle.base.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-beta.1/js/select2.min.js"></script>
    <script src="../js/off-canvas.js"></script>
    <script src="../js/hoverable-collapse.js"></script>
    <script src="../js/template.js"></script>
    <script src="../js/settings.js"></script>
    
    <!-- Data Table Scripts -->
    <script src="../vendors/datatables.net/jquery.dataTables.js"></script>
    <script src="../vendors/datatables.net-bs4/dataTables.bootstrap4.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.4.1/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.4.1/js/responsive.bootstrap4.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
    // Initialize Select2 & DataTables
    $(document).ready(function() {
        $('.js-example-basic-single').select2({
            placeholder: "-- Select Active Project --",
            allowClear: true
        });

        $('#projectStatusTable').DataTable({
            responsive: true,
            paging: true,
            pageLength: 10,
            searching: true,
            ordering: true,
            info: true,
            language: {
                search: "Search projects:",
                emptyTable: "No active projects found"
            }
        });

        // Initialize Analytical Charts
        initCharts();
    });

    // Form Client-side Validation
    function validateForm() {
        const projectId = document.getElementById("projectSelect").value;
        if (!projectId) {
            Swal.fire({
                icon: 'warning',
                title: 'Project Required',
                text: 'Please select a project from the dropdown before saving progress.',
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 3500
            });
            return false;
        }
        return true;
    }

    // Toggle checklist card visually when checkbox status changes
    function toggleCheckCard(cardEl) {
        const checkbox = cardEl.querySelector('.progress-item');
        if (checkbox) {
            checkbox.checked = !checkbox.checked;
            if (checkbox.checked) {
                cardEl.classList.add('checked');
            } else {
                cardEl.classList.remove('checked');
            }
            updateChecklistArray();
            calculateProgress();
        }
    }

    // Function to update hidden field with checked tasks
    function updateChecklistArray() {
        const checkboxes = document.querySelectorAll('.progress-item');
        const checkedTasks = [];
        
        checkboxes.forEach((checkbox) => {
            const card = checkbox.closest('.checklist-item-card');
            if (checkbox.checked) {
                checkedTasks.push(checkbox.value);
                if (card) card.classList.add('checked');
            } else {
                if (card) card.classList.remove('checked');
            }
        });

        document.getElementById('checklistArray').value = checkedTasks.join(',');
    }

    // Function to calculate progress
    function calculateProgress() {
        let totalWeight = 0;
        let completedWeight = 0;

        document.querySelectorAll('.progress-item').forEach(function (item) {
            let weight = parseFloat(item.getAttribute('data-weight')) || 0;
            totalWeight += weight;

            if (item.checked) {
                completedWeight += weight;
            }
        });

        let progress = totalWeight > 0 ? (completedWeight / totalWeight) * 100 : 0;
        progress = Math.min(Math.round(progress * 100) / 100, 100);

        document.getElementById('progress').value = progress;
        document.getElementById('progressValue').innerText = progress + '%';

        const progressBar = document.getElementById('progressBar');
        progressBar.style.width = progress + '%';
        progressBar.setAttribute('aria-valuenow', progress);

        // Dynamic Color Bar threshold
        progressBar.classList.remove('bg-danger', 'bg-warning', 'bg-success');
        if (progress >= 80) {
            progressBar.classList.add('bg-success');
        } else if (progress >= 40) {
            progressBar.classList.add('bg-warning');
        } else {
            progressBar.classList.add('bg-danger');
        }
    }

    // Fetch project progress data via AJAX
    function fetchProjectData() {
        const projectId = document.getElementById("projectSelect").value;

        if (projectId) {
            const xhr = new XMLHttpRequest();
            xhr.open("GET", "fetchProjectData.php?projectId=" + projectId, true);
            xhr.onreadystatechange = function () {
                if (xhr.readyState === 4 && xhr.status === 200) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        if (response.success) {
                            document.getElementById("remark").value = response.remark || '';
                            updateChecklist(response.checklist);
                        } else {
                            // Reset checklist and remarks if no existing entry
                            updateChecklist([]);
                            document.getElementById("remark").value = '';
                        }
                    } catch (e) {
                        console.error("Invalid response format:", xhr.responseText);
                    }
                }
            };
            xhr.send();
        }
    }

    // Update checklist based on fetched data
    function updateChecklist(checklistData) {
        const activeIds = (Array.isArray(checklistData) ? checklistData : String(checklistData).split(',')).map(id => String(id).trim());
        const checkboxes = document.querySelectorAll('.progress-item');
        
        checkboxes.forEach((checkbox) => {
            const taskId = String(checkbox.getAttribute('data-task-id'));
            const card = checkbox.closest('.checklist-item-card');
            
            if (activeIds.includes(taskId)) {
                checkbox.checked = true;
                if (card) card.classList.add('checked');
            } else {
                checkbox.checked = false;
                if (card) card.classList.remove('checked');
            }
        });

        updateChecklistArray();
        calculateProgress();
    }

    // Quick select project from table row action
    function quickSelectProject(projectId) {
        $('#projectSelect').val(projectId).trigger('change');
        $('html, body').animate({
            scrollTop: $("#pageTitle").offset().top - 20
        }, 400);
    }

    // Reset progress form
    function resetProgressForm() {
        $('#projectSelect').val('').trigger('change');
        document.getElementById('remark').value = '';
        updateChecklist([]);
    }

    // Initialize Analytical Charts
    function initCharts() {
        const rawData = <?php echo json_encode($projectsData); ?>;
        
        if (!rawData || rawData.length === 0) return;

        // Project labels and progress values for comparison chart
        const labels = rawData.map(p => p.projectName);
        const progressValues = rawData.map(p => parseFloat(p.progress));
        const bgColors = progressValues.map(v => v >= 80 ? '#28a745' : (v >= 40 ? '#ffc107' : '#dc3545'));

        // Chart 1: Progress Comparison Bar Chart
        const ctxBar = document.getElementById('progressComparisonChart').getContext('2d');
        new Chart(ctxBar, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Progress %',
                    data: progressValues,
                    backgroundColor: bgColors,
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        ticks: {
                            callback: function(value) { return value + "%"; }
                        }
                    }
                },
                plugins: {
                    legend: { display: false }
                }
            }
        });

        // Chart 2: Health Status Doughnut Chart
        const completed = <?php echo $completedCount; ?>;
        const onTrack = <?php echo $onTrackCount; ?>;
        const attention = <?php echo $behindCount; ?>;
        const notStarted = <?php echo $notStartedCount; ?>;

        const ctxDonut = document.getElementById('statusDistributionChart').getContext('2d');
        new Chart(ctxDonut, {
            type: 'doughnut',
            data: {
                labels: ['Completed', 'On Track', 'Needs Attention', 'Not Started'],
                datasets: [{
                    data: [completed, onTrack, attention, notStarted],
                    backgroundColor: ['#28a745', '#17a2b8', '#dc3545', '#6c757d'],
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
    }
    </script>

    <?php
    require_once('../include/sweetAlert.php');
    if (isset($_SESSION['status'])) {
        $status = $_SESSION['status'];
        $msg = $_SESSION['msg'];
        if ($status != true) {
            toastMsg('error', $msg);
        } else {
            toastMsg('success', $msg);
        }
        unset($_SESSION['status']);
        unset($_SESSION['msg']);
    }
    ?>
</body>
</html>