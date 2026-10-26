<?php
session_start();
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}

include('../include/db_config.php');

$userId  = (int)$_SESSION['userId'];
$userRow = mysqli_fetch_assoc(mysqli_query($con, "SELECT fullName FROM signup WHERE userId='$userId'"));
$userName = $userRow ? htmlspecialchars($userRow['fullName']) : 'Unknown';

$error   = '';
$success = '';

// ── AUTO-ADD MISSING COLUMNS ─────────────────────────────────────
$colChecks = [
    'batchLocation' => "ALTER TABLE batches ADD COLUMN batchLocation VARCHAR(100) DEFAULT '' AFTER description",
    'batchMode'     => "ALTER TABLE batches ADD COLUMN batchMode ENUM('Online','Offline','Hybrid') DEFAULT 'Offline' AFTER batchLocation",
];
foreach ($colChecks as $col => $sql) {
    $chk = mysqli_query($con, "SHOW COLUMNS FROM batches LIKE '$col'");
    if ($chk && mysqli_num_rows($chk) === 0) {
        mysqli_query($con, $sql);
    }
}

// ── AJAX: Fetch all active interns from signup table for select2 dropdown ──
if (isset($_GET['ajax_all_interns'])) {
    $res = mysqli_query($con, "
        SELECT internId, fullName, email, batch_number
        FROM signup
        WHERE status = 'Active' AND internId IS NOT NULL AND internId != ''
        ORDER BY fullName ASC
    ");
    $rows = [];
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $rows[] = [
                'internId' => $r['internId'],
                'fullName' => $r['fullName'],
                'email'    => $r['email'],
                'batch'    => $r['batch_number']
            ];
        }
    }
    header('Content-Type: application/json');
    echo json_encode(['interns' => $rows]);
    exit;
}

// ── AJAX: Create a new batch ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['ajax_create_batch'])) {
    header('Content-Type: application/json');
    $batchName     = trim(mysqli_real_escape_string($con, $_POST['batchName'] ?? ''));
    $batchCode     = trim(mysqli_real_escape_string($con, $_POST['batchCode'] ?? ''));
    $startDate     = mysqli_real_escape_string($con, $_POST['startDate'] ?? '');
    $endDate       = mysqli_real_escape_string($con, $_POST['endDate'] ?? '');
    $description   = trim(mysqli_real_escape_string($con, $_POST['description'] ?? ''));
    $batchLocation = trim(mysqli_real_escape_string($con, $_POST['batchLocation'] ?? ''));
    $batchMode     = mysqli_real_escape_string($con, $_POST['batchMode'] ?? 'Offline');
    $userId        = (int)$_SESSION['userId'];

    if (empty($batchName) || empty($batchCode)) {
        echo json_encode(['error' => 'Batch Name and Batch Code are required.']);
        exit;
    } elseif (empty($batchLocation)) {
        echo json_encode(['error' => 'Batch Location is required.']);
        exit;
    } elseif (empty($startDate)) {
        echo json_encode(['error' => 'Start Date is required.']);
        exit;
    } elseif ($startDate < date('Y-m-d')) {
        echo json_encode(['error' => 'Start Date cannot be in the past.']);
        exit;
    } elseif (empty($endDate)) {
        echo json_encode(['error' => 'End Date is required.']);
        exit;
    } elseif ($endDate <= $startDate) {
        echo json_encode(['error' => 'End Date must be after Start Date.']);
        exit;
    } else {
        $check = mysqli_fetch_assoc(mysqli_query($con, "SELECT id FROM batches WHERE batchCode='$batchCode'"));
        if ($check) {
            echo json_encode(['error' => "Batch code '$batchCode' already exists."]);
            exit;
        } else {
            $insert = "INSERT INTO batches (batchName, batchCode, startDate, endDate, description, batchLocation, batchMode, status, createdBy)
                       VALUES ('$batchName','$batchCode','$startDate','$endDate','$description','$batchLocation','$batchMode','Active','$userId')";
            if (mysqli_query($con, $insert)) {
                echo json_encode(['success' => true, 'batchName' => $batchName, 'batchCode' => $batchCode]);
                exit;
            } else {
                echo json_encode(['error' => 'Error: ' . mysqli_error($con)]);
                exit;
            }
        }
    }
}

// ── AJAX: Assign interns to a batch ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['ajax_assign_interns'])) {
    header('Content-Type: application/json');
    $batchId   = isset($_POST['batchId']) ? (int)$_POST['batchId'] : 0;
    $internIds = isset($_POST['internIds']) ? $_POST['internIds'] : [];

    if ($batchId <= 0) { echo json_encode(['error' => 'Invalid batch ID.']); exit; }
    $bRow = mysqli_fetch_assoc(mysqli_query($con, "SELECT batchName FROM batches WHERE id='$batchId'"));
    if (!$bRow)         { echo json_encode(['error' => 'Batch not found.']); exit; }

    $batchName     = $bRow['batchName'];
    $batchNameSafe = mysqli_real_escape_string($con, $batchName);

    if (!empty($internIds) && is_array($internIds)) {
        foreach ($internIds as $id) {
            $idSafe  = mysqli_real_escape_string($con, $id);
            $checkQ  = mysqli_query($con, "SELECT COUNT(*) as c FROM interns WHERE internId = '$idSafe'");
            $check   = mysqli_fetch_assoc($checkQ);
            if ($check && $check['c'] == 0) {
                $signupQ   = mysqli_query($con, "SELECT * FROM signup WHERE internId = '$idSafe' LIMIT 1");
                $signupRow = mysqli_fetch_assoc($signupQ);
                if ($signupRow) {
                    $fullname  = mysqli_real_escape_string($con, $signupRow['fullName']);
                    $email     = mysqli_real_escape_string($con, $signupRow['email']);
                    $mode      = mysqli_real_escape_string($con, $signupRow['mode'] ?: 'Offline');
                    $doj       = mysqli_real_escape_string($con, $signupRow['start_date'] ?: date('Y-m-d'));
                    $end_date  = mysqli_real_escape_string($con, $signupRow['end_date'] ?: date('Y-m-d', strtotime('+3 months')));
                    $role      = mysqli_real_escape_string($con, $signupRow['designation'] ?: 'juniorIntern');
                    $password  = mysqli_real_escape_string($con, $signupRow['password'] ?: '');
                    mysqli_query($con, "
                        INSERT INTO interns (internId, fullName, email, batch, mode, doj, expectedEndDate, approvalStatus, phase, role, password)
                        VALUES ('$idSafe','$fullname','$email','$batchNameSafe','$mode','$doj','$end_date','approved','Training','$role','$password')
                    ");
                }
            } else {
                mysqli_query($con, "UPDATE interns SET batch = '$batchNameSafe' WHERE internId = '$idSafe'");
            }
            mysqli_query($con, "UPDATE signup SET batch_number = '$batchNameSafe' WHERE internId = '$idSafe'");
        }
        echo json_encode(['success' => true]);
        exit;
    }
    echo json_encode(['error' => 'No interns selected.']);
    exit;
}

// ── AJAX: Change an intern's batch ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['ajax_change_intern_batch'])) {
    header('Content-Type: application/json');
    $internId     = isset($_POST['internId']) ? trim($_POST['internId']) : '';
    $newBatchName = isset($_POST['newBatchName']) ? trim(mysqli_real_escape_string($con, $_POST['newBatchName'])) : '';

    if (empty($internId)) {
        echo json_encode(['error' => 'Intern ID is required.']);
        exit;
    }

    $cleanId = mysqli_real_escape_string($con, preg_replace('/\s+/', '', $internId));

    // Update interns table
    mysqli_query($con, "
        UPDATE interns 
        SET batch = '$newBatchName' 
        WHERE REPLACE(REPLACE(internId, '\t', ''), ' ', '') = '$cleanId' 
           OR LOWER(TRIM(email)) = LOWER('$cleanId')
    ");

    // Update signup table
    mysqli_query($con, "
        UPDATE signup 
        SET batch_number = '$newBatchName' 
        WHERE REPLACE(REPLACE(internId, '\t', ''), ' ', '') = '$cleanId' 
           OR LOWER(TRIM(email)) = LOWER('$cleanId')
    ");

    echo json_encode(['success' => true, 'msg' => 'Intern batch updated successfully.']);
    exit;
}

// ── AJAX: Export interns of a batch to Excel (JSON data response) ─
if (isset($_GET['ajax_interns']) && is_numeric($_GET['ajax_interns'])) {
    $batchId = (int)$_GET['ajax_interns'];
    $bRow    = mysqli_fetch_assoc(mysqli_query($con, "SELECT batchName FROM batches WHERE id='$batchId'"));
    if ($bRow) {
        $batchNameSafe = mysqli_real_escape_string($con, $bRow['batchName']);
        $res = mysqli_query($con, "
            SELECT DISTINCT i.internId, i.fullName, i.email, i.role, i.techStack,
                   i.doj, i.expectedEndDate, i.workingSlots, i.phase, i.mode,
                   i.stipend, i.stipendAmount, i.approvalStatus, i.branch,
                   i.intern_location,
                   COALESCE(s.fullName,'—') as mentorName
            FROM interns i
            INNER JOIN signup su ON (TRIM(su.internId) = TRIM(i.internId) OR (su.email IS NOT NULL AND su.email != '' AND LOWER(TRIM(su.email)) = LOWER(TRIM(i.email))))
            LEFT JOIN signup s ON s.userId = i.approvedBy
            WHERE i.batch = '$batchNameSafe'
              AND (TRIM(LOWER(su.status)) = 'active' OR su.status = '1' OR su.status = 1)
            ORDER BY i.doj ASC, i.fullName ASC
        ");
        $rows = [];
        if ($res) while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
        header('Content-Type: application/json');
        echo json_encode(['batchName' => $bRow['batchName'], 'interns' => $rows]);
        exit;
    }
    echo json_encode(['error' => 'Batch not found']);
    exit;
}

// ── Handle CREATE ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_batch'])) {
    $batchName     = trim(mysqli_real_escape_string($con, $_POST['batchName']));
    $batchCode     = trim(mysqli_real_escape_string($con, $_POST['batchCode']));
    $startDate     = mysqli_real_escape_string($con, $_POST['startDate']);
    $endDate       = mysqli_real_escape_string($con, $_POST['endDate']);
    $description   = trim(mysqli_real_escape_string($con, $_POST['description']));
    $batchLocation = trim(mysqli_real_escape_string($con, $_POST['batchLocation'] ?? ''));
    $batchMode     = mysqli_real_escape_string($con, $_POST['batchMode'] ?? 'Offline');

    if (empty($batchName) || empty($batchCode)) {
        $error = "Batch Name and Batch Code are required.";
    } elseif (empty($batchLocation)) {
        $error = "Batch Location is required.";
    } elseif (empty($startDate)) {
        $error = "Start Date is required.";
    } elseif (empty($endDate)) {
        $error = "End Date is required.";
    } elseif ($endDate <= $startDate) {
        $error = "End Date must be after Start Date.";
    } else {
        $check = mysqli_fetch_assoc(mysqli_query($con, "SELECT id FROM batches WHERE batchCode='$batchCode'"));
        if ($check) {
            $error = "Batch code '$batchCode' already exists.";
        } else {
            $insert = "INSERT INTO batches (batchName, batchCode, startDate, endDate, description, batchLocation, batchMode, status, createdBy)
                       VALUES ('$batchName','$batchCode','$startDate','$endDate','$description','$batchLocation','$batchMode','Active','$userId')";
            if (mysqli_query($con, $insert)) {
                $success = "Batch '<strong>$batchName</strong>' created successfully!";
            } else {
                $error = "Error: " . mysqli_error($con);
            }
        }
    }
}

// ── Handle EDIT ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_batch'])) {
    $id            = (int)$_POST['editId'];
    $batchName     = trim(mysqli_real_escape_string($con, $_POST['editBatchName']));
    $batchCode     = trim(mysqli_real_escape_string($con, $_POST['editBatchCode']));
    $startDate     = mysqli_real_escape_string($con, $_POST['editStartDate']);
    $endDate       = mysqli_real_escape_string($con, $_POST['editEndDate']);
    $description   = trim(mysqli_real_escape_string($con, $_POST['editDescription']));
    $status        = mysqli_real_escape_string($con, $_POST['editStatus']);
    $batchLocation = trim(mysqli_real_escape_string($con, $_POST['editBatchLocation'] ?? ''));
    $batchMode     = mysqli_real_escape_string($con, $_POST['editBatchMode'] ?? 'Offline');

    if (empty($batchName) || empty($batchCode)) {
        $error = "Batch Name and Batch Code are required.";
    } elseif (empty($batchLocation)) {
        $error = "Batch Location is required.";
    } elseif (empty($startDate)) {
        $error = "Start Date is required.";
    } elseif (empty($endDate)) {
        $error = "End Date is required.";
    } elseif ($endDate <= $startDate) {
        $error = "End Date must be after Start Date.";
    } else {
        $update = "UPDATE batches SET
            batchName='$batchName', batchCode='$batchCode',
            startDate='$startDate', endDate='$endDate',
            description='$description', status='$status',
            batchLocation='$batchLocation', batchMode='$batchMode'
            WHERE id='$id'";
        if (mysqli_query($con, $update)) {
            $success = "Batch updated successfully!";
        } else {
            $error = "Error: " . mysqli_error($con);
        }
    }
}

// ── Toggle Status ────────────────────────────────────────────────
if (isset($_GET['toggleStatus']) && is_numeric($_GET['toggleStatus'])) {
    $id        = (int)$_GET['toggleStatus'];
    $currRow   = mysqli_fetch_assoc(mysqli_query($con, "SELECT status FROM batches WHERE id='$id'"));
    $newStatus = ($currRow['status'] === 'Active') ? 'Inactive' : 'Active';
    mysqli_query($con, "UPDATE batches SET status='$newStatus' WHERE id='$id'");
    header("Location: batch_master.php");
    exit;
}

// ── Fetch all batches ────────────────────────────────────────────
$search       = isset($_GET['search']) ? mysqli_real_escape_string($con, trim($_GET['search'])) : '';
$filterStatus = isset($_GET['status']) ? mysqli_real_escape_string($con, $_GET['status'])       : '';

$where = "WHERE 1=1";
if ($search)       $where .= " AND (batchName LIKE '%$search%' OR batchCode LIKE '%$search%')";
if ($filterStatus) $where .= " AND status = '$filterStatus'";

$batches = mysqli_query($con, "
    SELECT b.*,
        (SELECT COUNT(DISTINCT i.internId) 
         FROM interns i 
         INNER JOIN signup su ON (TRIM(su.internId) = TRIM(i.internId) OR (su.email IS NOT NULL AND su.email != '' AND LOWER(TRIM(su.email)) = LOWER(TRIM(i.email))))
         WHERE i.batch = b.batchName 
           AND (TRIM(LOWER(su.status)) = 'active' OR su.status = '1' OR su.status = 1)
        ) as internCount
    FROM batches b $where ORDER BY b.createdAt DESC
");

$totalBatches  = (int)mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as c FROM batches"))['c'];
$activeBatches = (int)mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as c FROM batches WHERE status='Active'"))['c'];
$totalInterns  = (int)mysqli_fetch_assoc(mysqli_query($con, "
    SELECT COUNT(DISTINCT i.internId) as c 
    FROM interns i 
    INNER JOIN signup su ON (TRIM(su.internId) = TRIM(i.internId) OR (su.email IS NOT NULL AND su.email != '' AND LOWER(TRIM(su.email)) = LOWER(TRIM(i.email))))
    WHERE (TRIM(LOWER(su.status)) = 'active' OR su.status = '1' OR su.status = 1)
"))['c'];

$allBatchesForPdf = [];
$pdfBatchQ = mysqli_query($con, "
    SELECT b.*, 
        (SELECT COUNT(DISTINCT i.internId) 
         FROM interns i 
         INNER JOIN signup su ON (TRIM(su.internId) = TRIM(i.internId) OR (su.email IS NOT NULL AND su.email != '' AND LOWER(TRIM(su.email)) = LOWER(TRIM(i.email))))
         WHERE i.batch = b.batchName 
           AND (TRIM(LOWER(su.status)) = 'active' OR su.status = '1' OR su.status = 1)
        ) as internCount
    FROM batches b ORDER BY b.createdAt DESC
");
while ($pb = mysqli_fetch_assoc($pdfBatchQ)) $allBatchesForPdf[] = $pb;

// ── Predefined batch locations ───────────────────────────────────
$batchLocations = ['Pune', 'Kinwat', 'Remote' ];
$todayDate = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Batch Master</title>
    <?php include('../partials/_links.php'); ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap4.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/exceljs/dist/exceljs.min.js"></script>
    <style>
        :root {
            --primary:       #2f55ff;
            --primary-light: #eef1ff;
            --success:       #28a745;
            --warning:       #f59e0b;
            --danger:        #e53935;
            --border:        #e0e4ef;
            --radius:        8px;
            --shadow:        0 2px 12px rgba(47,85,255,0.07);
        }
        .stat-card {
            background: #fff; border-radius: 12px; padding: 18px 22px;
            box-shadow: var(--shadow); display: flex; align-items: center;
            gap: 16px; border-left: 4px solid var(--primary);
        }
        .stat-card.active  { border-color: var(--success); }
        .stat-card.interns { border-color: var(--warning); }
        .stat-icon {
            width: 44px; height: 44px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.1rem; flex-shrink: 0;
        }
        .stat-icon.total   { background: var(--primary-light); color: var(--primary); }
        .stat-icon.active  { background: #f0fff4; color: var(--success); }
        .stat-icon.interns { background: #fff8e1; color: var(--warning); }
        .stat-num   { font-size: 1.6rem; font-weight: 800; color: #222; line-height: 1; }
        .stat-label { font-size: 0.8rem; color: #888; margin-top: 2px; }
        .filter-bar {
            background: #fff; border-radius: var(--radius); padding: 14px 18px;
            box-shadow: var(--shadow); margin-bottom: 20px;
            display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end;
        }
        .filter-bar .form-group { margin: 0; flex: 1; min-width: 160px; }
        .filter-bar label { font-size: 0.78rem; font-weight: 600; color: #888; margin-bottom: 4px; display: block; }
        .filter-bar .form-control, .filter-bar select {
            border-radius: 6px; font-size: 0.87rem;
            border: 1.5px solid var(--border); padding: 7px 11px;
        }
        .btn-filter { background: var(--primary); color: #fff; border: none; border-radius: 6px; padding: 9px 18px; font-weight: 600; font-size: 0.87rem; cursor: pointer; }
        .btn-reset  { background: #f1f3f9; color: #555; border: none; border-radius: 6px; padding: 9px 14px; font-weight: 600; font-size: 0.87rem; cursor: pointer; text-decoration: none; display: inline-block; }
        .table-wrap { background: #fff; border-radius: 12px; box-shadow: var(--shadow); overflow: hidden; }
        .table-header {
            padding: 16px 22px; display: flex; align-items: center;
            justify-content: space-between; border-bottom: 1px solid var(--border);
        }
        .table-header h5 { margin: 0; font-weight: 700; font-size: 1rem; }
        table.batch-table { width: 100%; border-collapse: collapse; font-size: 0.87rem; }
        .batch-table th {
            background: #f8f9fd; color: #666; font-weight: 700; font-size: 0.75rem;
            text-transform: uppercase; letter-spacing: 0.05em;
            padding: 11px 16px; border-bottom: 1px solid var(--border); white-space: nowrap;
        }
        .batch-table td { padding: 12px 16px; border-bottom: 1px solid #f2f4fb; vertical-align: middle; color: #333; }
        .batch-table tr:last-child td { border-bottom: none; }
        .batch-table tr.batch-row:hover td { background: #f8f9ff; }
        .batch-table tr.batch-row { cursor: pointer; }
        .intern-history-row { display: none; }
        .intern-history-row.open { display: table-row; }
        .intern-history-cell {
            padding: 0 !important; background: #f8f9ff;
            border-bottom: 2px solid var(--primary) !important;
        }
        .intern-history-inner { padding: 18px 22px; animation: slideDown 0.25s ease; }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-8px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .history-table-title {
            font-size: 0.82rem; font-weight: 800; text-transform: uppercase;
            letter-spacing: 0.07em; color: var(--primary); margin-bottom: 12px;
            display: flex; align-items: center; gap: 7px;
        }
        .intern-dt-wrapper .dataTables_filter { display: none; }
        .intern-dt-search-row {
            display: flex; align-items: center; justify-content: space-between;
            flex-wrap: wrap; gap: 10px; margin-bottom: 12px;
        }
        .intern-dt-search-box {
            display: flex; align-items: center;
            background: #fff; border: 1.5px solid var(--border);
            border-radius: 7px; overflow: hidden; height: 34px;
        }
        .intern-dt-search-box i { padding: 0 10px; color: #9090a8; font-size: 0.85rem; flex-shrink: 0; }
        .intern-dt-search-box input { border: none; outline: none; font-size: 0.82rem; color: #495057; background: transparent; width: 200px; padding-right: 10px; }
        .intern-dt-wrapper .dataTables_length label { font-size: 0.8rem; color: #495057; }
        .intern-dt-wrapper .dataTables_length select { border: 1px solid var(--border); border-radius: 5px; padding: 3px 8px; font-size: 0.8rem; outline: none; }
        .intern-dt-wrapper .dataTables_info { font-size: 0.78rem; color: #9090a8; padding-top: 8px; }
        .intern-dt-wrapper .dataTables_paginate { padding-top: 6px; }
        .intern-dt-wrapper .dataTables_paginate .paginate_button { border-radius: 5px !important; font-size: 0.78rem !important; padding: 4px 9px !important; }
        .intern-dt-wrapper .dataTables_paginate .paginate_button.current,
        .intern-dt-wrapper .dataTables_paginate .paginate_button.current:hover { background: var(--primary) !important; border-color: var(--primary) !important; color: #fff !important; }
        .intern-dt-wrapper .dataTables_paginate .paginate_button:hover { background: #f0f0fb !important; border-color: var(--border) !important; color: var(--primary) !important; }
        .btn-excel-export {
            background: #217346; color: #fff; border: none;
            border-radius: 6px; padding: 6px 14px; font-size: 0.8rem;
            font-weight: 600; cursor: pointer; display: inline-flex;
            align-items: center; gap: 6px; transition: background 0.2s;
        }
        .btn-excel-export:hover { background: #185c37; }
        table.intern-table { width: 100%; border-collapse: collapse; font-size: 0.83rem; background: #fff; border-radius: 8px; overflow: hidden; }
        .intern-table th {
            background: var(--primary-light); color: var(--primary);
            font-weight: 700; font-size: 0.73rem; text-transform: uppercase;
            letter-spacing: 0.05em; padding: 9px 14px; white-space: nowrap;
        }
        .intern-table td { padding: 9px 14px; border-bottom: 1px solid #f0f2fb; color: #333; vertical-align: middle; }
        .intern-table tr:last-child td { border-bottom: none; }
        .intern-table tr:hover td { background: #f8f9ff; }
        .empty-interns { text-align: center; padding: 24px; color: #bbb; font-size: 0.85rem; }
        .empty-interns i { display: block; font-size: 1.6rem; margin-bottom: 6px; color: #dde0f5; }
        .badge-status { display: inline-block; padding: 3px 12px; border-radius: 20px; font-size: 0.73rem; font-weight: 700; letter-spacing: 0.03em; }
        .badge-active   { background: #f0fff4; color: #15803d; }
        .badge-inactive { background: #fff1f1; color: var(--danger); }
        .badge-mode { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 0.73rem; font-weight: 700; }
        .badge-mode-online  { background: #e8f5e9; color: #1b5e20; }
        .badge-mode-offline { background: #e3f2fd; color: #0d47a1; }
        .badge-mode-hybrid  { background: #fff3e0; color: #bf360c; }
        .batch-code-badge { background: var(--primary-light); color: var(--primary); padding: 3px 10px; border-radius: 5px; font-size: 0.78rem; font-weight: 700; font-family: 'Courier New', monospace; }
        .intern-count-badge {
            display: inline-flex; align-items: center; gap: 5px;
            background: #fff8e1; color: #b45309; padding: 3px 10px; border-radius: 20px;
            font-size: 0.78rem; font-weight: 700; cursor: pointer; transition: background 0.15s;
        }
        .intern-count-badge:hover { background: #fde68a; }
        .location-text { font-size: 0.82rem; color: #555; display: flex; align-items: center; gap: 4px; }
        .location-text i { color: #aaa; font-size: 0.75rem; }
        .btn-edit {
            background: var(--primary-light); color: var(--primary); border: none;
            border-radius: 6px; padding: 5px 12px; font-size: 0.8rem; font-weight: 600; cursor: pointer; transition: all 0.15s;
        }
        .btn-edit:hover { background: var(--primary); color: #fff; }
        .btn-toggle-active   { background: #fff1f1; color: var(--danger); border: none; border-radius: 6px; padding: 5px 12px; font-size: 0.8rem; font-weight: 600; cursor: pointer; transition: all 0.15s; }
        .btn-toggle-active:hover   { background: var(--danger); color: #fff; }
        .btn-toggle-inactive { background: #f0fff4; color: var(--success); border: none; border-radius: 6px; padding: 5px 12px; font-size: 0.8rem; font-weight: 600; cursor: pointer; transition: all 0.15s; }
        .btn-toggle-inactive:hover { background: var(--success); color: #fff; }
        .btn-create {
            background: var(--primary); color: #fff; border: none; border-radius: var(--radius);
            padding: 9px 20px; font-weight: 600; font-size: 0.9rem; cursor: pointer;
            display: inline-flex; align-items: center; gap: 7px; text-decoration: none; transition: background 0.2s;
        }
        .btn-create:hover { background: #1a3de0; color: #fff; text-decoration: none; }
        .expand-icon {
            display: inline-flex; align-items: center; justify-content: center;
            width: 22px; height: 22px; border-radius: 50%;
            background: var(--primary-light); color: var(--primary);
            font-size: 0.7rem; transition: transform 0.2s, background 0.2s; flex-shrink: 0;
        }
        tr.expanded .expand-icon { transform: rotate(180deg); background: var(--primary); color: #fff; }
        .modal-header { background: var(--primary-light); border-bottom: 1px solid var(--border); }
        .modal-title  { color: var(--primary); font-weight: 700; }
        .modal .form-control, .modal select.form-control { border-radius: 6px; border: 1.5px solid var(--border); font-size: 0.9rem; }
        .modal .form-control:focus, .modal select.form-control:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(47,85,255,0.1); }
        .modal label { font-weight: 600; font-size: 0.85rem; color: #555; }
        .modal-section { font-size: 0.75rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.08em; color: var(--primary); border-bottom: 1px solid var(--border); padding-bottom: 4px; margin: 14px 0 10px; }
        .mode-toggle-modal { display: flex; gap: 8px; }
        .mode-btn-modal { flex: 1; padding: 8px 6px; border: 2px solid var(--border); border-radius: 6px; background: #fff; color: #555; font-weight: 600; font-size: 0.83rem; cursor: pointer; text-align: center; transition: all 0.2s; }
        .mode-btn-modal.sel { border-color: var(--primary); background: var(--primary); color: #fff; }
        .phase-badge { display: inline-block; padding: 2px 9px; border-radius: 20px; font-size: 0.72rem; font-weight: 700; }
        .phase-training  { background: #e8f5e9; color: #2e7d32; }
        .phase-project   { background: #e3f2fd; color: #1565c0; }
        .phase-completed { background: #f3e5f5; color: #6a1b9a; }
        .phase-other     { background: #fafafa; color: #555; }
        .empty-state { text-align: center; padding: 48px 20px; color: #aaa; }
        .empty-state i { font-size: 2.5rem; margin-bottom: 12px; color: #d0d5f0; display: block; }
        .alert { border-radius: var(--radius); }
        .export-spinner { display: none; width: 14px; height: 14px; border: 2px solid rgba(255,255,255,0.4); border-top-color: #fff; border-radius: 50%; animation: spin 0.6s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .required { color: #e53935; }

        /* ── Field error highlight ────────────────────────────── */
        .field-error { border-color: var(--danger) !important; box-shadow: 0 0 0 2px rgba(229,57,53,0.15) !important; }
        .field-error-msg {
            color: var(--danger); font-size: 0.78rem; font-weight: 600;
            margin-top: 4px; display: flex; align-items: center; gap: 4px;
        }
        .field-error-msg i { font-size: 0.72rem; }

        /* ── Dropdown selects ─────────────────────────────────── */
        select.form-control {
            appearance: none; -webkit-appearance: none;
            background: #fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%232f55ff' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E") no-repeat right 13px center;
            padding-right: 36px; cursor: pointer; color: #222 !important;
        }
        select.form-control option { color: #222; }

        /* ── Date input: hide browser min/max arrow clutter ───── */
        input[type="date"].form-control { color: #222; }

        /* ── Select2 overrides ────────────────────────────────── */
        .select2-container--default .select2-selection--multiple {
            border: 1.5px solid var(--border); border-radius: 6px; padding: 2px 6px; min-height: 40px;
        }
        .select2-container--default.select2-container--focus .select2-selection--multiple { border-color: var(--primary); }
        .select2-container--default .select2-selection--multiple .select2-selection__choice {
            background-color: var(--primary-light); border: 1px solid rgba(47,85,255,0.2);
            color: var(--primary); font-size: 0.82rem; font-weight: 600;
            border-radius: 4px; padding: 2px 8px; margin-top: 4px;
        }
        .select2-container--default .select2-selection--multiple .select2-selection__choice__remove { color: var(--primary); margin-right: 5px; }
        .select2-container--default .select2-selection--multiple .select2-selection__choice__remove:hover { color: var(--danger); }
        .select2-container--default .select2-results__option--highlighted[aria-selected] { background-color: var(--primary) !important; color: white !important; }
    </style>
</head>
<body>
<div class="container-scroller">
    <?php include('../partials/_navbar.php'); ?>
    <div class="container-fluid page-body-wrapper">
        <?php include('../partials/_sidebar.php'); include('../partials/_settings-panel.php'); ?>
        <div class="main-panel">
            <div class="content-wrapper">

                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="fa-solid fa-circle-exclamation me-2"></i><?php echo $error; ?>
                        <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
                    </div>
                <?php endif; ?>
                <?php if (!empty($success)): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="fa-solid fa-circle-check me-2"></i><?php echo $success; ?>
                        <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
                    </div>
                <?php endif; ?>

                <!-- Page Header -->
                <div class="d-flex align-items-center justify-content-between mb-4">
                    <div>
                        <h4 class="mb-1" style="font-weight:800;color:#222;">
                            <i class="fa-solid fa-layer-group me-2" style="color:var(--primary)"></i>
                            Batch Master
                        </h4>
                        <p class="mb-0" style="color:#888;font-size:0.88rem;">
                            Manage batches — click a row to view interns &amp; export to Excel
                        </p>
                    </div>
                    <div style="display:flex;gap:10px;align-items:center;">
                        <button class="btn-excel-export" onclick="exportBatchesExcel()" style="padding:9px 16px;font-size:0.87rem;">
                            <i class="fa-solid fa-file-excel"></i> Export Batches Excel
                        </button>
                        <button class="btn-create" onclick="openCreateModal()">
                            <i class="fa-solid fa-plus"></i> Create New Batch
                        </button>
                    </div>
                </div>

                <!-- Stats -->
                <div class="row mb-4">
                    <div class="col-md-4 mb-3">
                        <div class="stat-card">
                            <div class="stat-icon total"><i class="fa-solid fa-layer-group"></i></div>
                            <div><div class="stat-num"><?php echo $totalBatches; ?></div><div class="stat-label">Total Batches</div></div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="stat-card active">
                            <div class="stat-icon active"><i class="fa-solid fa-circle-check"></i></div>
                            <div><div class="stat-num"><?php echo $activeBatches; ?></div><div class="stat-label">Active Batches</div></div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="stat-card interns">
                            <div class="stat-icon interns"><i class="fa-solid fa-users"></i></div>
                            <div><div class="stat-num"><?php echo $totalInterns; ?></div><div class="stat-label">Total Interns Assigned</div></div>
                        </div>
                    </div>
                </div>

                <!-- Filter Bar -->
                <form method="GET" class="filter-bar">
                    <div class="form-group">
                        <label>Search</label>
                        <input type="text" name="search" class="form-control" placeholder="Batch name or code…" value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" class="form-control">
                            <option value="">All</option>
                            <option value="Active"   <?php echo ($filterStatus === 'Active')   ? 'selected' : ''; ?>>Active</option>
                            <option value="Inactive" <?php echo ($filterStatus === 'Inactive') ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                    <button type="submit" class="btn-filter"><i class="fa-solid fa-magnifying-glass me-1"></i> Filter</button>
                    <a href="batch_master.php" class="btn-reset"><i class="fa-solid fa-rotate me-1"></i> Reset</a>
                </form>

                <!-- Table -->
                <div class="table-wrap">
                    <div class="table-header">
                        <h5><i class="fa-solid fa-list me-2" style="color:var(--primary)"></i> All Batches</h5>
                        <span style="color:#888;font-size:0.83rem;"><?php echo mysqli_num_rows($batches); ?> batches found &nbsp;·&nbsp; click a row to expand interns &amp; export Excel</span>
                    </div>
                    <div style="overflow-x:auto;">
                        <table class="batch-table">
                            <thead>
                                <tr>
                                    <th style="width:32px;"></th>
                                    <th>#</th>
                                    <th>Batch Name</th>
                                    <th>Batch Code</th>
                                    <th>Location</th>
                                    <th>Mode</th>
                                    <th>Start Date</th>
                                    <th>End Date</th>
                                    <th>Interns</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $sr = 1;
                                if ($batches && mysqli_num_rows($batches) > 0):
                                    while ($batch = mysqli_fetch_assoc($batches)):
                                        $rowId  = 'history_' . $batch['id'];
                                        $modeLC = strtolower($batch['batchMode'] ?? 'offline');
                                ?>
                                <tr class="batch-row" onclick="toggleHistory('<?php echo $rowId; ?>', this, <?php echo $batch['id']; ?>)" id="row_<?php echo $batch['id']; ?>">
                                    <td style="text-align:center;">
                                        <span class="expand-icon"><i class="fa-solid fa-chevron-down"></i></span>
                                    </td>
                                    <td><?php echo $sr++; ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($batch['batchName']); ?></strong>
                                        <?php if (!empty($batch['description'])): ?>
                                            <div style="font-size:0.75rem;color:#aaa;margin-top:2px;">
                                                <?php echo htmlspecialchars(substr($batch['description'], 0, 50)) . (strlen($batch['description']) > 50 ? '…' : ''); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="batch-code-badge"><?php echo htmlspecialchars($batch['batchCode']); ?></span></td>
                                    <td>
                                        <?php if (!empty($batch['batchLocation'])): ?>
                                            <span class="location-text"><i class="fa-solid fa-location-dot"></i><?php echo htmlspecialchars($batch['batchLocation']); ?></span>
                                        <?php else: ?><span style="color:#ccc;font-size:0.8rem;">—</span><?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $modeLabel = htmlspecialchars($batch['batchMode'] ?? 'Offline');
                                        $modeClass = 'badge-mode-' . $modeLC;
                                        $modeIcon  = $modeLC === 'online' ? 'wifi' : ($modeLC === 'hybrid' ? 'shuffle' : 'building');
                                        ?>
                                        <span class="badge-mode <?php echo $modeClass; ?>">
                                            <i class="fa-solid fa-<?php echo $modeIcon; ?> me-1" style="font-size:0.7rem;"></i><?php echo $modeLabel; ?>
                                        </span>
                                    </td>
                                    <td><?php echo $batch['startDate'] ? date('d M Y', strtotime($batch['startDate'])) : '—'; ?></td>
                                    <td><?php echo $batch['endDate']   ? date('d M Y', strtotime($batch['endDate']))   : '—'; ?></td>
                                    <td>
                                        <span class="intern-count-badge" id="mainBadge_<?php echo $batch['id']; ?>" title="Click row to see interns">
                                            <i class="fa-solid fa-user-graduate"></i>
                                            <span class="count-val"><?php echo $batch['internCount']; ?></span> intern<?php echo $batch['internCount'] != 1 ? 's' : ''; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge-status <?php echo $batch['status'] === 'Active' ? 'badge-active' : 'badge-inactive'; ?>">
                                            <?php echo $batch['status']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div style="display:flex;gap:6px;" onclick="event.stopPropagation()">
                                            <button class="btn-edit"
                                                    data-batch="<?php echo htmlspecialchars(json_encode($batch), ENT_QUOTES); ?>"
                                                    onclick="openEditModal(JSON.parse(this.dataset.batch))">
                                                <i class="fa-solid fa-pen"></i> Edit
                                            </button>
                                            <a href="?toggleStatus=<?php echo $batch['id']; ?>"
                                               class="<?php echo $batch['status'] === 'Active' ? 'btn-toggle-active' : 'btn-toggle-inactive'; ?>"
                                               onclick="return confirm('<?php echo $batch['status'] === 'Active' ? 'Deactivate' : 'Activate'; ?> this batch?')">
                                                <i class="fa-solid fa-<?php echo $batch['status'] === 'Active' ? 'ban' : 'circle-check'; ?>"></i>
                                                <?php echo $batch['status'] === 'Active' ? 'Deactivate' : 'Activate'; ?>
                                            </a>
                                        </div>
                                    </td>
                                </tr>

                                <!-- Intern History Expand Row -->
                                <tr class="intern-history-row" id="<?php echo $rowId; ?>">
                                    <td class="intern-history-cell" colspan="11">
                                        <div class="intern-history-inner" id="inner_<?php echo $batch['id']; ?>">
                                            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:10px;">
                                                <div class="history-table-title" style="margin-bottom:0;">
                                                    <i class="fa-solid fa-users"></i>
                                                    Interns in <em><?php echo htmlspecialchars($batch['batchName']); ?></em>
                                                    <span id="badge_<?php echo $batch['id']; ?>" style="background:var(--primary);color:#fff;padding:1px 9px;border-radius:20px;font-size:0.7rem;margin-left:4px;">…</span>
                                                </div>
                                                <div style="display:flex;gap:10px;">
                                                    <button class="btn-excel-export" style="background:var(--primary);" onclick="openAddInternsModal(<?php echo $batch['id']; ?>, '<?php echo addslashes($batch['batchName']); ?>')">
                                                        <i class="fa-solid fa-user-plus"></i> Add Interns
                                                    </button>
                                                    <button class="btn-excel-export" onclick="exportBatchExcel(<?php echo $batch['id']; ?>, '<?php echo addslashes($batch['batchName']); ?>')" id="excelBtn_<?php echo $batch['id']; ?>">
                                                        <i class="fa-solid fa-file-excel"></i>
                                                        <span>Export to Excel</span>
                                                        <span class="export-spinner" id="spinner_<?php echo $batch['id']; ?>"></span>
                                                    </button>
                                                </div>
                                            </div>
                                            <div class="intern-dt-search-row">
                                                <div class="intern-dt-search-box">
                                                    <i class="fa fa-search"></i>
                                                    <input type="text"
                                                           id="internSearch_<?php echo $batch['id']; ?>"
                                                           placeholder="Search interns…"
                                                           oninput="filterInternDT(<?php echo $batch['id']; ?>, this.value)">
                                                </div>
                                                <div style="font-size:0.78rem;color:#9090a8;" id="internDtInfo_<?php echo $batch['id']; ?>"></div>
                                            </div>
                                            <div id="internTableContainer_<?php echo $batch['id']; ?>" class="intern-dt-wrapper">
                                                <div style="text-align:center;padding:20px;color:#aaa;">
                                                    <i class="fa-solid fa-circle-notch fa-spin me-2"></i> Loading interns…
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>

                                <?php endwhile; else: ?>
                                <tr><td colspan="11"><div class="empty-state"><i class="fa-solid fa-layer-group"></i>No batches found.</div></td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     CREATE BATCH MODAL
     ══════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="createBatchModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-md" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fa-solid fa-plus me-2"></i> Create New Batch</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <form method="POST" id="createBatchForm" novalidate>
                <input type="hidden" name="create_batch" value="1">
                <div class="modal-body">
                    <div class="modal-section">Batch Details</div>
                    <div class="row">
                        <div class="col-md-7">
                            <div class="form-group mb-3">
                                <label>Batch Name <span class="required">*</span></label>
                                <input type="text" class="form-control" name="batchName" id="createBatchName"
                                       placeholder="e.g. Batch A, Summer 2025"
                                       autocomplete="off">
                                <div class="field-error-msg" id="err_createBatchName" style="display:none;">
                                    <i class="fa-solid fa-circle-exclamation"></i> Batch Name is required.
                                </div>
                            </div>
                        </div>
                        <div class="col-md-5">
                            <div class="form-group mb-3">
                                <label>Batch Code <span class="required">*</span></label>
                                <input type="text" class="form-control" name="batchCode" id="createBatchCode"
                                       placeholder="e.g. BA, S25"
                                       style="text-transform:uppercase;"
                                       oninput="this.value=this.value.toUpperCase()"
                                       autocomplete="off">
                                <div class="field-error-msg" id="err_createBatchCode" style="display:none;">
                                    <i class="fa-solid fa-circle-exclamation"></i> Batch Code is required.
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label>Start Date <span class="required">*</span></label>
                                <input type="date" class="form-control" name="startDate" id="createStartDate">
                                <div class="field-error-msg" id="err_createStartDate" style="display:none;">
                                    <i class="fa-solid fa-circle-exclamation"></i> Start Date is required.
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label>End Date <span class="required">*</span></label>
                                <input type="date" class="form-control" name="endDate" id="createEndDate">
                                <div class="field-error-msg" id="err_createEndDate" style="display:none;">
                                    <i class="fa-solid fa-circle-exclamation"></i> <span id="createEndDateErrTxt">End Date is required.</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label>Batch Location <span class="required">*</span></label>
                                <select class="form-control" name="batchLocation" id="createBatchLocation"
                                        onchange="clearFieldError('createBatchLocation')">
                                    <option value="">— Select Location —</option>
                                    <?php foreach ($batchLocations as $loc): ?>
                                        <option value="<?php echo $loc; ?>"><?php echo $loc; ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="field-error-msg" id="err_createBatchLocation" style="display:none;">
                                    <i class="fa-solid fa-circle-exclamation"></i> Please select a Batch Location.
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label>Batch Mode <span class="required">*</span></label>
                                <div class="mode-toggle-modal" id="createModeToggle">
                                    <div class="mode-btn-modal sel" onclick="setCreateMode('Online',this)"><i class="fa-solid fa-wifi me-1"></i>Online</div>
                                    <div class="mode-btn-modal" onclick="setCreateMode('Offline',this)"><i class="fa-solid fa-building me-1"></i>Offline</div>
                                    <div class="mode-btn-modal" onclick="setCreateMode('Hybrid',this)"><i class="fa-solid fa-shuffle me-1"></i>Hybrid</div>
                                </div>
                                <input type="hidden" name="batchMode" id="createBatchMode" value="Online">
                            </div>
                        </div>
                    </div>
                    <div class="form-group mb-3">
                        <label>Description</label>
                        <textarea class="form-control" name="description" rows="2"
                                  placeholder="Optional notes about this batch"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="submitCreateBatch()">
                        <i class="fa-solid fa-plus me-1"></i> Create Batch
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     EDIT BATCH MODAL
     ══════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="editBatchModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-md" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fa-solid fa-pen me-2"></i> Edit Batch</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <form method="POST" id="editBatchForm" novalidate>
                <input type="hidden" name="edit_batch" value="1">
                <input type="hidden" name="editId" id="editId">
                <div class="modal-body">
                    <div class="modal-section">Batch Details</div>
                    <div class="row">
                        <div class="col-md-7">
                            <div class="form-group mb-3">
                                <label>Batch Name <span class="required">*</span></label>
                                <input type="text" class="form-control" name="editBatchName" id="editBatchName"
                                       autocomplete="off">
                                <div class="field-error-msg" id="err_editBatchName" style="display:none;">
                                    <i class="fa-solid fa-circle-exclamation"></i> Batch Name is required.
                                </div>
                            </div>
                        </div>
                        <div class="col-md-5">
                            <div class="form-group mb-3">
                                <label>Batch Code <span class="required">*</span></label>
                                <input type="text" class="form-control" name="editBatchCode" id="editBatchCode"
                                       style="text-transform:uppercase;"
                                       oninput="this.value=this.value.toUpperCase()"
                                       autocomplete="off">
                                <div class="field-error-msg" id="err_editBatchCode" style="display:none;">
                                    <i class="fa-solid fa-circle-exclamation"></i> Batch Code is required.
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label>Start Date <span class="required">*</span></label>
                                <input type="date" class="form-control" name="editStartDate" id="editStartDate">
                                <div class="field-error-msg" id="err_editStartDate" style="display:none;">
                                    <i class="fa-solid fa-circle-exclamation"></i> Start Date is required.
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label>End Date <span class="required">*</span></label>
                                <input type="date" class="form-control" name="editEndDate" id="editEndDate">
                                <div class="field-error-msg" id="err_editEndDate" style="display:none;">
                                    <i class="fa-solid fa-circle-exclamation"></i> <span id="editEndDateErrTxt">End Date is required.</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label>Batch Location <span class="required">*</span></label>
                                <select class="form-control" name="editBatchLocation" id="editBatchLocation"
                                        onchange="clearFieldError('editBatchLocation')">
                                    <option value="">— Select Location —</option>
                                    <?php foreach ($batchLocations as $loc): ?>
                                        <option value="<?php echo $loc; ?>"><?php echo $loc; ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="field-error-msg" id="err_editBatchLocation" style="display:none;">
                                    <i class="fa-solid fa-circle-exclamation"></i> Please select a Batch Location.
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label>Batch Mode <span class="required">*</span></label>
                                <div class="mode-toggle-modal" id="editModeToggle">
                                    <div class="mode-btn-modal" id="editModeOnline"  onclick="setEditMode('Online')"><i class="fa-solid fa-wifi me-1"></i>Online</div>
                                    <div class="mode-btn-modal" id="editModeOffline" onclick="setEditMode('Offline')"><i class="fa-solid fa-building me-1"></i>Offline</div>
                                    <div class="mode-btn-modal" id="editModeHybrid"  onclick="setEditMode('Hybrid')"><i class="fa-solid fa-shuffle me-1"></i>Hybrid</div>
                                </div>
                                <input type="hidden" name="editBatchMode" id="editBatchMode" value="Offline">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label>Status</label>
                                <select class="form-control" name="editStatus" id="editStatus">
                                    <option value="Active">Active</option>
                                    <option value="Inactive">Inactive</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="form-group mb-3">
                        <label>Description</label>
                        <textarea class="form-control" name="editDescription" id="editDescription" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="submitEditBatch()">
                        <i class="fa-solid fa-floppy-disk me-1"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ADD INTERNS MODAL -->
<div class="modal fade" id="addInternsModal" tabindex="-1" role="dialog" aria-labelledby="addInternsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-md" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addInternsModalLabel"><i class="fa-solid fa-user-plus me-2"></i> Add/Manage Interns</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <div class="modal-section">Select Interns for Batch <span id="modalBatchName" style="color:var(--primary); font-weight:700;"></span></div>
                <div class="form-group mb-3">
                    <label for="batchInternsSelect">Select Interns</label>
                    <select class="form-control" id="batchInternsSelect" name="interns[]" multiple="multiple" style="width: 100%;"></select>
                    <small class="form-text text-muted mt-2">
                        Search by Intern ID, Name, or Email. Interns already in other batches can be reassigned here.
                    </small>
                </div>
            </div>
            <div class="modal-footer">
                <input type="hidden" id="modalBatchId">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" onclick="saveInternsForBatch()" class="btn btn-primary">
                    <i class="fa-solid fa-floppy-disk me-1"></i> Save Assignment
                </button>
            </div>
        </div>
    </div>
</div>

<!-- MOVE INTERN MODAL -->
<div class="modal fade" id="moveInternModal" tabindex="-1" role="dialog" aria-labelledby="moveInternModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content" style="border-radius:12px; overflow:hidden;">
            <div class="modal-header" style="background:var(--primary-light);">
                <h5 class="modal-title" id="moveInternModalLabel" style="color:var(--primary); font-weight:700;">
                    <i class="fa-solid fa-right-left me-2"></i> Move Intern to Another Batch
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body p-4">
                <input type="hidden" id="moveInternId">
                <input type="hidden" id="moveOldBatchId">

                <div class="mb-3">
                    <label class="form-label font-weight-bold text-muted small" style="letter-spacing:0.05em;">INTERN</label>
                    <div id="moveInternName" class="fw-bold text-dark font-weight-bold" style="font-size:1.05rem;"></div>
                </div>

                <div class="mb-3">
                    <label class="form-label font-weight-bold text-muted small" style="letter-spacing:0.05em;">CURRENT BATCH</label>
                    <div><span id="moveCurrentBatch" class="badge badge-light border text-dark font-weight-bold" style="font-size:0.88rem; padding:6px 12px;"></span></div>
                </div>

                <div class="form-group mb-0">
                    <label for="moveNewBatchSelect" class="form-label font-weight-bold text-dark" style="font-size:0.88rem;">SELECT NEW BATCH <span class="text-danger">*</span></label>
                    <select id="moveNewBatchSelect" class="form-control" style="width:100%;">
                        <option value="">-- Remove from Batch (No Batch) --</option>
                        <?php
                        $bRes = mysqli_query($con, "SELECT id, batchName FROM batches WHERE status = 'Active' ORDER BY batchName ASC");
                        if ($bRes) {
                            while ($bRow = mysqli_fetch_assoc($bRes)) {
                                echo '<option value="' . htmlspecialchars($bRow['batchName'], ENT_QUOTES) . '" data-batchid="' . $bRow['id'] . '">' . htmlspecialchars($bRow['batchName']) . '</option>';
                            }
                        }
                        ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary text-dark" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="submitMoveIntern()" style="font-weight:600;">
                    <i class="fa-solid fa-floppy-disk me-1"></i> Save & Move Intern
                </button>
            </div>
        </div>
    </div>
</div>

<script src="../vendors/js/vendor.bundle.base.js"></script>
<script src="../js/off-canvas.js"></script>
<script src="../js/hoverable-collapse.js"></script>
<script src="../js/template.js"></script>
<script src="../js/settings.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap4.min.js"></script>
<script src="../vendors/select2/select2.min.js"></script>

<script>
const allBatchesData  = <?php echo json_encode($allBatchesForPdf); ?>;
const BATCH_LOCATIONS = <?php echo json_encode($batchLocations); ?>;
const TODAY           = '<?php echo $todayDate; ?>';
</script>

<script>
// ══════════════════════════════════════════════════════════════════
// UTILITY: field-level error helpers
// ══════════════════════════════════════════════════════════════════
function showFieldError(fieldId, msg) {
    var field = document.getElementById(fieldId);
    var errEl = document.getElementById('err_' + fieldId);
    if (!field) return;
    field.classList.add('field-error');
    if (errEl) {
        if (msg) {
            // Update inner text span if present, else whole element
            var span = errEl.querySelector('span') || errEl;
            // Only override if there's a dedicated span for the text
            errEl.style.display = 'flex';
        }
        errEl.style.display = 'flex';
    }
    field.focus();
}

function clearFieldError(fieldId) {
    var field = document.getElementById(fieldId);
    var errEl = document.getElementById('err_' + fieldId);
    if (field) field.classList.remove('field-error');
    if (errEl) errEl.style.display = 'none';
}

function clearAllErrors(prefix) {
    var ids = [
        prefix + 'BatchName', prefix + 'BatchCode',
        prefix + 'StartDate', prefix + 'EndDate',
        prefix + 'BatchLocation'
    ];
    // Map form field IDs to error element IDs
    // Create form: createBatchName -> err_createBatchName
    // Edit form:   editBatchName   -> err_editBatchName
    ids.forEach(function(id) { clearFieldError(id); });
}

// ══════════════════════════════════════════════════════════════════
// INLINE REAL-TIME CLEAR on user interaction
// ══════════════════════════════════════════════════════════════════
$(document).ready(function () {

    // Create form – clear errors on interaction
    $('#createBatchName').on('input', function()  { if (this.value.trim()) clearFieldError('createBatchName'); });
    $('#createBatchCode').on('input', function()  { if (this.value.trim()) clearFieldError('createBatchCode'); });
    $('#createBatchLocation').on('change', function() { if (this.value) clearFieldError('createBatchLocation'); });
    function isValidCompleteDate(dateStr) {
        if (!dateStr) return false;
        var parts = dateStr.split('-');
        if (parts.length !== 3) return false;
        var y = parseInt(parts[0], 10);
        return y >= 1900 && y <= 2100;
    }

    $('#createStartDate').on('change blur', function() {
        if (isValidCompleteDate(this.value)) {
            clearFieldError('createStartDate');
            var endVal = $('#createEndDate').val();
            if (isValidCompleteDate(endVal)) {
                if (endVal <= this.value) {
                    document.getElementById('createEndDateErrTxt').textContent = 'End Date must be after Start Date.';
                    showFieldError('createEndDate');
                } else {
                    clearFieldError('createEndDate');
                }
            }
        }
    });
    $('#createEndDate').on('change blur', function() {
        var startVal = $('#createStartDate').val();
        if (isValidCompleteDate(this.value)) {
            if (isValidCompleteDate(startVal) && this.value <= startVal) {
                document.getElementById('createEndDateErrTxt').textContent = 'End Date must be after Start Date.';
                showFieldError('createEndDate');
            } else {
                clearFieldError('createEndDate');
            }
        }
    });

    // Edit form – clear errors on interaction
    $('#editBatchName').on('input', function()    { if (this.value.trim()) clearFieldError('editBatchName'); });
    $('#editBatchCode').on('input', function()    { if (this.value.trim()) clearFieldError('editBatchCode'); });
    $('#editBatchLocation').on('change', function() { if (this.value) clearFieldError('editBatchLocation'); });
    $('#editStartDate').on('change blur', function() {
        if (isValidCompleteDate(this.value)) {
            clearFieldError('editStartDate');
            var endVal = $('#editEndDate').val();
            if (isValidCompleteDate(endVal)) {
                if (endVal <= this.value) {
                    document.getElementById('editEndDateErrTxt').textContent = 'End Date must be after Start Date.';
                    showFieldError('editEndDate');
                } else {
                    clearFieldError('editEndDate');
                }
            }
        }
    });
    $('#editEndDate').on('change blur', function() {
        var startVal = $('#editStartDate').val();
        if (isValidCompleteDate(this.value)) {
            if (isValidCompleteDate(startVal) && this.value <= startVal) {
                document.getElementById('editEndDateErrTxt').textContent = 'End Date must be after Start Date.';
                showFieldError('editEndDate');
            } else {
                clearFieldError('editEndDate');
            }
        }
    });

    // Reset Create modal on open
    $('#createBatchModal').on('show.bs.modal', function () {
        document.getElementById('createBatchForm').reset();
        // reset mode toggle to Online
        $('#createBatchMode').val('Online');
        $('#createModeToggle .mode-btn-modal').removeClass('sel');
        $('#createModeToggle .mode-btn-modal').first().addClass('sel');
        // clear all field errors
        clearAllErrors('create');
        $('#createEndDate').removeAttr('min');
    });

    // Clear date errors when Edit modal opens
    $('#editBatchModal').on('show.bs.modal', function () {
        clearAllErrors('edit');
    });
});

// ══════════════════════════════════════════════════════════════════
// OPEN MODALS
// ══════════════════════════════════════════════════════════════════
function openCreateModal() {
    $('#createBatchModal').modal('show');
}

// ══════════════════════════════════════════════════════════════════
// CREATE BATCH — validate then submit
// ══════════════════════════════════════════════════════════════════
function submitCreateBatch() {
    var valid    = true;
    var firstErr = null;

    var name     = document.getElementById('createBatchName').value.trim();
    var code     = document.getElementById('createBatchCode').value.trim();
    var loc      = document.getElementById('createBatchLocation').value;
    var startVal = document.getElementById('createStartDate').value;
    var endVal   = document.getElementById('createEndDate').value;

    // Clear previous errors
    clearAllErrors('create');

    if (!name) {
        showFieldError('createBatchName');
        valid = false;
        if (!firstErr) firstErr = 'createBatchName';
    }
    if (!code) {
        showFieldError('createBatchCode');
        valid = false;
        if (!firstErr) firstErr = 'createBatchCode';
    }
    if (!startVal) {
        showFieldError('createStartDate');
        valid = false;
        if (!firstErr) firstErr = 'createStartDate';
    }
    if (!endVal) {
        document.getElementById('createEndDateErrTxt').textContent = 'End Date is required.';
        showFieldError('createEndDate');
        valid = false;
        if (!firstErr) firstErr = 'createEndDate';
    } else if (startVal && endVal <= startVal) {
        document.getElementById('createEndDateErrTxt').textContent = 'End Date must be after Start Date.';
        showFieldError('createEndDate');
        valid = false;
        if (!firstErr) firstErr = 'createEndDate';
    }
    if (!loc) {
        showFieldError('createBatchLocation');
        valid = false;
        if (!firstErr) firstErr = 'createBatchLocation';
    }

    if (!valid) {
        if (firstErr) {
            var el = document.getElementById(firstErr);
            if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        return;
    }

    document.getElementById('createBatchForm').submit();
}

// ══════════════════════════════════════════════════════════════════
// EDIT BATCH — populate modal then validate on submit
// ══════════════════════════════════════════════════════════════════
function openEditModal(batch) {
    document.getElementById('editId').value            = batch.id;
    document.getElementById('editBatchName').value     = batch.batchName    || '';
    document.getElementById('editBatchCode').value     = batch.batchCode    || '';
    document.getElementById('editStartDate').value     = batch.startDate    || '';
    document.getElementById('editEndDate').value       = batch.endDate      || '';
    document.getElementById('editDescription').value   = batch.description  || '';
    document.getElementById('editStatus').value        = batch.status       || 'Active';

    // Location: set dropdown (or first matching option)
    var locVal = batch.batchLocation || '';
    var locSel = document.getElementById('editBatchLocation');
    var matchFound = false;
    for (var i = 0; i < locSel.options.length; i++) {
        if (locSel.options[i].value === locVal) { matchFound = true; break; }
    }
    locSel.value = matchFound ? locVal : '';

    // Mode toggle
    setEditMode(batch.batchMode || 'Offline');

    // Update end-date minimum based on start
    if (batch.startDate) {
        var next = new Date(batch.startDate);
        next.setDate(next.getDate() + 1);
        document.getElementById('editEndDate').setAttribute('min', next.toISOString().split('T')[0]);
    }

    $('#editBatchModal').modal('show');
}

function submitEditBatch() {
    var valid    = true;
    var firstErr = null;

    var name     = document.getElementById('editBatchName').value.trim();
    var code     = document.getElementById('editBatchCode').value.trim();
    var loc      = document.getElementById('editBatchLocation').value;
    var startVal = document.getElementById('editStartDate').value;
    var endVal   = document.getElementById('editEndDate').value;

    clearAllErrors('edit');

    if (!name) {
        showFieldError('editBatchName');
        valid = false;
        if (!firstErr) firstErr = 'editBatchName';
    }
    if (!code) {
        showFieldError('editBatchCode');
        valid = false;
        if (!firstErr) firstErr = 'editBatchCode';
    }
    if (!startVal) {
        showFieldError('editStartDate');
        valid = false;
        if (!firstErr) firstErr = 'editStartDate';
    }
    if (!endVal) {
        document.getElementById('editEndDateErrTxt').textContent = 'End Date is required.';
        showFieldError('editEndDate');
        valid = false;
        if (!firstErr) firstErr = 'editEndDate';
    } else if (startVal && endVal <= startVal) {
        document.getElementById('editEndDateErrTxt').textContent = 'End Date must be after Start Date.';
        showFieldError('editEndDate');
        valid = false;
        if (!firstErr) firstErr = 'editEndDate';
    }
    if (!loc) {
        showFieldError('editBatchLocation');
        valid = false;
        if (!firstErr) firstErr = 'editBatchLocation';
    }

    if (!valid) {
        if (firstErr) {
            var el = document.getElementById(firstErr);
            if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        return;
    }

    document.getElementById('editBatchForm').submit();
}

// ══════════════════════════════════════════════════════════════════
// MODE TOGGLE HELPERS
// ══════════════════════════════════════════════════════════════════
function setCreateMode(mode, el) {
    document.getElementById('createBatchMode').value = mode;
    document.querySelectorAll('#createModeToggle .mode-btn-modal').forEach(function(b) { b.classList.remove('sel'); });
    el.classList.add('sel');
}

function setEditMode(mode) {
    document.getElementById('editBatchMode').value = mode;
    ['Online', 'Offline', 'Hybrid'].forEach(function(m) {
        var el = document.getElementById('editMode' + m);
        if (el) el.classList.toggle('sel', m === mode);
    });
}

// ══════════════════════════════════════════════════════════════════
// ROW EXPAND / COLLAPSE
// ══════════════════════════════════════════════════════════════════
function toggleHistory(rowId, batchRowEl, batchId) {
    const histRow = document.getElementById(rowId);
    if (!histRow) return;
    const isOpen = histRow.classList.contains('open');
    document.querySelectorAll('.intern-history-row.open').forEach(function(r) { r.classList.remove('open'); });
    document.querySelectorAll('.batch-row.expanded').forEach(function(r)      { r.classList.remove('expanded'); });
    if (!isOpen) {
        histRow.classList.add('open');
        batchRowEl.classList.add('expanded');
        if (!internDataCache[batchId]) fetchAndRenderInterns(batchId);
    }
}

// ══════════════════════════════════════════════════════════════════
// INTERN TABLE FETCH & RENDER
// ══════════════════════════════════════════════════════════════════
const internDataCache   = {};
const internDtInstances = {};

function fetchAndRenderInterns(batchId) {
    fetch('batch_master.php?ajax_interns=' + batchId)
        .then(function(r) { return r.json(); })
        .then(function(data) { internDataCache[batchId] = data; renderInternTable(batchId, data); })
        .catch(function() {
            document.getElementById('internTableContainer_' + batchId).innerHTML =
                '<div class="empty-interns"><i class="fa-solid fa-triangle-exclamation"></i> Failed to load interns.</div>';
        });
}

function renderInternTable(batchId, data) {
    var container = document.getElementById('internTableContainer_' + batchId);
    var badge     = document.getElementById('badge_' + batchId);
    var mainBadge = document.getElementById('mainBadge_' + batchId);
    var interns   = data.interns || [];

    if (badge)     badge.textContent = interns.length;
    if (mainBadge) {
        var countEl = mainBadge.querySelector('.count-val');
        if (countEl) countEl.textContent = interns.length;
        mainBadge.innerHTML = '<i class="fa-solid fa-user-graduate"></i> <span class="count-val">' + interns.length + '</span> intern' + (interns.length !== 1 ? 's' : '');
    }

    if (!interns.length) {
        container.innerHTML = '<div class="empty-interns"><i class="fa-solid fa-user-slash"></i>No interns assigned to this batch yet.</div>';
        return;
    }

    var tableId = 'internDT_' + batchId;
    var html = '<table id="' + tableId + '" class="intern-table" style="width:100%">';
    html += '<thead><tr>'
          + '<th>#</th><th>Intern ID</th><th>Name</th><th>Email</th>'
          + '<th>Role</th><th>Phase</th><th>Mode</th>'
          + '<th>DOJ</th><th>Expected End</th><th>Status</th><th>Actions</th>'
          + '</tr></thead><tbody>';

    interns.forEach(function(i, idx) {
        var phase = i.phase || '—';
        var pClass = phase.toLowerCase() === 'training'  ? 'phase-training'
                   : phase.toLowerCase() === 'project'   ? 'phase-project'
                   : phase.toLowerCase() === 'completed' ? 'phase-completed'
                   : 'phase-other';
        var isApproved = (i.approvalStatus || '').toLowerCase() === 'approved';
        var currentBatch = data.batchName || '';
        html += '<tr>'
             + '<td>' + (idx + 1) + '</td>'
             + '<td><span class="batch-code-badge" style="font-size:0.72rem;">' + esc(i.internId || '—') + '</span></td>'
             + '<td><strong>' + esc(i.fullName || '—') + '</strong></td>'
             + '<td style="color:#666;">' + esc(i.email || '—') + '</td>'
             + '<td>' + esc(i.role || '—') + '</td>'
             + '<td><span class="phase-badge ' + pClass + '">' + esc(phase) + '</span></td>'
             + '<td>' + esc(i.mode || '—') + '</td>'
             + '<td>' + fmtDate(i.doj) + '</td>'
             + '<td>' + fmtDate(i.expectedEndDate) + '</td>'
             + '<td><span class="badge-status ' + (isApproved ? 'badge-active' : 'badge-inactive') + '">'
             + esc(i.approvalStatus || '—') + '</span></td>'
             + '<td><button type="button" class="btn-edit text-primary border-primary bg-light" style="padding:3px 9px; font-size:0.75rem; font-weight:600;" onclick="openMoveInternModal(\'' + esc(i.internId || '') + '\', \'' + esc(i.fullName || '') + '\', \'' + esc(currentBatch) + '\', ' + batchId + ')" title="Move / Change Batch"><i class="fa-solid fa-right-left me-1"></i> Change Batch</button></td>'
             + '</tr>';
    });
    html += '</tbody></table>';
    container.innerHTML = html;

    // Init DataTable
    if (internDtInstances[batchId]) {
        try { internDtInstances[batchId].destroy(); } catch(e) {}
    }
    internDtInstances[batchId] = $('#' + tableId).DataTable({
        paging:   true,
        pageLength: 10,
        searching: true,
        ordering:  true,
        info:      true,
        responsive: true,
        dom: 'tip',
        language: { info: 'Showing _START_–_END_ of _TOTAL_ interns', paginate: { previous: '‹', next: '›' } }
    });
    updateDtInfo(batchId);
}

function fmtDate(d) {
    if (!d || d === '0000-00-00') return '—';
    var parts = d.split('-');
    if (parts.length !== 3) return d;
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return parts[2] + ' ' + months[parseInt(parts[1], 10) - 1] + ' ' + parts[0];
}

function esc(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function filterInternDT(batchId, val) {
    var dt = internDtInstances[batchId];
    if (dt) { dt.search(val).draw(); updateDtInfo(batchId); }
}

function updateDtInfo(batchId) {
    var dt  = internDtInstances[batchId];
    var el  = document.getElementById('internDtInfo_' + batchId);
    if (dt && el) {
        var info = dt.page.info();
        el.textContent = 'Showing ' + (info.recordsDisplay ? info.start + 1 : 0) + '–' + info.end + ' of ' + info.recordsDisplay + ' intern' + (info.recordsDisplay !== 1 ? 's' : '');
    }
}

// ══════════════════════════════════════════════════════════════════
// ADD INTERNS MODAL
// ══════════════════════════════════════════════════════════════════
function openAddInternsModal(batchId, batchName) {
    document.getElementById('modalBatchId').value   = batchId;
    document.getElementById('modalBatchName').textContent = batchName;

    var $sel = $('#batchInternsSelect');
    $sel.empty();

    if (!$sel.hasClass('select2-hidden-accessible')) {
        $sel.select2({
            placeholder: 'Type to search interns…',
            allowClear: true,
            dropdownParent: $('#addInternsModal')
        });
    }

    fetch('batch_master.php?ajax_all_interns=1')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            (data.interns || []).forEach(function(intern) {
                var label = intern.fullName + ' (' + intern.internId + ')' + (intern.batch ? ' [' + intern.batch + ']' : '');
                var opt   = new Option(label, intern.internId, false, false);
                $sel.append(opt);
            });
            $sel.trigger('change');
            $('#addInternsModal').modal('show');
        });
}

function saveInternsForBatch() {
    var batchId   = document.getElementById('modalBatchId').value;
    var internIds = $('#batchInternsSelect').val();

    if (!internIds || internIds.length === 0) {
        Swal.fire({ icon: 'warning', title: 'No Interns Selected', text: 'Please select at least one intern.', confirmButtonColor: '#2f55ff' });
        return;
    }

    var formData = new FormData();
    formData.append('batchId', batchId);
    internIds.forEach(function(id) { formData.append('internIds[]', id); });

    fetch('batch_master.php?ajax_assign_interns=1', { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success) {
                $('#addInternsModal').modal('hide');
                delete internDataCache[batchId];
                fetchAndRenderInterns(batchId);
                Swal.fire({ icon: 'success', title: 'Assigned!', text: 'Interns assigned to batch successfully.', confirmButtonColor: '#2f55ff', timer: 2000, showConfirmButton: false });
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: res.error || 'Failed to assign interns.', confirmButtonColor: '#2f55ff' });
            }
        });
}

// ══════════════════════════════════════════════════════════════════
// EXCEL EXPORT — single batch
// ══════════════════════════════════════════════════════════════════
function exportBatchExcel(batchId, batchName) {
    var btn     = document.getElementById('excelBtn_' + batchId);
    var spinner = document.getElementById('spinner_' + batchId);
    if (btn)     btn.disabled = true;
    if (spinner) spinner.style.display = 'inline-block';

    var data = internDataCache[batchId];
    if (data) {
        buildAndDownloadExcel(data, batchName);
        if (btn)     btn.disabled = false;
        if (spinner) spinner.style.display = 'none';
        return;
    }

    fetch('batch_master.php?ajax_interns=' + batchId)
        .then(function(r) { return r.json(); })
        .then(function(d) {
            internDataCache[batchId] = d;
            buildAndDownloadExcel(d, batchName);
        })
        .finally(function() {
            if (btn)     btn.disabled = false;
            if (spinner) spinner.style.display = 'none';
        });
}

function buildAndDownloadExcel(data, batchName) {
    var wb = new ExcelJS.Workbook();
    var ws = wb.addWorksheet(batchName || 'Batch');

    ws.columns = [
        { header: 'Intern ID',     key: 'internId',        width: 15 },
        { header: 'Full Name',     key: 'fullName',         width: 22 },
        { header: 'Email',         key: 'email',            width: 28 },
        { header: 'Role',          key: 'role',             width: 18 },
        { header: 'Phase',         key: 'phase',            width: 14 },
        { header: 'Mode',          key: 'mode',             width: 12 },
        { header: 'DOJ',           key: 'doj',              width: 14 },
        { header: 'Expected End',  key: 'expectedEndDate',  width: 14 },
        { header: 'Status',        key: 'approvalStatus',   width: 14 },
        { header: 'Branch',        key: 'branch',           width: 16 },
        { header: 'Location',      key: 'intern_location',  width: 16 },
        { header: 'Mentor',        key: 'mentorName',       width: 20 },
    ];

    ws.getRow(1).font  = { bold: true, color: { argb: 'FFFFFFFF' } };
    ws.getRow(1).fill  = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF2F55FF' } };
    ws.getRow(1).alignment = { vertical: 'middle', horizontal: 'center' };

    (data.interns || []).forEach(function(i) { ws.addRow(i); });

    wb.xlsx.writeBuffer().then(function(buf) {
        var blob = new Blob([buf], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
        var url  = URL.createObjectURL(blob);
        var a    = document.createElement('a');
        a.href   = url;
        a.download = (batchName || 'Batch') + '_Interns.xlsx';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    });
}

// ══════════════════════════════════════════════════════════════════
// EXCEL EXPORT — all batches summary
// ══════════════════════════════════════════════════════════════════
function exportBatchesExcel() {
    var wb = new ExcelJS.Workbook();
    var ws = wb.addWorksheet('All Batches');

    ws.columns = [
        { header: '#',           key: 'sr',            width: 6  },
        { header: 'Batch Name',  key: 'batchName',     width: 22 },
        { header: 'Batch Code',  key: 'batchCode',     width: 14 },
        { header: 'Location',    key: 'batchLocation', width: 16 },
        { header: 'Mode',        key: 'batchMode',     width: 12 },
        { header: 'Start Date',  key: 'startDate',     width: 14 },
        { header: 'End Date',    key: 'endDate',       width: 14 },
        { header: 'Interns',     key: 'internCount',   width: 10 },
        { header: 'Status',      key: 'status',        width: 12 },
        { header: 'Description', key: 'description',   width: 30 },
    ];

    ws.getRow(1).font  = { bold: true, color: { argb: 'FFFFFFFF' } };
    ws.getRow(1).fill  = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF2F55FF' } };
    ws.getRow(1).alignment = { vertical: 'middle', horizontal: 'center' };

    allBatchesData.forEach(function(b, idx) {
        ws.addRow({
            sr:            idx + 1,
            batchName:     b.batchName     || '',
            batchCode:     b.batchCode     || '',
            batchLocation: b.batchLocation || '',
            batchMode:     b.batchMode     || '',
            startDate:     b.startDate     || '',
            endDate:       b.endDate       || '',
            internCount:   b.internCount   || 0,
            status:        b.status        || '',
            description:   b.description   || '',
        });
    });

    wb.xlsx.writeBuffer().then(function(buf) {
        var blob = new Blob([buf], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
        var url  = URL.createObjectURL(blob);
        var a    = document.createElement('a');
        a.href   = url;
        a.download = 'All_Batches.xlsx';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    });
}

// ══════════════════════════════════════════════════════════════════
// MOVE / CHANGE INTERN BATCH
// ══════════════════════════════════════════════════════════════════
function openMoveInternModal(internId, fullName, currentBatch, currentBatchId) {
    document.getElementById('moveInternId').value = internId;
    document.getElementById('moveOldBatchId').value = currentBatchId;
    document.getElementById('moveInternName').textContent = fullName + ' (' + internId + ')';
    document.getElementById('moveCurrentBatch').textContent = currentBatch || 'None';
    $('#moveNewBatchSelect').val(currentBatch || '');
    $('#moveInternModal').modal('show');
}

function submitMoveIntern() {
    var internId     = document.getElementById('moveInternId').value;
    var oldBatchId   = document.getElementById('moveOldBatchId').value;
    var newBatchName = $('#moveNewBatchSelect').val() || '';

    if (!internId) return;

    var formData = new FormData();
    formData.append('internId', internId);
    formData.append('newBatchName', newBatchName);

    fetch('batch_master.php?ajax_change_intern_batch=1', { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success) {
                $('#moveInternModal').modal('hide');

                // Refresh old batch intern table
                delete internDataCache[oldBatchId];
                fetchAndRenderInterns(oldBatchId);

                // Refresh new batch if selected & open
                var selectedOpt = $('#moveNewBatchSelect option:selected');
                var newBatchId = selectedOpt.data('batchid');
                if (newBatchId) {
                    delete internDataCache[newBatchId];
                    var newRow = document.getElementById('historyRow_' + newBatchId);
                    if (newRow && newRow.classList.contains('open')) {
                        fetchAndRenderInterns(newBatchId);
                    }
                }

                Swal.fire({
                    icon: 'success',
                    title: 'Moved!',
                    text: 'Intern batch updated successfully.',
                    confirmButtonColor: '#2f55ff',
                    timer: 1500,
                    showConfirmButton: false
                }).then(function() {
                    window.location.reload();
                });
                setTimeout(function() { window.location.reload(); }, 1500);
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: res.error || 'Failed to move intern.',
                    confirmButtonColor: '#2f55ff'
                });
            }
        })
        .catch(function(err) {
            Swal.fire({ icon: 'error', title: 'Server Error', text: 'Something went wrong.', confirmButtonColor: '#2f55ff' });
        });
}
</script>
</body>
</html>