<?php
session_start();
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}

include('../include/db_config.php');

$userId   = $_SESSION['userId'];
$userRow  = mysqli_fetch_assoc(mysqli_query($con, "SELECT fullName, designation, userRole FROM signup WHERE userId='$userId'"));
$userName = $userRow ? htmlspecialchars($userRow['fullName']) : 'Unknown';
$userDesigClean = strtolower(str_replace([' ', '_'], '', $userRow['designation'] ?? ''));
$userRoleClean  = strtolower(str_replace([' ', '_'], '', $userRow['userRole'] ?? ''));

$rhVariants     = ['resourcehead', 'resourceallocator', 'resourcemanager', 'seniorintern', 'admin', 'superadmin', 'hr'];
$isResourceHead = in_array($userDesigClean, $rhVariants) || in_array($userRoleClean, $rhVariants);
$isScrumHost    = ($userDesigClean === 'scrumhost' || $userName === 'Divya Y.'); 
$canHostScrum   = $isResourceHead || $isScrumHost;

if (!$canHostScrum) {
    echo "<script>alert('Unauthorized Access'); window.location.href='logout.php';</script>";
    exit;
}

$todayDate  = date('Y-m-d');
$todayLabel = date('l, d M Y');

// Fetch active interns in Working Phase
$internResult = mysqli_query($con, "
    SELECT s.userId, s.internId, s.fullName,
           COALESCE(i.branch, 'Not Assigned') AS branch,
           COALESCE(i.batch,  'N/A')           AS batch,
           COALESCE(i.phase,  'Working Phase') AS phase,
           COALESCE(TIME_FORMAT(i.workStartTime, '%H:%i'), '09:00') AS workStart,
           COALESCE(TIME_FORMAT(i.workEndTime,   '%H:%i'), '17:00') AS workEnd
    FROM signup s
    LEFT JOIN interns i ON s.internId = i.internId
    WHERE s.status = 'active'
      AND (i.phase = 'Working Phase' OR i.phase IS NULL)
    ORDER BY s.fullName ASC
");
if (!$internResult) die("Query failed: " . mysqli_error($con));

$interns = [];
while ($r = mysqli_fetch_assoc($internResult)) {
    $interns[$r['internId']] = $r;
}
$totalInterns = count($interns);

// Fetch today's attendance
$attendanceMap = [];
if (!empty($interns)) {
    $userIdList = implode("','", array_map(fn($i) => mysqli_real_escape_string($con, $i['userId']), $interns));
    $attResult = mysqli_query($con, "
        SELECT userId, punchInTime, punchOutTime, status
        FROM attendance
        WHERE date = '$todayDate' AND userId IN ('$userIdList')
        ORDER BY dateTime DESC
    ");
    if ($attResult) {
        while ($a = mysqli_fetch_assoc($attResult)) {
            if (!isset($attendanceMap[$a['userId']])) $attendanceMap[$a['userId']] = $a;
        }
    }
}

// Fetch today's allocations
$allocMap = [];
if (!empty($interns)) {
    $internIdList = implode("','", array_map(fn($i) => mysqli_real_escape_string($con, $i), array_keys($interns)));
    $allocResult = mysqli_query($con, "
        SELECT a.id, a.internId COLLATE utf8mb4_unicode_ci AS internId,
               a.projectName, a.clientName,
               COALESCE(a.allocStartTime, '09:00') AS allocStartTime,
               COALESCE(a.allocEndTime,   '17:00') AS allocEndTime,
               a.startDate, a.endDate
        FROM internAllocations a
        WHERE a.startDate <= '$todayDate' AND a.endDate >= '$todayDate'
          AND a.internId COLLATE utf8mb4_unicode_ci IN ('$internIdList')
    ");
    if ($allocResult) {
        while ($a = mysqli_fetch_assoc($allocResult)) $allocMap[$a['internId']][] = $a;
    }
}

// Pending adjustments for Resource Head
$pendingAdjustments = [];
if ($isResourceHead) {
    $adjResult = mysqli_query($con, "
        SELECT ta.*, s.fullName AS requestedByName, i.fullName AS internName
        FROM scrumTaskAdjustments ta
        LEFT JOIN signup s ON s.userId = ta.requestedBy
        LEFT JOIN signup i ON i.internId = ta.internId
        WHERE ta.status = 'pending'
        ORDER BY ta.createdAt DESC
    ");
    if ($adjResult) while ($a = mysqli_fetch_assoc($adjResult)) $pendingAdjustments[] = $a;
}

$presentCount = 0; $absentCount = 0; $allocatedToday = 0;
foreach ($interns as $iid => $intern) {
    $uid = $intern['userId'];
    if (isset($attendanceMap[$uid]) && !empty($attendanceMap[$uid]['punchInTime'])) $presentCount++;
    else $absentCount++;
    if (!empty($allocMap[$iid])) $allocatedToday++;
}
$pendingApprovalCount = count($pendingAdjustments);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>Scrum Call — <?php echo $todayLabel; ?></title>
<?php include('../partials/_links.php'); ?>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap4.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
/* ── RESET & BASE ──────────────────────────────────────────────── */
body, .main-panel, .content-wrapper {
    font-family: 'DM Sans', sans-serif !important;
    background: #f4f5f7 !important;
}

/* ── TOKENS ────────────────────────────────────────────────────── */
:root {
    --ink:       #1c1c2e;
    --ink-2:     #4a4a6a;
    --ink-3:     #9393b0;
    --surface:   #ffffff;
    --bg:        #f4f5f7;
    --border:    #e8e8f0;

    --brand:     #5a56e0;
    --brand-lt:  #eeeeff;
    --brand-dk:  #3f3cb0;

    --green:     #2e7d4f;
    --green-lt:  #e8f5ee;
    --red:       #c0392b;
    --red-lt:    #fdecea;
    --amber:     #b45309;
    --amber-lt:  #fff8e6;

    --radius-sm: 8px;
    --radius:    12px;
    --radius-lg: 16px;
    --shadow:    0 1px 4px rgba(0,0,0,.07), 0 4px 16px rgba(0,0,0,.05);
    --shadow-md: 0 2px 8px rgba(0,0,0,.09), 0 8px 24px rgba(0,0,0,.07);
}

/* ── PAGE WRAPPER ──────────────────────────────────────────────── */
.sc-page { padding: 28px 28px 48px; }

/* ── PAGE HEADER ───────────────────────────────────────────────── */
.sc-page-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 16px;
    margin-bottom: 28px;
}
.sc-page-header .ph-title {
    font-size: 1.35rem;
    font-weight: 700;
    color: var(--ink);
    margin: 0 0 4px;
    letter-spacing: -.02em;
}
.sc-page-header .ph-sub {
    font-size: 0.82rem;
    color: var(--ink-3);
    margin: 0;
}
.sc-date-chip {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 20px;
    padding: 6px 14px;
    font-size: 0.78rem;
    font-weight: 600;
    color: var(--ink-2);
    display: inline-flex;
    align-items: center;
    gap: 6px;
    box-shadow: var(--shadow);
}
.sc-date-chip i { color: var(--brand); }

/* ── STAT CARDS ────────────────────────────────────────────────── */
.stat-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}
@media (max-width: 900px) { .stat-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 480px) { .stat-grid { grid-template-columns: 1fr 1fr; } }

.stat-card {
    background: var(--surface);
    border-radius: var(--radius);
    padding: 20px 22px;
    box-shadow: var(--shadow);
    display: flex;
    align-items: center;
    gap: 16px;
    border: 1px solid var(--border);
    transition: box-shadow .2s;
}
.stat-card:hover { box-shadow: var(--shadow-md); }
.stat-icon {
    width: 46px; height: 46px;
    border-radius: var(--radius-sm);
    display: flex; align-items: center; justify-content: center;
    font-size: 1rem; flex-shrink: 0;
}
.stat-card.s-total  .stat-icon { background: var(--brand-lt);  color: var(--brand); }
.stat-card.s-present .stat-icon { background: var(--green-lt); color: var(--green); }
.stat-card.s-absent  .stat-icon { background: var(--red-lt);   color: var(--red); }
.stat-card.s-alloc   .stat-icon { background: var(--amber-lt); color: var(--amber); }
.stat-num  { font-size: 1.75rem; font-weight: 700; color: var(--ink); line-height: 1; }
.stat-lbl  { font-size: 0.75rem; color: var(--ink-3); margin-top: 3px; font-weight: 500; }

/* ── MAIN CARD ─────────────────────────────────────────────────── */
.sc-card {
    background: var(--surface);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow);
    border: 1px solid var(--border);
    overflow: hidden;
    margin-bottom: 24px;
}
.sc-card-header {
    padding: 20px 24px 16px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
}
.sc-card-header .ch-title { font-size: 0.95rem; font-weight: 700; color: var(--ink); margin: 0; }
.sc-card-header .ch-sub   { font-size: 0.76rem; color: var(--ink-3); margin-top: 2px; }
.sc-card-body { padding: 20px 24px; }

/* ── TOOLBAR ───────────────────────────────────────────────────── */
.sc-toolbar {
    display: flex; align-items: center;
    justify-content: space-between;
    flex-wrap: wrap; gap: 12px;
    margin-bottom: 18px;
}
.sc-search {
    display: flex; align-items: center;
    background: var(--bg);
    border: 1.5px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 0 12px; height: 36px;
    gap: 8px; min-width: 220px;
    transition: border .15s;
}
.sc-search:focus-within { border-color: var(--brand); background: #fff; }
.sc-search i { color: var(--ink-3); font-size: 0.8rem; }
.sc-search input { border: none; outline: none; background: transparent; font-size: 0.82rem; color: var(--ink); width: 160px; font-family: inherit; }

.sc-filters { display: flex; gap: 6px; flex-wrap: wrap; }
.sc-filter {
    background: var(--bg); border: 1.5px solid var(--border);
    color: var(--ink-2); border-radius: 20px;
    padding: 5px 14px; font-size: 0.76rem; font-weight: 600;
    cursor: pointer; transition: all .15s; white-space: nowrap;
    font-family: inherit;
}
.sc-filter.active, .sc-filter:hover { background: var(--brand); border-color: var(--brand); color: #fff; }

.sc-save-btn {
    background: var(--green); color: #fff;
    border: none; border-radius: var(--radius-sm);
    padding: 8px 18px; font-size: 0.8rem; font-weight: 600;
    cursor: pointer; display: flex; align-items: center;
    gap: 7px; transition: background .15s; font-family: inherit;
    white-space: nowrap;
}
.sc-save-btn:hover { background: #235c3b; }
.sc-save-btn:disabled { opacity: .6; cursor: not-allowed; }

/* ── TABLE ─────────────────────────────────────────────────────── */
#scrumTable { width: 100% !important; border-collapse: collapse; }
#scrumTable thead th {
    background: #f9f9fc;
    color: var(--ink-2);
    font-size: 0.73rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .05em;
    padding: 11px 16px !important;
    border-bottom: 2px solid var(--border) !important;
    border-top: none !important;
    white-space: nowrap;
    vertical-align: middle;
}
#scrumTable tbody td {
    padding: 13px 16px !important;
    vertical-align: middle;
    font-size: 0.84rem;
    border-color: var(--border) !important;
    color: var(--ink);
}
#scrumTable tbody tr { transition: background .12s; }
#scrumTable tbody tr:hover { background: #fafaff !important; }
#scrumTable tbody tr:nth-child(even) { background: #fafafa; }

/* ── INTERN CELL ───────────────────────────────────────────────── */
.intern-cell { display: flex; align-items: center; gap: 12px; }
.intern-avatar {
    width: 36px; height: 36px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.78rem; font-weight: 700; flex-shrink: 0;
}
.intern-name  { font-size: 0.875rem; font-weight: 600; color: var(--ink); }
.intern-hours { font-size: 0.7rem; color: var(--ink-3); margin-top: 2px; }

/* ── ATTENDANCE TOGGLE ─────────────────────────────────────────── */
.att-wrap { display: flex; align-items: center; justify-content: center; }
.att-toggle {
    display: inline-flex; border-radius: 8px;
    overflow: hidden; border: 1.5px solid var(--border);
    background: var(--bg);
}
.att-btn {
    background: transparent; border: none;
    padding: 6px 14px; font-size: 0.74rem; font-weight: 600;
    cursor: pointer; transition: all .13s; white-space: nowrap;
    color: var(--ink-3); font-family: inherit;
    display: flex; align-items: center; gap: 5px;
}
.att-btn.present.active { background: var(--green-lt); color: var(--green); }
.att-btn.absent.active  { background: var(--red-lt);   color: var(--red); }
.att-btn:hover:not(.active) { background: #f0f0ff; color: var(--brand); }
.att-sep { width: 1px; background: var(--border); height: 100%; }

/* ── TASK PILLS ────────────────────────────────────────────────── */
.task-list { display: flex; flex-direction: column; gap: 5px; }
.task-pill {
    display: inline-flex; align-items: center; gap: 7px;
    background: var(--bg); border: 1px solid var(--border);
    border-radius: 6px; padding: 5px 10px;
    font-size: 0.75rem; max-width: 230px;
}
.task-dot  { width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; }
.task-name { font-weight: 600; color: var(--ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 140px; }
.task-time { color: var(--ink-3); font-size: 0.67rem; white-space: nowrap; }
.task-none { font-size: 0.76rem; color: var(--ink-3); font-style: italic; }

.btn-change {
    margin-top: 6px; background: transparent;
    border: 1.5px dashed var(--border); color: var(--brand);
    border-radius: 6px; padding: 3px 10px; font-size: 0.71rem;
    font-weight: 600; cursor: pointer; transition: all .13s;
    font-family: inherit;
}
.btn-change:hover { background: var(--brand-lt); border-color: var(--brand); border-style: solid; }

/* ── PHASE BADGE ───────────────────────────────────────────────── */
.phase-pill {
    display: inline-block; font-size: 0.68rem; font-weight: 600;
    padding: 3px 9px; border-radius: 12px; white-space: nowrap;
}
.phase-working  { background: var(--brand-lt); color: var(--brand); }
.phase-training { background: var(--amber-lt); color: var(--amber); }

/* ── PLAN BTN ──────────────────────────────────────────────────── */
.btn-plan {
    background: var(--brand-lt); color: var(--brand);
    border: 1px solid #d0cff8; border-radius: 6px;
    padding: 5px 12px; font-size: 0.72rem; font-weight: 600;
    cursor: pointer; transition: all .13s; font-family: inherit;
    white-space: nowrap;
}
.btn-plan:hover { background: var(--brand); color: #fff; }

/* ── APPROVAL SECTION ──────────────────────────────────────────── */
.approval-card {
    background: var(--surface);
    border-radius: var(--radius-lg);
    border: 1px solid var(--border);
    box-shadow: var(--shadow);
    overflow: hidden;
    margin-bottom: 24px;
}
.approval-card-header {
    padding: 18px 24px;
    border-bottom: 1px solid var(--border);
    display: flex; align-items: center; justify-content: space-between;
}
.approval-card-header h5 { font-size: 0.95rem; font-weight: 700; color: var(--ink); margin: 0; }
.pending-badge {
    background: var(--red-lt); color: var(--red);
    border: 1px solid #f5c5c0; border-radius: 12px;
    padding: 3px 10px; font-size: 0.72rem; font-weight: 700;
}
.adj-list { padding: 16px 24px; display: flex; flex-direction: column; gap: 14px; }
.adj-item {
    background: #fafafa;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 18px 20px;
}
.adj-intern { font-size: 0.9rem; font-weight: 700; color: var(--ink); }
.adj-meta   { font-size: 0.74rem; color: var(--ink-3); margin-top: 3px; }
.adj-compare {
    display: grid;
    grid-template-columns: 1fr 32px 1fr;
    gap: 10px; align-items: center;
    margin: 14px 0;
}
@media (max-width: 600px) {
    .adj-compare { grid-template-columns: 1fr; }
    .adj-arrow { transform: rotate(90deg); }
}
.adj-box {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 12px 14px;
}
.adj-box.old { border-left: 3px solid var(--red); }
.adj-box.new { border-left: 3px solid var(--green); }
.adj-box-lbl { font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--ink-3); margin-bottom: 5px; }
.adj-box-proj { font-size: 0.85rem; font-weight: 600; color: var(--ink); }
.adj-box-time { font-size: 0.72rem; color: var(--ink-3); margin-top: 3px; }
.adj-arrow-icon { text-align: center; color: var(--ink-3); font-size: 1rem; }
.adj-actions { display: flex; gap: 8px; flex-wrap: wrap; }
.btn-adj {
    border-radius: var(--radius-sm); padding: 6px 16px;
    font-size: 0.77rem; font-weight: 600; cursor: pointer;
    border: 1px solid; transition: all .13s; font-family: inherit;
    display: flex; align-items: center; gap: 5px;
}
.btn-adj.approve { background: var(--green-lt); color: var(--green); border-color: #9dcfb0; }
.btn-adj.approve:hover { background: var(--green); color: #fff; }
.btn-adj.reject  { background: var(--red-lt); color: var(--red); border-color: #f0a8a0; }
.btn-adj.reject:hover  { background: var(--red); color: #fff; }
.btn-adj.modify  { background: var(--amber-lt); color: var(--amber); border-color: #f5cc80; }
.btn-adj.modify:hover  { background: var(--amber); color: #fff; }

/* ── MODAL ─────────────────────────────────────────────────────── */
.sc-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(15,15,30,.5); z-index: 1060;
    align-items: center; justify-content: center;
    backdrop-filter: blur(2px);
}
.sc-overlay.open { display: flex; }
.sc-modal {
    background: var(--surface);
    border-radius: var(--radius-lg);
    width: 520px; max-width: calc(100vw - 32px);
    max-height: 88vh; overflow: hidden;
    display: flex; flex-direction: column;
    box-shadow: 0 24px 64px rgba(0,0,0,.18);
}
.sc-modal-head {
    background: var(--ink);
    color: #fff; padding: 18px 22px;
    display: flex; align-items: center;
    justify-content: space-between; flex-shrink: 0;
}
.sc-modal-head h5 { margin: 0; font-size: 0.92rem; font-weight: 700; }
.sc-modal-close {
    background: rgba(255,255,255,.12); border: none;
    color: #fff; width: 28px; height: 28px;
    border-radius: 50%; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 1rem; transition: background .13s;
}
.sc-modal-close:hover { background: rgba(255,255,255,.22); }
.sc-modal-body { flex: 1; overflow-y: auto; padding: 22px; }
.sc-modal-foot {
    padding: 16px 22px; border-top: 1px solid var(--border);
    display: flex; gap: 8px; justify-content: flex-end; flex-shrink: 0;
}
.current-box {
    background: var(--bg); border: 1px solid var(--border);
    border-radius: var(--radius-sm); padding: 14px;
    margin-bottom: 18px;
}
.current-box-lbl { font-size: 0.69rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: var(--brand); margin-bottom: 8px; }
.task-form-row {
    background: var(--bg); border: 1px solid var(--border);
    border-radius: var(--radius-sm); padding: 14px;
    margin-bottom: 12px; position: relative;
}
.task-form-num { font-size: 0.72rem; font-weight: 700; color: var(--brand); margin-bottom: 10px; }
.form-lbl { font-size: 0.75rem; font-weight: 600; color: var(--ink-2); margin-bottom: 4px; display: block; }
.form-ctrl {
    border: 1.5px solid var(--border); border-radius: 7px;
    padding: 7px 10px; font-size: 0.82rem; width: 100%;
    outline: none; transition: border .15s; background: var(--surface);
    font-family: inherit; color: var(--ink);
}
.form-ctrl:focus { border-color: var(--brand); box-shadow: 0 0 0 3px rgba(90,86,224,.1); }
.btn-remove-task {
    background: var(--red-lt); color: var(--red);
    border: 1px solid #f0a8a0; border-radius: 6px;
    padding: 4px 10px; font-size: 0.71rem; font-weight: 600;
    cursor: pointer; font-family: inherit;
}
.btn-remove-task:hover { background: var(--red); color: #fff; }
.btn-add-task {
    background: var(--brand-lt); color: var(--brand);
    border: 1.5px dashed #c0bef8; border-radius: 7px;
    padding: 7px 16px; font-size: 0.78rem; font-weight: 600;
    cursor: pointer; transition: all .13s; width: 100%;
    font-family: inherit; margin-top: 2px;
}
.btn-add-task:hover { background: var(--brand); color: #fff; border-style: solid; }
.info-note {
    border-radius: 7px; padding: 8px 12px;
    font-size: 0.75rem; font-weight: 600;
    display: inline-flex; align-items: center; gap: 6px;
    margin-bottom: 14px;
}
.info-note.rh   { background: var(--green-lt); color: var(--green); border: 1px solid #9dcfb0; }
.info-note.host { background: var(--amber-lt); color: var(--amber); border: 1px solid #f5cc80; }
.ta-warn {
    background: #fff3cd; border: 1px solid #fcd34d;
    border-radius: 6px; padding: 8px 11px;
    font-size: 0.76rem; color: #856404;
    margin-top: 10px; display: none;
}
.ta-warn.show { display: block; }
.btn-submit {
    background: var(--brand); color: #fff;
    border: none; border-radius: var(--radius-sm);
    padding: 9px 22px; font-size: 0.82rem; font-weight: 700;
    cursor: pointer; transition: background .13s;
    display: flex; align-items: center; gap: 7px;
    font-family: inherit;
}
.btn-submit:hover { background: var(--brand-dk); }
.btn-submit:disabled { opacity: .6; cursor: not-allowed; }
.btn-cancel {
    background: var(--bg); color: var(--ink-2);
    border: 1.5px solid var(--border); border-radius: var(--radius-sm);
    padding: 9px 16px; font-size: 0.82rem; cursor: pointer;
    font-family: inherit;
}
.btn-cancel:hover { background: var(--border); }

/* ── TOAST ─────────────────────────────────────────────────────── */
.sc-toast {
    position: fixed; bottom: 24px; right: 24px;
    border-radius: var(--radius-sm); padding: 12px 18px;
    font-size: 0.82rem; font-weight: 600; z-index: 9999;
    display: none; align-items: center; gap: 10px;
    box-shadow: 0 4px 20px rgba(0,0,0,.18);
    animation: slideUp .25s ease;
}
.sc-toast.show   { display: flex; }
.sc-toast.success { background: var(--green); color: #fff; }
.sc-toast.error   { background: var(--red);   color: #fff; }
@keyframes slideUp { from { transform: translateY(12px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }

/* ── DATATABLE OVERRIDES ───────────────────────────────────────── */
#scrumTable_wrapper .dataTables_filter { display: none; }
#scrumTable_wrapper .dataTables_length label { font-size: 0.78rem; color: var(--ink-2); }
#scrumTable_wrapper .dataTables_length select { border: 1.5px solid var(--border); border-radius: 6px; padding: 3px 8px; font-size: 0.78rem; outline: none; }
#scrumTable_wrapper .dataTables_info { font-size: 0.76rem; color: var(--ink-3); padding-top: 10px; }
#scrumTable_wrapper .dataTables_paginate .paginate_button.current,
#scrumTable_wrapper .dataTables_paginate .paginate_button.current:hover { background: var(--brand) !important; border-color: var(--brand) !important; color: #fff !important; border-radius: 6px !important; }
#scrumTable_wrapper .dataTables_paginate .paginate_button { border-radius: 6px !important; font-size: 0.78rem !important; padding: 4px 10px !important; }
#scrumTable_wrapper .dataTables_paginate .paginate_button:hover { background: var(--brand-lt) !important; color: var(--brand) !important; border-color: var(--brand-lt) !important; }
</style>
</head>
<body>
<div class="container-scroller">
    <?php include('../partials/_navbar.php'); ?>
    <div class="container-fluid page-body-wrapper">
        <?php include('../partials/_sidebar.php'); include('../partials/_settings-panel.php'); ?>
        <div class="main-panel">
        <div class="content-wrapper">
        <div class="sc-page">

            <!-- Page Header -->
            <div class="sc-page-header">
                <div>
                    <h1 class="ph-title">
                        <i class="fa fa-microphone" style="color:var(--brand);margin-right:10px;font-size:1.1rem;"></i>
                        Scrum Call
                    </h1>
                    <p class="ph-sub">Mark attendance, review tasks, manage adjustments</p>
                </div>
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    <span class="sc-date-chip">
                        <i class="fa fa-calendar"></i>
                        <?php echo $todayLabel; ?>
                    </span>
                    <?php if ($isResourceHead && $pendingApprovalCount > 0): ?>
                    <span style="background:var(--red-lt);color:var(--red);border:1px solid #f0a8a0;border-radius:20px;padding:6px 14px;font-size:0.76rem;font-weight:700;display:inline-flex;align-items:center;gap:6px;">
                        <i class="fa fa-clock-o"></i>
                        <?php echo $pendingApprovalCount; ?> pending approval<?php echo $pendingApprovalCount > 1 ? 's' : ''; ?>
                    </span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Stat Cards -->
            <div class="stat-grid">
                <div class="stat-card s-total">
                    <div class="stat-icon"><i class="fa fa-users"></i></div>
                    <div>
                        <div class="stat-num"><?php echo $totalInterns; ?></div>
                        <div class="stat-lbl">Total Interns</div>
                    </div>
                </div>
                <div class="stat-card s-present">
                    <div class="stat-icon"><i class="fa fa-check-circle"></i></div>
                    <div>
                        <div class="stat-num" id="stat-present"><?php echo $presentCount; ?></div>
                        <div class="stat-lbl">Present Today</div>
                    </div>
                </div>
                <div class="stat-card s-absent">
                    <div class="stat-icon"><i class="fa fa-times-circle"></i></div>
                    <div>
                        <div class="stat-num" id="stat-absent"><?php echo $absentCount; ?></div>
                        <div class="stat-lbl">Absent</div>
                    </div>
                </div>
                <div class="stat-card s-alloc">
                    <div class="stat-icon"><i class="fa fa-briefcase"></i></div>
                    <div>
                        <div class="stat-num"><?php echo $allocatedToday; ?></div>
                        <div class="stat-lbl">Allocated Today</div>
                    </div>
                </div>
            </div>

            <!-- Main Table Card -->
            <div class="sc-card">
                <div class="sc-card-header">
                    <div>
                        <div class="ch-title">Today's Intern Report</div>
                        <div class="ch-sub">Mark attendance and manage task assignments</div>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px;font-size:0.72rem;color:var(--ink-3);font-weight:600;">
                        <span style="width:8px;height:8px;border-radius:50%;background:var(--green);display:inline-block;"></span>Present
                        <span style="width:8px;height:8px;border-radius:50%;background:var(--red);display:inline-block;margin-left:6px;"></span>Absent
                        <span style="width:8px;height:8px;border-radius:50%;background:var(--brand);display:inline-block;margin-left:6px;"></span>Allocated
                    </div>
                </div>
                <div class="sc-card-body">

                    <div class="sc-toolbar">
                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                            <div class="sc-search">
                                <i class="fa fa-search"></i>
                                <input type="text" placeholder="Search intern, branch…" oninput="filterTable(this.value)">
                            </div>
                            <div class="sc-filters">
                                <button class="sc-filter active" id="f-all"     onclick="setFilter('all')">All</button>
                                <button class="sc-filter"        id="f-present" onclick="setFilter('present')">Present</button>
                                <button class="sc-filter"        id="f-absent"  onclick="setFilter('absent')">Absent</button>
                                <button class="sc-filter"        id="f-free"    onclick="setFilter('free')">Free</button>
                            </div>
                        </div>
                        <button class="sc-save-btn" id="saveBtn" onclick="saveAttendance()">
                            <i class="fa fa-save"></i> Save Attendance
                        </button>
                    </div>

                    <div class="table-responsive">
                    <table id="scrumTable" class="table table-bordered">
                        <thead>
                            <tr>
                                <th style="min-width:200px;">Intern</th>
                                <th style="min-width:120px;text-align:center;">Attendance</th>
                                <th style="min-width:200px;">Task(s)</th>
                                <th style="min-width:110px;text-align:center;">Phase</th>
                                <th style="min-width:100px;text-align:center;">Branch</th>
                                <th style="min-width:90px;text-align:center;">Plan</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($interns)): ?>
                            <tr>
                                <td colspan="6" style="text-align:center;padding:40px;color:var(--ink-3);">
                                    <i class="fa fa-inbox fa-2x" style="display:block;margin-bottom:8px;"></i>
                                    No active interns in Working Phase found.
                                </td>
                            </tr>
                        <?php else:
                            $COLORS = ['#5a56e0','#2e7d4f','#854F0B','#E24B4A','#0c5460'];
                            foreach ($interns as $iid => $intern):
                                $uid     = $intern['userId'];
                                $att     = $attendanceMap[$uid] ?? null;
                                $present = $att && !empty($att['punchInTime']) && $att['punchInTime'] !== '00:00:00';
                                $status  = $present ? 'present' : 'absent';
                                $tasks   = $allocMap[$iid] ?? [];
                                $avBg    = $present ? '#e8f5ee' : '#fdecea';
                                $avCl    = $present ? '#2e7d4f' : '#c0392b';
                                $phase   = $intern['phase'] ?? 'Working Phase';
                                $phCls   = strpos($phase, 'Training') !== false ? 'phase-training' : 'phase-working';
                        ?>
                            <tr data-intern-id="<?php echo htmlspecialchars($iid); ?>"
                                data-att="<?php echo $status; ?>"
                                data-has-task="<?php echo !empty($tasks) ? '1' : '0'; ?>">

                                <!-- Intern -->
                                <td>
                                    <div class="intern-cell">
                                        <div class="intern-avatar" style="background:<?php echo $avBg; ?>;color:<?php echo $avCl; ?>;">
                                            <?php echo strtoupper(substr($intern['fullName'], 0, 1)); ?>
                                        </div>
                                        <div>
                                            <div class="intern-name"><?php echo htmlspecialchars($intern['fullName']); ?></div>
                                            <div class="intern-hours"><i class="fa fa-clock-o" style="font-size:.6rem;margin-right:3px;"></i><?php echo $intern['workStart'] . ' – ' . $intern['workEnd']; ?></div>
                                        </div>
                                    </div>
                                </td>

                                <!-- Attendance -->
                                <td>
                                    <div class="att-wrap">
                                        <div class="att-toggle">
                                            <button class="att-btn present <?php echo $status === 'present' ? 'active' : ''; ?>"
                                                    onclick="toggleAtt('<?php echo htmlspecialchars($iid); ?>', 'present', this)">
                                                <i class="fa fa-check"></i> P
                                            </button>
                                            <div class="att-sep"></div>
                                            <button class="att-btn absent <?php echo $status === 'absent' ? 'active' : ''; ?>"
                                                    onclick="toggleAtt('<?php echo htmlspecialchars($iid); ?>', 'absent', this)">
                                                <i class="fa fa-times"></i> A
                                            </button>
                                        </div>
                                    </div>
                                </td>

                                <!-- Tasks -->
                                <td>
                                    <div class="task-list" id="tasks-<?php echo htmlspecialchars($iid); ?>">
                                        <?php if (empty($tasks)): ?>
                                            <span class="task-none">No task assigned</span>
                                        <?php else: foreach ($tasks as $ti => $task): $col = $COLORS[$ti % count($COLORS)]; ?>
                                            <div class="task-pill">
                                                <span class="task-dot" style="background:<?php echo $col; ?>"></span>
                                                <span class="task-name" title="<?php echo htmlspecialchars($task['projectName']); ?>"><?php echo htmlspecialchars($task['projectName']); ?></span>
                                                <span class="task-time"><?php echo $task['allocStartTime'] . '–' . $task['allocEndTime']; ?></span>
                                            </div>
                                        <?php endforeach; endif; ?>
                                    </div>
                                    <button class="btn-change" onclick="openTaskModal('<?php echo htmlspecialchars($iid); ?>','<?php echo htmlspecialchars(addslashes($intern['fullName'])); ?>')">
                                        <i class="fa fa-exchange" style="font-size:.65rem;margin-right:4px;"></i>Change Task
                                    </button>
                                </td>

                                <!-- Phase -->
                                <td style="text-align:center;">
                                    <span class="phase-pill <?php echo $phCls; ?>"><?php echo htmlspecialchars($phase); ?></span>
                                </td>

                                <!-- Branch -->
                                <td style="text-align:center;font-size:0.8rem;color:var(--ink-2);">
                                    <?php echo htmlspecialchars($intern['branch']); ?>
                                </td>

                              <!-- Plan -->
<td style="text-align:center;">
    <button class="btn-plan" onclick="window.location.href='weeklyPlanOverview.php<?php echo ($intern['branch'] !== 'Not Assigned') ? '?branch=' . urlencode($intern['branch']) : ''; ?>'">
        <i class="fa fa-calendar"></i> Plan
    </button>
</td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                    </div>

                </div>
            </div>

            <?php if ($isResourceHead && !empty($pendingAdjustments)): ?>
            <!-- Pending Approvals -->
            <div class="approval-card">
                <div class="approval-card-header">
                    <h5><i class="fa fa-clock-o" style="margin-right:8px;color:var(--amber);"></i>Pending Task Approval Requests</h5>
                    <span class="pending-badge"><?php echo $pendingApprovalCount; ?> pending</span>
                </div>
                <div class="adj-list">
                <?php foreach ($pendingAdjustments as $adj): ?>
                    <div class="adj-item" id="adj-<?php echo $adj['id']; ?>">
                        <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:4px;">
                            <div>
                                <div class="adj-intern"><?php echo htmlspecialchars($adj['internName'] ?? 'Unknown Intern'); ?></div>
                                <div class="adj-meta">Requested by <strong><?php echo htmlspecialchars($adj['requestedByName'] ?? 'Unknown'); ?></strong> · <?php echo date('d M Y, h:i A', strtotime($adj['createdAt'])); ?></div>
                            </div>
                            <span style="font-size:0.72rem;color:var(--ink-3);">Scrum Host request</span>
                        </div>

                        <div class="adj-compare">
                            <div class="adj-box old">
                                <div class="adj-box-lbl">Previous Task</div>
                                <div class="adj-box-proj"><?php echo htmlspecialchars($adj['oldProjectName'] ?? 'No Task'); ?></div>
                                <?php if (!empty($adj['oldStartTime'])): ?>
                                <div class="adj-box-time"><?php echo $adj['oldStartTime'] . ' – ' . $adj['oldEndTime']; ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="adj-arrow-icon"><i class="fa fa-arrow-right"></i></div>
                            <div class="adj-box new">
                                <div class="adj-box-lbl">Proposed Task</div>
                                <div class="adj-box-proj"><?php echo htmlspecialchars($adj['newProjectName']); ?></div>
                                <div class="adj-box-time"><?php echo $adj['newStartTime'] . ' – ' . $adj['newEndTime']; ?><?php echo !empty($adj['newClientName']) ? ' · ' . htmlspecialchars($adj['newClientName']) : ''; ?></div>
                            </div>
                        </div>

                        <div class="adj-actions">
                            <button class="btn-adj approve" onclick="handleAdj(<?php echo $adj['id']; ?>, 'approve')">
                                <i class="fa fa-check"></i> Approve
                            </button>
                            <button class="btn-adj reject" onclick="handleAdj(<?php echo $adj['id']; ?>, 'reject')">
                                <i class="fa fa-times"></i> Reject
                            </button>
                            <button class="btn-adj modify" onclick="openModifyModal(<?php echo $adj['id']; ?>, '<?php echo htmlspecialchars(addslashes($adj['newProjectName'])); ?>', '<?php echo addslashes($adj['newClientName'] ?? ''); ?>', '<?php echo $adj['newStartTime']; ?>', '<?php echo $adj['newEndTime']; ?>')">
                                <i class="fa fa-edit"></i> Modify & Approve
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

        </div><!-- /.sc-page -->
        </div></div></div></div>
</div>

<!-- ── TASK ADJUSTMENT MODAL ──────────────────────────────────── -->
<div class="sc-overlay" id="taOverlay">
    <div class="sc-modal">
        <div class="sc-modal-head">
            <h5><i class="fa fa-exchange" style="margin-right:8px;"></i>Adjust Task — <span id="ta-intern-name"></span></h5>
            <button class="sc-modal-close" onclick="closeTaskModal()">&#x2715;</button>
        </div>
        <div class="sc-modal-body">
            <div class="current-box">
                <div class="current-box-lbl"><i class="fa fa-briefcase" style="margin-right:4px;"></i>Current Assignment</div>
                <div id="ta-current"></div>
            </div>
            <div id="ta-note" style="margin-bottom:14px;"></div>
            <div style="font-size:0.85rem;font-weight:700;color:var(--ink);margin-bottom:12px;">
                <i class="fa fa-plus-circle" style="color:var(--brand);margin-right:6px;"></i>New Task Assignment
            </div>
            <div id="ta-rows"></div>
            <button class="btn-add-task" onclick="addTaskRow()">
                <i class="fa fa-plus" style="margin-right:6px;"></i>Add Another Task
            </button>
            <div class="ta-warn" id="ta-warn"></div>
        </div>
        <div class="sc-modal-foot">
            <button class="btn-cancel" onclick="closeTaskModal()">Cancel</button>
            <button class="btn-submit" id="ta-submit" onclick="submitAdjustment()">
                <i class="fa fa-check"></i> Submit
            </button>
        </div>
    </div>
</div>

<!-- ── MODIFY MODAL ───────────────────────────────────────────── -->
<div class="sc-overlay" id="modOverlay">
    <div class="sc-modal">
        <div class="sc-modal-head">
            <h5><i class="fa fa-edit" style="margin-right:8px;"></i>Modify & Approve</h5>
            <button class="sc-modal-close" onclick="closeModifyModal()">&#x2715;</button>
        </div>
        <div class="sc-modal-body">
            <p style="font-size:0.82rem;color:var(--ink-3);margin-bottom:16px;">Edit the proposed task before approving.</p>
            <input type="hidden" id="mod-id">
            <div class="mb-3">
                <label class="form-lbl">Project Name <span style="color:var(--red)">*</span></label>
                <input type="text" class="form-ctrl" id="mod-proj" placeholder="Project name">
            </div>
            <div class="mb-3">
                <label class="form-lbl">Client Name</label>
                <input type="text" class="form-ctrl" id="mod-client" placeholder="Client name">
            </div>
            <div class="row">
                <div class="col-6 mb-3">
                    <label class="form-lbl">Start Time <span style="color:var(--red)">*</span></label>
                    <input type="time" class="form-ctrl" id="mod-st">
                </div>
                <div class="col-6 mb-3">
                    <label class="form-lbl">End Time <span style="color:var(--red)">*</span></label>
                    <input type="time" class="form-ctrl" id="mod-et">
                </div>
            </div>
            <div class="ta-warn" id="mod-warn"></div>
        </div>
        <div class="sc-modal-foot">
            <button class="btn-cancel" onclick="closeModifyModal()">Cancel</button>
            <button class="btn-submit" onclick="submitModify()">
                <i class="fa fa-check"></i> Approve with Changes
            </button>
        </div>
    </div>
</div>

<!-- Toast -->
<div class="sc-toast" id="scToast"></div>

<script>
const INTERNS_DATA    = <?php echo json_encode(array_values($interns), JSON_UNESCAPED_UNICODE); ?>;
const ALLOC_DATA      = <?php echo json_encode($allocMap, JSON_UNESCAPED_UNICODE); ?>;
const TODAY           = "<?php echo $todayDate; ?>";
const USER_ID         = "<?php echo htmlspecialchars($userId); ?>";
const IS_RESOURCE_HEAD = <?php echo $isResourceHead ? 'true' : 'false'; ?>;
const TASK_COLORS     = ['#5a56e0','#2e7d4f','#854F0B','#E24B4A','#0c5460'];
</script>
<script src="../vendors/js/vendor.bundle.base.js"></script>
<script src="../js/off-canvas.js"></script>
<script src="../js/hoverable-collapse.js"></script>
<script src="../js/template.js"></script>
<script src="../js/settings.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap4.min.js"></script>
<script>
// ── STATE ────────────────────────────────────────────────────────
var attChanges  = {};
var dtTable     = null;
var _iid        = null;
var _iname      = null;
var rowIdx      = 0;
var curFilter   = 'all';

// ── DATATABLE ────────────────────────────────────────────────────
$(document).ready(function() {
    dtTable = $('#scrumTable').DataTable({
        paging: true, pageLength: 20,
        lengthMenu: [[10,20,50,-1],[10,20,50,'All']],
        ordering: true, order: [[0,'asc']],
        searching: true, info: true, responsive: true, autoWidth: false,
        columnDefs: [{ orderable: false, targets: [1,2,3,4,5] }],
        language: {
            info: 'Showing _START_–_END_ of _TOTAL_ interns',
            infoEmpty: 'No interns found',
            paginate: {
                previous: '<i class="fa fa-chevron-left" style="font-size:.7rem"></i>',
                next:     '<i class="fa fa-chevron-right" style="font-size:.7rem"></i>'
            }
        }
    });
});

function filterTable(v) { if (dtTable) dtTable.search(v).draw(); }

// ── FILTER ───────────────────────────────────────────────────────
function setFilter(type) {
    curFilter = type;
    document.querySelectorAll('.sc-filter').forEach(function(b) { b.classList.remove('active'); });
    var id = { all:'f-all', present:'f-present', absent:'f-absent', free:'f-free' }[type];
    if (id) document.getElementById(id).classList.add('active');
    if (!dtTable) return;
    $.fn.dataTable.ext.search = [];
    if (type !== 'all') {
        $.fn.dataTable.ext.search.push(function(settings, data, idx) {
            var row = dtTable.row(idx).node();
            if (!row) return true;
            var att = row.getAttribute('data-att') || 'absent';
            var haT = row.getAttribute('data-has-task') === '1';
            if (type === 'present') return att === 'present';
            if (type === 'absent')  return att === 'absent';
            if (type === 'free')    return !haT;
            return true;
        });
    }
    dtTable.draw();
}

// ── ATTENDANCE ───────────────────────────────────────────────────
function toggleAtt(iid, status, btn) {
    var row = btn.closest('tr');
    row.querySelectorAll('.att-btn').forEach(function(b) { b.classList.remove('active'); });
    btn.classList.add('active');
    row.setAttribute('data-att', status);
    var av = row.querySelector('.intern-avatar');
    if (av) {
        av.style.background = status === 'present' ? '#e8f5ee' : '#fdecea';
        av.style.color      = status === 'present' ? '#2e7d4f' : '#c0392b';
    }
    attChanges[iid] = status;
    if (curFilter !== 'all') setFilter(curFilter);
    updateStats();
}

function updateStats() {
    var p = 0, a = 0;
    document.querySelectorAll('#scrumTable tbody tr').forEach(function(r) {
        if (r.getAttribute('data-att') === 'present') p++;
        else a++;
    });
    var pe = document.getElementById('stat-present');
    var ae = document.getElementById('stat-absent');
    if (pe) pe.textContent = p;
    if (ae) ae.textContent = a;
}

function saveAttendance() {
    if (!Object.keys(attChanges).length) { showToast('No changes to save.', 'error'); return; }
    var btn = document.getElementById('saveBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving…';
    var fd = new FormData();
    fd.append('date', TODAY); fd.append('markedBy', USER_ID);
    fd.append('changes', JSON.stringify(attChanges));
    fetch('scrumSaveAttendance.php', { method:'POST', body:fd })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (res.status === 'success') { attChanges = {}; showToast('Attendance saved!', 'success'); }
        else showToast(res.message || 'Save failed.', 'error');
        btn.disabled = false; btn.innerHTML = '<i class="fa fa-save"></i> Save Attendance';
    })
    .catch(function(e) {
        showToast('Error: ' + e.message, 'error');
        btn.disabled = false; btn.innerHTML = '<i class="fa fa-save"></i> Save Attendance';
    });
}

// ── TASK MODAL ───────────────────────────────────────────────────
function openTaskModal(iid, iname) {
    _iid = iid; _iname = iname; rowIdx = 0;
    document.getElementById('ta-intern-name').textContent = iname;
    // Current tasks
    var allocs = ALLOC_DATA[iid] || [];
    var curEl = document.getElementById('ta-current');
    if (!allocs.length) {
        curEl.innerHTML = '<span style="font-size:.8rem;color:var(--ink-3);font-style:italic;">No task currently assigned.</span>';
    } else {
        curEl.innerHTML = allocs.map(function(a, i) {
            var c = TASK_COLORS[i % TASK_COLORS.length];
            return '<div style="display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid var(--border);">'
                + '<span style="width:7px;height:7px;border-radius:50%;background:'+c+';flex-shrink:0;display:inline-block;"></span>'
                + '<div><div style="font-size:.82rem;font-weight:600;color:var(--ink);">'+esc(a.projectName)+'</div>'
                + '<div style="font-size:.7rem;color:var(--ink-3);">'+esc(a.clientName||'')+(a.clientName?' · ':'')+a.allocStartTime+'–'+a.allocEndTime+'</div></div></div>';
        }).join('');
    }
    // Note
    var noteEl = document.getElementById('ta-note');
    if (IS_RESOURCE_HEAD) {
        noteEl.innerHTML = '<span class="info-note rh"><i class="fa fa-bolt"></i> Resource Head — changes apply immediately</span>';
    } else {
        noteEl.innerHTML = '<span class="info-note host"><i class="fa fa-info-circle"></i> Changes will be sent to Resource Head for approval</span>';
    }
    document.getElementById('ta-rows').innerHTML = '';
    document.getElementById('ta-warn').classList.remove('show');
    addTaskRow();
    document.getElementById('taOverlay').classList.add('open');
}

function closeTaskModal() { document.getElementById('taOverlay').classList.remove('open'); }

function addTaskRow() {
    var idx = rowIdx++;
    var d = document.createElement('div');
    d.className = 'task-form-row'; d.id = 'tr-' + idx;
    d.innerHTML = '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">'
        + '<span class="task-form-num">Task ' + (idx+1) + '</span>'
        + (idx > 0 ? '<button type="button" class="btn-remove-task" onclick="removeRow('+idx+')"><i class="fa fa-times"></i> Remove</button>' : '')
        + '</div>'
        + '<div class="mb-2"><label class="form-lbl">Project Name <span style="color:var(--red)">*</span></label>'
        + '<input type="text" class="form-ctrl" id="tp-'+idx+'" placeholder="e.g. Website Redesign"></div>'
        + '<div class="mb-2"><label class="form-lbl">Client Name</label>'
        + '<input type="text" class="form-ctrl" id="tc-'+idx+'" placeholder="e.g. Acme Corp"></div>'
        + '<div class="row">'
        + '<div class="col-6"><label class="form-lbl">Start Time <span style="color:var(--red)">*</span></label>'
        + '<input type="time" class="form-ctrl" id="ts-'+idx+'" value="09:00"></div>'
        + '<div class="col-6"><label class="form-lbl">End Time <span style="color:var(--red)">*</span></label>'
        + '<input type="time" class="form-ctrl" id="te-'+idx+'" value="17:00"></div></div>';
    document.getElementById('ta-rows').appendChild(d);
}

function removeRow(idx) { var el = document.getElementById('tr-'+idx); if (el) el.remove(); }

function submitAdjustment() {
    var wEl = document.getElementById('ta-warn');
    wEl.classList.remove('show');
    var tasks = [], valid = true;
    document.getElementById('ta-rows').querySelectorAll('[id^="tr-"]').forEach(function(row) {
        var i = row.id.replace('tr-', '');
        var p = document.getElementById('tp-'+i);
        var c = document.getElementById('tc-'+i);
        var s = document.getElementById('ts-'+i);
        var e = document.getElementById('te-'+i);
        if (!p || !s || !e) return;
        if (!p.value.trim() || toMins(e.value) <= toMins(s.value)) { valid = false; return; }
        tasks.push({ project: p.value.trim(), client: c ? c.value.trim() : '', startTime: s.value, endTime: e.value });
    });
    if (!valid || !tasks.length) { wEl.textContent = 'Fill all required fields. End time must be after start time.'; wEl.classList.add('show'); return; }
    var oldTasks = ALLOC_DATA[_iid] || [];
    var btn = document.getElementById('ta-submit');
    btn.disabled = true; btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Submitting…';
    var fd = new FormData();
    fd.append('internId', _iid); fd.append('requestedBy', USER_ID);
    fd.append('isResourceHead', IS_RESOURCE_HEAD ? '1' : '0');
    fd.append('newTasks', JSON.stringify(tasks));
    fd.append('oldTasks', JSON.stringify(oldTasks));
    fd.append('date', TODAY);
    fetch('scrumTaskAdjust.php', { method:'POST', body:fd })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (res.status === 'success') {
            closeTaskModal();
            showToast(IS_RESOURCE_HEAD ? 'Task adjusted!' : 'Sent for approval.', 'success');
            if (IS_RESOURCE_HEAD && res.allocations) {
                updateTaskCell(_iid, res.allocations);
                ALLOC_DATA[_iid] = res.allocations;
            }
        } else { wEl.textContent = res.message || 'Submission failed.'; wEl.classList.add('show'); }
        btn.disabled = false; btn.innerHTML = '<i class="fa fa-check"></i> Submit';
    })
    .catch(function(e) { wEl.textContent = 'Error: '+e.message; wEl.classList.add('show'); btn.disabled=false; btn.innerHTML='<i class="fa fa-check"></i> Submit'; });
}

function updateTaskCell(iid, allocs) {
    var cell = document.getElementById('tasks-' + iid);
    if (!cell) return;
    if (!allocs || !allocs.length) {
        cell.innerHTML = '<span class="task-none">No task assigned</span>';
        var row = cell.closest('tr'); if (row) row.setAttribute('data-has-task','0');
        return;
    }
    cell.innerHTML = allocs.map(function(a, i) {
        var c = TASK_COLORS[i % TASK_COLORS.length];
        return '<div class="task-pill"><span class="task-dot" style="background:'+c+'"></span>'
            + '<span class="task-name">'+esc(a.projectName||a.project)+'</span>'
            + '<span class="task-time">'+(a.allocStartTime||a.startTime)+'–'+(a.allocEndTime||a.endTime)+'</span></div>';
    }).join('');
    var row = cell.closest('tr'); if (row) row.setAttribute('data-has-task','1');
}

// ── APPROVALS ────────────────────────────────────────────────────
function handleAdj(id, action) {
    if (!id) { showToast('Invalid ID', 'error'); return; }
    var fd = new FormData();
    fd.append('adjId',      id);
    fd.append('action',     action);
    fd.append('approvedBy', USER_ID);

    fetch('scrumAdjApproval.php', { method: 'POST', body: fd })
    .then(function(r) { return r.text(); })
    .then(function(raw) {
        try {
            var res = JSON.parse(raw);
            if (res.status === 'success') {
                var card = document.getElementById('adj-' + id);
                if (card) {
                    card.style.opacity = '0';
                    card.style.transition = 'opacity .3s';
                    setTimeout(function() { card.remove(); }, 300);
                }
                showToast(action === 'approve' ? 'Approved!' : 'Rejected.',
                          action === 'approve' ? 'success' : 'error');
            } else {
                showToast((res.message || 'Action failed.'), 'error');
            }
        } catch(e) {
            showToast('Server error — check console', 'error');
            console.error('Raw response:', raw);
        }
    })
    .catch(function(e) { showToast('Network error: ' + e.message, 'error'); });
}

function openModifyModal(id, proj, client, st, et) {
    document.getElementById('mod-id').value     = id;
    document.getElementById('mod-proj').value   = proj;
    document.getElementById('mod-client').value = client;
    document.getElementById('mod-st').value     = st;
    document.getElementById('mod-et').value     = et;
    document.getElementById('mod-warn').classList.remove('show');
    document.getElementById('modOverlay').classList.add('open');
}
function closeModifyModal() { document.getElementById('modOverlay').classList.remove('open'); }

function submitModify() {
    var id     = document.getElementById('mod-id').value;
    var proj   = document.getElementById('mod-proj').value.trim();
    var client = document.getElementById('mod-client').value.trim();
    var st     = document.getElementById('mod-st').value;
    var et     = document.getElementById('mod-et').value;
    var wEl    = document.getElementById('mod-warn');
    wEl.classList.remove('show');

    if (!proj)                      { wEl.textContent = 'Project name is required.'; wEl.classList.add('show'); return; }
    if (toMins(et) <= toMins(st))   { wEl.textContent = 'End time must be after start time.'; wEl.classList.add('show'); return; }

    var fd = new FormData();
    fd.append('adjId',          id);
    fd.append('action',         'modify_approve');
    fd.append('approvedBy',     USER_ID);
    fd.append('newProjectName', proj);
    fd.append('newClientName',  client);
    fd.append('newStartTime',   st);
    fd.append('newEndTime',     et);

    fetch('scrumAdjApproval.php', { method: 'POST', body: fd })
    .then(function(r) { return r.text(); })
    .then(function(raw) {
        try {
            var res = JSON.parse(raw);
            if (res.status === 'success') {
                closeModifyModal();
                var card = document.getElementById('adj-' + id);
                if (card) {
                    card.style.opacity = '0';
                    card.style.transition = 'opacity .3s';
                    setTimeout(function() { card.remove(); }, 300);
                }
                showToast('Approved with changes!', 'success');
            } else {
                wEl.textContent = res.message || 'Failed.';
                wEl.classList.add('show');
                console.error('Server error:', res);
            }
        } catch(e) {
            wEl.textContent = 'Server error — check console';
            wEl.classList.add('show');
            console.error('Raw response:', raw);
        }
    })
    .catch(function(e) { wEl.textContent = 'Network error: ' + e.message; wEl.classList.add('show'); });
}
// ── UTILS ────────────────────────────────────────────────────────
function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function toMins(t) { if(!t) return 0; var p=t.split(':'); return +p[0]*60 + +p[1]; }
function showToast(msg, type) {
    var t = document.getElementById('scToast');
    t.textContent = msg; t.className = 'sc-toast show ' + (type||'');
    setTimeout(function() { t.classList.remove('show'); }, 3200);
}

document.getElementById('taOverlay').addEventListener('click', function(e) { if (e.target===this) closeTaskModal(); });
document.getElementById('modOverlay').addEventListener('click', function(e) { if (e.target===this) closeModifyModal(); });
</script>
</body>
</html>