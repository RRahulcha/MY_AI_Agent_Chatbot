<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Auth check
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}

// Role check
$userRole = $_SESSION['userRole'] ?? '';
$roleNormalized = strtolower(str_replace('_', '', $userRole));

if ($roleNormalized !== 'md' && $roleNormalized !== 'hr') {
    echo '<script>alert("You are not authorized to access this page.");window.location.href="logout.php";</script>';
    die();
}

include('../include/db_config.php');

// Fetch Team Leads
// Based on logic in teamLeadRequestAuditReport.php, Team Leads are:
$qLeads = mysqli_query($con, "
    SELECT DISTINCT userId, fullName, designation 
    FROM signup 
    WHERE userRole IN ('teamLead', 'departmentHead', 'manager', 'resourceAllocator', 'seniorIntern', 'senior_intern') 
    ORDER BY fullName ASC
");

$teamLeads = [];
if ($qLeads) {
    while ($row = mysqli_fetch_assoc($qLeads)) {
        $teamLeads[] = $row;
    }
}

// Fetch team leads who have filled task diary for today
$todayDate = date('Y-m-d');
$filledUserIds = [];
$qFilled = mysqli_query($con, "SELECT DISTINCT userId FROM task_diary WHERE date = '$todayDate' AND (is_deleted = 0 OR is_deleted IS NULL)");
if ($qFilled) {
    while ($rFilled = mysqli_fetch_assoc($qFilled)) {
        $filledUserIds[] = $rFilled['userId'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <!-- Required meta tags -->
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>MD Task Diary | Dashboard</title>
    <!-- plugins:css -->
    <?php include('../partials/_links.php'); ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <style>
        .page-header-custom {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .page-title-custom {
            font-size: 1.5rem;
            font-weight: 600;
            color: #111827;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .tl-list-container {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            height: calc(100vh - 180px);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .tl-list-header {
            padding: 16px;
            border-bottom: 1px solid #e5e7eb;
            background: #f9fafb;
            font-weight: 600;
        }

        .tl-list-body {
            flex: 1;
            overflow-y: auto;
            padding: 0;
            margin: 0;
            list-style: none;
        }

        .tl-item {
            padding: 12px 16px;
            border-bottom: 1px solid #f3f4f6;
            border-left: 4px solid transparent;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .tl-item:hover {
            background: #f0f7ff;
        }

        .tl-item.active {
            background: #e0efff;
            border-left: 4px solid #1977e1 !important;
        }

        .tl-item.not-filled {
            border-left: 4px solid #dc3545 !important;
            background: #fef2f2;
        }

        .tl-item.not-filled:hover {
            background: #fee2e2;
        }

        .tl-item.not-filled.active {
            background: #fde8e8;
            border-left: 4px solid #dc3545 !important;
        }

        .tl-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #1977e1;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1.1rem;
        }

        .tl-info {
            flex: 1;
        }

        .tl-name {
            font-weight: 600;
            color: #111827;
            font-size: 0.95rem;
            margin-bottom: 2px;
        }

        .tl-role {
            font-size: 0.75rem;
            color: #6b7280;
        }

        .task-panel-container {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            height: calc(100vh - 180px);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .task-panel-header {
            padding: 16px;
            border-bottom: 1px solid #e5e7eb;
            background: #f9fafb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .task-panel-title {
            font-weight: 600;
            font-size: 1.1rem;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .task-filters {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .task-filters input,
        .task-filters select {
            border: 1px solid #d1d5db;
            border-radius: 6px;
            padding: 6px 12px;
            font-size: 0.9rem;
            outline: none;
        }

        .task-filters input:focus,
        .task-filters select:focus {
            border-color: #1977e1;
            box-shadow: 0 0 0 2px rgba(25, 119, 225, 0.2);
        }

        /* Statistics Container */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 10px;
            padding: 12px 16px;
            background: #ffffff;
            border-bottom: 1px solid #e5e7eb;
        }

        @media (max-width: 1200px) {
            .stats-container {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (max-width: 768px) {
            .stats-container {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        .stat-card {
            border-radius: 8px;
            padding: 8px 10px;
            display: flex;
            align-items: center;
            gap: 10px;
            border: 1px solid #e2e8f0;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.05);
        }

        .stat-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
        }

        .stat-info {
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .stat-value {
            font-size: 1.1rem;
            font-weight: 700;
            line-height: 1.2;
        }

        .stat-label {
            font-size: 0.68rem;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            white-space: nowrap;
        }

        .stat-total {
            background: #eff6ff;
            border-color: #bfdbfe;
        }

        .stat-total .stat-icon {
            background: #2563eb;
            color: #ffffff;
        }

        .stat-total .stat-value {
            color: #1e40af;
        }

        .stat-completed {
            background: #f0fdf4;
            border-color: #bbf7d0;
        }

        .stat-completed .stat-icon {
            background: #16a34a;
            color: #ffffff;
        }

        .stat-completed .stat-value {
            color: #166534;
        }

        .stat-pending {
            background: #f8fafc;
            border-color: #cbd5e1;
        }

        .stat-pending .stat-icon {
            background: #64748b;
            color: #ffffff;
        }

        .stat-pending .stat-value {
            color: #334155;
        }

        .stat-postponed {
            background: #fffbeb;
            border-color: #fde68a;
        }

        .stat-postponed .stat-icon {
            background: #d97706;
            color: #ffffff;
        }

        .stat-postponed .stat-value {
            color: #92400e;
        }

        .stat-planned {
            background: #f0f9ff;
            border-color: #bae6fd;
        }

        .stat-planned .stat-icon {
            background: #0284c7;
            color: #ffffff;
        }

        .stat-planned .stat-value {
            color: #075985;
        }

        .stat-unplanned {
            background: #fdf2f8;
            border-color: #fbcfe8;
        }

        .stat-unplanned .stat-icon {
            background: #db2777;
            color: #ffffff;
        }

        .stat-unplanned .stat-value {
            color: #9d174d;
        }

        .task-panel-body {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
            background: #f8fafc;
        }

        /* Task Cards */
        .task-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.02);
            border-left: 4px solid #9ca3af;
        }

        .task-card.completed {
            border-left-color: #059669;
        }

        .task-card.postponed {
            border-left-color: #d97706;
        }

        .task-info {
            flex: 1;
            padding-right: 20px;
        }

        .task-desc {
            font-size: 1rem;
            color: #111827;
            font-weight: 500;
            margin-bottom: 8px;
        }

        .task-meta {
            display: flex;
            gap: 15px;
            font-size: 0.8rem;
            color: #6b7280;
            align-items: center;
        }

        .badge-type {
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            background: #f3f4f6;
            color: #374151;
        }

        .badge-type.planned {
            background: #e0f2fe;
            color: #0284c7;
        }

        .badge-type.unplanned {
            background: #fce7f3;
            color: #db2777;
        }

        .status-badge {
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .status-badge.pending {
            background: #f3f4f6;
            color: #4b5563;
            border: 1px solid #e5e7eb;
        }

        .status-badge.completed {
            background: #ecfdf5;
            color: #059669;
            border: 1px solid #a7f3d0;
        }

        .status-badge.postponed {
            background: #fffbeb;
            color: #d97706;
            border: 1px solid #fde68a;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #6b7280;
        }

        .empty-state i {
            font-size: 3rem;
            color: #d1d5db;
            margin-bottom: 15px;
        }

        .loader-spinner {
            text-align: center;
            padding: 40px;
        }

        .search-box {
            padding: 10px 16px;
            border-bottom: 1px solid #e5e7eb;
            background: #fff;
        }

        .search-box input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            outline: none;
            font-size: 0.9rem;
        }

        .search-box input:focus {
            border-color: #1977e1;
        }
    </style>
</head>

<body>
    <div class="container-scroller">
        <!-- partial:partials/_navbar.php -->
        <?php include('../partials/_navbar.php'); ?>

        <div class="container-fluid page-body-wrapper">
            <!-- partial:partials/_sidebar.php -->
            <?php include('../partials/_sidebar.php'); ?>

            <div class="main-panel">
                <div class="content-wrapper">

                    <div class="page-header-custom">
                        <h3 class="page-title-custom">
                            <i class="bi bi-journal-check" style="color: #1977e1;"></i> Task Diary Overview
                        </h3>
                    </div>

                    <div class="row">
                        <!-- Left Panel: Team Leads -->
                        <div class="col-md-3">
                            <div class="tl-list-container">
                                <div class="tl-list-header">
                                    Team Leads (<?= count($teamLeads) ?>)
                                </div>
                                <div class="search-box">
                                    <input type="text" id="tlSearch" placeholder="Search team lead..."
                                        onkeyup="filterLeads()">
                                </div>
                                <ul class="tl-list-body" id="tlList">
                                    <?php if (empty($teamLeads)): ?>
                                        <li class="empty-state" style="padding: 20px;">No team leads found.</li>
                                    <?php else: ?>
                                        <?php foreach ($teamLeads as $tl):
                                            $initials = strtoupper(substr($tl['fullName'], 0, 1));
                                            $names = explode(' ', $tl['fullName']);
                                            if (count($names) > 1) {
                                                $initials .= strtoupper(substr($names[count($names) - 1], 0, 1));
                                            }
                                            $isFilled = in_array($tl['userId'], $filledUserIds);
                                            $notFilledClass = !$isFilled ? 'not-filled' : '';
                                            ?>
                                            <li class="tl-item <?= $notFilledClass ?>" data-id="<?= $tl['userId'] ?>"
                                                data-name="<?= htmlspecialchars($tl['fullName']) ?>" onclick="selectLead(this)">
                                                <div class="tl-avatar"><?= $initials ?></div>
                                                <div class="tl-info">
                                                    <div class="tl-name"><?= htmlspecialchars($tl['fullName']) ?></div>
                                                    <div class="tl-role"><?= htmlspecialchars($tl['designation']) ?></div>
                                                </div>

                                            </li>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        </div>

                        <!-- Right Panel: Task Diary -->
                        <div class="col-md-9">
                            <div class="task-panel-container">
                                <div class="task-panel-header">
                                    <h4 class="task-panel-title" id="selectedLeadName">
                                        <i class="bi bi-person-lines-fill" style="color:#6b7280;"></i> Select a Team
                                        Lead
                                    </h4>
                                    <div class="task-filters">
                                        <input type="date" id="filterDate" value="<?= date('Y-m-d') ?>"
                                            onchange="fetchTasks()">
                                        <select id="filterStatus" onchange="fetchTasks()">
                                            <option value="All">All Status</option>
                                            <option value="Completed">Completed</option>
                                            <option value="Postponed">Postponed</option>
                                            <option value="Pending">Pending</option>
                                        </select>
                                    </div>
                                </div>
                                <!-- Task Statistics Bar -->
                                <div class="stats-container" id="statsBar">
                                    <div class="stat-card stat-total">
                                        <div class="stat-icon"><i class="bi bi-list-task"></i></div>
                                        <div class="stat-info">
                                            <div class="stat-value" id="statTotal">0</div>
                                            <div class="stat-label">Total</div>
                                        </div>
                                    </div>
                                    <div class="stat-card stat-completed">
                                        <div class="stat-icon"><i class="bi bi-check-circle-fill"></i></div>
                                        <div class="stat-info">
                                            <div class="stat-value" id="statCompleted">0</div>
                                            <div class="stat-label">Completed</div>
                                        </div>
                                    </div>
                                    <div class="stat-card stat-pending">
                                        <div class="stat-icon"><i class="bi bi-clock-history"></i></div>
                                        <div class="stat-info">
                                            <div class="stat-value" id="statPending">0</div>
                                            <div class="stat-label">Pending</div>
                                        </div>
                                    </div>
                                    <div class="stat-card stat-postponed">
                                        <div class="stat-icon"><i class="bi bi-arrow-right-circle-fill"></i></div>
                                        <div class="stat-info">
                                            <div class="stat-value" id="statPostponed">0</div>
                                            <div class="stat-label">Postponed</div>
                                        </div>
                                    </div>
                                    <div class="stat-card stat-planned">
                                        <div class="stat-icon"><i class="bi bi-calendar-check-fill"></i></div>
                                        <div class="stat-info">
                                            <div class="stat-value" id="statPlanned">0</div>
                                            <div class="stat-label">Planned</div>
                                        </div>
                                    </div>
                                    <div class="stat-card stat-unplanned">
                                        <div class="stat-icon"><i class="bi bi-lightning-charge-fill"></i></div>
                                        <div class="stat-info">
                                            <div class="stat-value" id="statUnplanned">0</div>
                                            <div class="stat-label">Unplanned</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="task-panel-body" id="taskPanelBody">
                                    <div class="empty-state">
                                        <i class="bi bi-arrow-left-circle"></i>
                                        <h5>Please select a Team Lead from the left panel</h5>
                                        <p>To view their task diary for the selected date.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
                <!-- content-wrapper ends -->
                <?php include('../partials/_footer.php'); ?>
            </div>
            <!-- main-panel ends -->
        </div>
        <!-- page-body-wrapper ends -->
    </div>
    <!-- container-scroller -->

    <!-- plugins:js -->
    <script src="../assets/vendors/js/vendor.bundle.base.js"></script>
    <script src="../assets/js/off-canvas.js"></script>
    <script src="../assets/js/hoverable-collapse.js"></script>
    <script src="../assets/js/misc.js"></script>

    <script>
        let currentUserId = null;

        function filterLeads() {
            let input = document.getElementById('tlSearch').value.toLowerCase();
            let items = document.querySelectorAll('.tl-item');
            items.forEach(item => {
                let name = item.getAttribute('data-name').toLowerCase();
                if (name.includes(input)) {
                    item.style.display = 'flex';
                } else {
                    item.style.display = 'none';
                }
            });
        }

        function selectLead(element) {
            // Remove active from all
            document.querySelectorAll('.tl-item').forEach(el => el.classList.remove('active'));

            // Add active to selected
            element.classList.add('active');

            currentUserId = element.getAttribute('data-id');
            let name = element.getAttribute('data-name');

            document.getElementById('selectedLeadName').innerHTML = `<i class="bi bi-person-lines-fill" style="color:#1977e1;"></i> Task Diary: ${name}`;

            fetchTasks();
        }

        function updateLeadStatuses(date) {
            if (!date) date = document.getElementById('filterDate').value;
            $.ajax({
                url: '../ajax/mdTaskDiary.php',
                type: 'POST',
                data: {
                    action: 'get_leads_status',
                    date: date
                },
                success: function (res) {
                    if (res.success) {
                        let filledSet = new Set(res.filledUserIds || []);
                        document.querySelectorAll('.tl-item').forEach(item => {
                            let uid = item.getAttribute('data-id');
                            let badge = item.querySelector('.tl-status-badge');
                            if (filledSet.has(uid)) {
                                item.classList.remove('not-filled');
                                if (badge) {
                                    badge.className = 'tl-status-badge badge bg-success';
                                    badge.textContent = 'Filled';
                                }
                            } else {
                                item.classList.add('not-filled');
                                if (badge) {
                                    badge.className = 'tl-status-badge badge bg-danger';
                                    badge.textContent = 'Not Filled';
                                }
                            }
                        });
                    }
                }
            });
        }

        function fetchTasks() {
            let date = document.getElementById('filterDate').value;
            updateLeadStatuses(date);

            if (!currentUserId) return;

            let status = document.getElementById('filterStatus').value;
            let container = document.getElementById('taskPanelBody');

            container.innerHTML = `<div class="loader-spinner">
                <div class="spinner-border text-primary" role="status">
                    
                </div>
                <span class="visually-hidden">Loading...</span>
            </div>`;

            $.ajax({
                url: '../ajax/mdTaskDiary.php',
                type: 'POST',
                data: {
                    action: 'get_tasks',
                    targetUserId: currentUserId,
                    date: date,
                    status: status
                },
                success: function (res) {
                    console.log("AJAX Success:", res);
                    if (res.success) {
                        renderTasks(res.tasks, date);
                        if (res.stats) {
                            document.getElementById('statTotal').textContent = res.stats.total || 0;
                            document.getElementById('statCompleted').textContent = res.stats.completed || 0;
                            document.getElementById('statPending').textContent = res.stats.pending || 0;
                            document.getElementById('statPostponed').textContent = res.stats.postponed || 0;
                            document.getElementById('statPlanned').textContent = res.stats.planned || 0;
                            document.getElementById('statUnplanned').textContent = res.stats.unplanned || 0;
                        }
                    } else {
                        container.innerHTML = `<div class="empty-state text-danger">
                            <i class="bi bi-exclamation-triangle"></i>
                            <h5>Error Loading Tasks</h5>
                            <p>${res.msg}</p>
                        </div>`;
                    }
                },
                error: function (xhr, status, err) {
                    console.error("AJAX Error:", status, err, xhr.responseText);
                    container.innerHTML = `<div class="empty-state text-danger">
                        <i class="bi bi-exclamation-triangle"></i>
                        <h5>Connection Error</h5>
                        <p>Could not reach the server.</p>
                    </div>`;
                }
            });
        }

        function renderTasks(tasks, date) {
            let container = document.getElementById('taskPanelBody');

            if (tasks.length === 0) {
                container.innerHTML = `<div class="empty-state">
                    <i class="bi bi-journal-x"></i>
                    <h5>No Tasks Found</h5>
                    <p>No tasks recorded for the selected date and filters.</p>
                </div>`;
                return;
            }

            let html = '';
            tasks.forEach((task, index) => {
                let statusStr = task.status ? task.status : '';
                let typeStr = task.task_type ? task.task_type : '';
                let statusClass = statusStr.toLowerCase();
                let typeClass = typeStr.toLowerCase();
                let icon = 'bi-clock-history';
                if (statusStr === 'Completed') icon = 'bi-check-circle-fill';
                if (statusStr === 'Postponed') icon = 'bi-arrow-right-circle-fill';

                let tempDiv = document.createElement('div');
                tempDiv.textContent = task.task_description || '';
                let safeDesc = tempDiv.innerHTML;

                html += `
                    <div class="task-card ${statusClass}">
                        <div class="task-info">
                            <div class="task-desc">
                                ${index + 1}. ${safeDesc}
                            </div>
                            <div class="task-meta">
                                <span class="badge-type ${typeClass}">${typeStr}</span>
                                <span><i class="bi bi-clock"></i> Created: ${task.created_time || '--'}</span>
                                ${statusStr !== 'Pending' ? `<span><i class="bi bi-arrow-repeat"></i> Updated: ${task.updated_time || '--'}</span>` : ''}
                            </div>
                        </div>
                        <div class="status-badge ${statusClass}">
                            <i class="bi ${icon}"></i> ${statusStr}
                        </div>
                    </div>
                `;
            });

            container.innerHTML = html;
        }
    </script>
</body>

</html>
