<?php
include('../include/db_config.php');
include('../include/notifications.php');

if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}

// ── FAST AJAX ENDPOINT: fetch members of batch ──
$reqAction = $_POST['action'] ?? ($_GET['action'] ?? '');
if ($reqAction === 'get_batch_members') {
    session_write_close();
    header('Content-Type: application/json');
    $batchName = mysqli_real_escape_string($con, trim((string)($_POST['batchName'] ?? ($_GET['batchName'] ?? ''))));

    if ($batchName === '__ALL__') {
        $q = mysqli_query($con,
            "SELECT DISTINCT userId, fullName, display_id FROM (
                SELECT COALESCE(NULLIF(s.userId,''), NULLIF(s.internId,''), s.emp_id) AS userId,
                       s.fullName AS fullName,
                       COALESCE(NULLIF(s.emp_id,''), s.internId) AS display_id
                FROM signup s
                WHERE s.batch_number IS NOT NULL AND TRIM(s.batch_number) != ''
                  AND (TRIM(LOWER(s.status)) = 'active' OR s.status = '1' OR s.status = 1)
                  AND (s.batch_number IN (SELECT batchName FROM batches WHERE status='Active' OR status IS NULL) OR s.batch_number IN (SELECT batchCode FROM batches WHERE status='Active' OR status IS NULL))

                UNION

                SELECT COALESCE(NULLIF(s.userId,''), NULLIF(i.internId,''), i.id) AS userId,
                       COALESCE(NULLIF(i.fullName,''), s.fullName) AS fullName,
                       COALESCE(NULLIF(i.internId,''), s.internId) AS display_id
                FROM interns i
                INNER JOIN signup s ON (TRIM(s.internId) = TRIM(i.internId) OR (s.email IS NOT NULL AND s.email != '' AND LOWER(TRIM(s.email)) = LOWER(TRIM(i.email))))
                WHERE i.batch IS NOT NULL AND TRIM(i.batch) != ''
                  AND (TRIM(LOWER(s.status)) = 'active' OR s.status = '1' OR s.status = 1)
                  AND (i.batch IN (SELECT batchName FROM batches WHERE status='Active' OR status IS NULL) OR i.batch IN (SELECT batchCode FROM batches WHERE status='Active' OR status IS NULL))
             ) combined
             WHERE userId IS NOT NULL AND userId != ''
             ORDER BY fullName ASC");
    } else {
        $bRow = mysqli_fetch_assoc(mysqli_query($con,
            "SELECT batchName, batchCode FROM batches
             WHERE batchName='$batchName' OR batchCode='$batchName' LIMIT 1"));
        $matchName = ($bRow && isset($bRow['batchName'])) ? mysqli_real_escape_string($con, trim((string)$bRow['batchName'])) : $batchName;
        $matchCode = ($bRow && isset($bRow['batchCode'])) ? mysqli_real_escape_string($con, trim((string)$bRow['batchCode'])) : $batchName;
        
        $q = mysqli_query($con,
            "SELECT DISTINCT userId, fullName, display_id FROM (
                SELECT COALESCE(NULLIF(s.userId,''), NULLIF(i.internId,''), i.id) AS userId,
                       COALESCE(NULLIF(i.fullName,''), s.fullName) AS fullName,
                       COALESCE(NULLIF(i.internId,''), s.internId) AS display_id,
                       i.batch AS batch_val
                FROM interns i
                INNER JOIN signup s ON (TRIM(s.internId) = TRIM(i.internId) OR (s.email IS NOT NULL AND s.email != '' AND LOWER(TRIM(s.email)) = LOWER(TRIM(i.email))))
                WHERE i.batch IS NOT NULL AND TRIM(i.batch) != ''
                  AND (TRIM(LOWER(s.status)) = 'active' OR s.status = '1' OR s.status = 1)

                UNION

                SELECT COALESCE(NULLIF(s.userId,''), NULLIF(s.internId,''), s.emp_id) AS userId,
                       s.fullName AS fullName,
                       COALESCE(NULLIF(s.emp_id,''), s.internId) AS display_id,
                       s.batch_number AS batch_val
                FROM signup s
                WHERE s.batch_number IS NOT NULL AND TRIM(s.batch_number) != ''
                  AND (TRIM(LOWER(s.status)) = 'active' OR s.status = '1' OR s.status = 1)
             ) combined
             WHERE (LOWER(TRIM(batch_val)) = LOWER(TRIM('$matchName')) OR LOWER(TRIM(batch_val)) = LOWER(TRIM('$matchCode')))
               AND userId IS NOT NULL AND userId != ''
             ORDER BY fullName ASC");
    }

    $members = [];
    $seen = [];
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $uid = trim((string)($row['userId'] ?? ''));
            if ($uid !== '' && !isset($seen[$uid])) {
                $seen[$uid] = true;
                $members[] = [
                    'userId'   => $uid,
                    'fullName' => trim((string)($row['fullName'] ?? '')),
                    'id'       => trim((string)($row['display_id'] ?? ''))
                ];
            }
        }
    }
    echo json_encode(['success' => true, 'members' => $members]);
    exit;
}

$userId = $_SESSION['userId'];

$userRow = mysqli_fetch_assoc(mysqli_query($con, "SELECT fullName, employment_type, userRole, designation FROM signup WHERE userId='$userId'"));
$userName  = $userRow ? htmlspecialchars($userRow['fullName']) : 'Unknown';
$userRole  = strtolower(trim((string)($userRow['userRole'] ?? '')));
$userDesig = strtolower(trim((string)($userRow['designation'] ?? '')));

$allowedRoles = ['seniorintern', 'senior_intern', 'resourceallocator', 'resource_allocator', 'resourcehead', 'resource_head', 'hr', 'receptionist', 'admin', 'superadmin'];
if (!in_array($userRole, $allowedRoles) && !in_array($userDesig, $allowedRoles)) {
    echo "<script>alert('You do not have permission to create meetings.'); window.location.href = 'meetingHistory.php';</script>";
    exit;
}

// ── Ensure all required columns exist ──
$recurCols = [
    'recurrence_type' => "ALTER TABLE meetings ADD COLUMN recurrence_type VARCHAR(20) DEFAULT NULL",
    'recurrence_days' => "ALTER TABLE meetings ADD COLUMN recurrence_days VARCHAR(50) DEFAULT NULL",
    'recurrence_end'  => "ALTER TABLE meetings ADD COLUMN recurrence_end DATE DEFAULT NULL",
    'time_slots'      => "ALTER TABLE meetings ADD COLUMN time_slots TEXT DEFAULT NULL",
];
foreach ($recurCols as $col => $sql) {
    $chk = mysqli_query($con, "SHOW COLUMNS FROM meetings LIKE '$col'");
    if ($chk && mysqli_num_rows($chk) === 0) mysqli_query($con, $sql);
}

$chkStatus = mysqli_query($con, "SHOW COLUMNS FROM meetings LIKE 'status'");
if ($chkStatus && mysqli_num_rows($chkStatus) === 0) {
    mysqli_query($con, "ALTER TABLE meetings ADD COLUMN status VARCHAR(20) DEFAULT NULL");
}

$chkLink = mysqli_query($con, "SHOW COLUMNS FROM notifications LIKE 'link'");
if ($chkLink && mysqli_num_rows($chkLink) === 0) {
    mysqli_query($con, "ALTER TABLE notifications ADD COLUMN link VARCHAR(500) DEFAULT NULL AFTER message");
}

// ── Ensure occurrences table exists ──
mysqli_query($con, "
    CREATE TABLE IF NOT EXISTS meeting_occurrences (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        meeting_id      INT NOT NULL,
        occurrence_date DATE NOT NULL,
        occurrence_time TIME NOT NULL,
        status          VARCHAR(20) DEFAULT NULL,
        created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_meeting_occurrence (meeting_id, occurrence_date, occurrence_time),
        KEY idx_meeting_id (meeting_id),
        KEY idx_occurrence_date (occurrence_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$batchesQuery = mysqli_query($con, "
    SELECT DISTINCT b.id, b.batchName, b.batchCode, b.status 
    FROM batches b 
    INNER JOIN (
        SELECT batch_number AS bname FROM signup WHERE (TRIM(LOWER(status)) = 'active' OR status = '1' OR status = 1) AND batch_number IS NOT NULL AND TRIM(batch_number) != ''
        UNION
        SELECT i.batch AS bname FROM interns i INNER JOIN signup s ON (TRIM(s.internId)=TRIM(i.internId) OR s.email=i.email) WHERE (TRIM(LOWER(s.status)) = 'active' OR s.status = '1' OR s.status = 1) AND i.batch IS NOT NULL AND TRIM(i.batch) != ''
    ) active_assigned ON (LOWER(TRIM(b.batchName)) = LOWER(TRIM(active_assigned.bname)) OR LOWER(TRIM(b.batchCode)) = LOWER(TRIM(active_assigned.bname)))
    ORDER BY b.batchName ASC
");
$allBatches = [];

$allBatchMembersMap = [];
$allAssignedActiveMembers = [];
$seenAssigned = [];

$batchMembersQ = mysqli_query($con, "
    SELECT DISTINCT batch_val, userId, fullName, display_id FROM (
        SELECT COALESCE(NULLIF(s.userId,''), NULLIF(i.internId,''), i.id) AS userId,
               COALESCE(NULLIF(i.fullName,''), s.fullName) AS fullName,
               COALESCE(NULLIF(i.internId,''), s.internId) AS display_id,
               i.batch AS batch_val
        FROM interns i
        INNER JOIN signup s ON (TRIM(s.internId) = TRIM(i.internId) OR (s.email IS NOT NULL AND s.email != '' AND LOWER(TRIM(s.email)) = LOWER(TRIM(i.email))))
        WHERE i.batch IS NOT NULL AND TRIM(i.batch) != ''
          AND (TRIM(LOWER(s.status)) = 'active' OR s.status = '1' OR s.status = 1)

        UNION

        SELECT COALESCE(NULLIF(s.userId,''), NULLIF(s.internId,''), s.emp_id) AS userId,
               s.fullName AS fullName,
               COALESCE(NULLIF(s.emp_id,''), s.internId) AS display_id,
               s.batch_number AS batch_val
        FROM signup s
        WHERE s.batch_number IS NOT NULL AND TRIM(s.batch_number) != ''
          AND (TRIM(LOWER(s.status)) = 'active' OR s.status = '1' OR s.status = 1)
    ) combined
    WHERE userId IS NOT NULL AND userId != ''
    ORDER BY fullName ASC
");

if ($batchMembersQ) {
    while ($r = mysqli_fetch_assoc($batchMembersQ)) {
        $uid  = trim((string)($r['userId'] ?? ''));
        $bKey = strtolower(trim((string)($r['batch_val'] ?? '')));
        $item = [
            'userId'   => $uid,
            'fullName' => trim((string)($r['fullName'] ?? '')),
            'id'       => trim((string)($r['display_id'] ?? ''))
        ];
        if (!isset($allBatchMembersMap[$bKey])) $allBatchMembersMap[$bKey] = [];
        
        $alreadyInBatch = false;
        foreach ($allBatchMembersMap[$bKey] as $existing) {
            if ($existing['userId'] === $uid) {
                $alreadyInBatch = true;
                break;
            }
        }
        if (!$alreadyInBatch) {
            $allBatchMembersMap[$bKey][] = $item;
        }

        if ($uid !== '' && !isset($seenAssigned[$uid])) {
            $seenAssigned[$uid] = true;
            $allAssignedActiveMembers[] = $item;
        }
    }
}

$batchMapJS = [];
while ($b = mysqli_fetch_assoc($batchesQuery)) {
    $bNameKey = strtolower(trim((string)($b['batchName'] ?? '')));
    $bCodeKey = strtolower(trim((string)($b['batchCode'] ?? '')));
    $members  = $allBatchMembersMap[$bNameKey] ?? ($allBatchMembersMap[$bCodeKey] ?? []);
    $b['memberCount'] = count($members);
    $allBatches[]     = $b;
    $batchMapJS[$b['batchName']] = $members;
    $batchMapJS[$b['batchCode']] = $members;
}

$batchMapJS['__ALL__'] = $allAssignedActiveMembers;

$participantsQuery = mysqli_query($con, "SELECT userId, fullName, emp_id, internId FROM signup ORDER BY fullName ASC");

// ── Helper: send meeting notification to a single user ──
function sendMeetingNotification($con, $toUserId, $agenda, $meetingDate, $meetingTime, $meetingLink, $newMeetingId, $creatorName) {
    $toUserId    = mysqli_real_escape_string($con, $toUserId);
    $safeAgenda  = mysqli_real_escape_string($con, $agenda);
    $safeCreator = mysqli_real_escape_string($con, $creatorName);

    $formattedDate = date('d M Y', strtotime($meetingDate));
    $formattedTime = date('h:i A', strtotime($meetingTime));

    $title   = mysqli_real_escape_string($con, '📅 Meeting: ' . $agenda);
    $message = mysqli_real_escape_string($con,
        "Meeting Invitation by {$creatorName}.\n" .
        "Date: {$formattedDate}\n" .
        "🕐Time: {$formattedTime}"
    );

    $joinUrl = !empty($meetingLink)
        ? $meetingLink
        : '../pages/meetingHistory.php?highlight=' . (int)$newMeetingId;
    $safeLink = mysqli_real_escape_string($con, $joinUrl);

    mysqli_query($con,
        "INSERT INTO notifications (userId, title, message, link, type, status, is_read, created_at)
         VALUES ('$toUserId', '$title', '$message', '$safeLink', 'meeting', 'active', 0, NOW())"
    );

    $userQuery = mysqli_query($con, "SELECT token FROM signup WHERE userId='$toUserId' LIMIT 1");
    if ($userQuery && mysqli_num_rows($userQuery) > 0) {
        $userData = mysqli_fetch_assoc($userQuery);
        if (!empty($userData['token'])) {
            global $accessToken, $projectId;
            sendFCMNotification(
                $userData['token'],
                '📅 Meeting Invitation',
                "You have been invited to a meeting: $agenda",
                $accessToken,
                $projectId
            );
        }
    }
}

// ── Helper: generate occurrences for a recurring meeting ──
function generateOccurrences($con, $meetingId, $startDate, $primaryTime, $recurrenceType, $recurrenceDays, $recurrenceEnd, $timeSlots) {
    // Build list of times to schedule each day (ensure HH:MM format)
    $rawTimes = [];
    if (!empty($timeSlots)) {
        $rawTimes = array_filter(array_map(fn($t) => trim((string)$t), explode(',', (string)$timeSlots)));
    }
    if (empty($rawTimes) && !empty($primaryTime)) {
        $rawTimes = [trim((string)$primaryTime)];
    }

    $times = [];
    foreach ($rawTimes as $t) {
        $t = trim((string)$t);
        if ($t !== '') {
            if (strlen($t) == 5) $t .= ':00';
            $times[] = $t;
        }
    }
    $times = array_values(array_unique($times));
    if (empty($times)) return 0;

    // Parse recurrence days (for weekly / custom)
    $dayMap = [
        'monday'    => 1,
        'tuesday'   => 2,
        'wednesday' => 3,
        'thursday'  => 4,
        'friday'    => 5,
        'saturday'  => 6,
        'sunday'    => 0,
    ];
    $activeDays = [];
    if (!empty($recurrenceDays)) {
        foreach (explode(',', $recurrenceDays) as $d) {
            $d = strtolower(trim((string)$d));
            if (isset($dayMap[$d])) $activeDays[] = $dayMap[$d];
        }
    }

    // If weekly or custom with no activeDays parsed, default to day of startDate
    if (($recurrenceType === 'weekly' || $recurrenceType === 'custom') && empty($activeDays) && !empty($startDate)) {
        $sDT = new DateTime($startDate);
        $activeDays[] = (int)$sDT->format('w');
    }

    // Determine end date (max 1 year ahead if not set)
    $endDate = (!empty($recurrenceEnd) && $recurrenceEnd !== '0000-00-00')
        ? $recurrenceEnd
        : date('Y-m-d', strtotime($startDate . ' +1 year'));

    $current  = new DateTime($startDate);
    $end      = new DateTime($endDate);
    $inserted = 0;
    $maxRows  = 500; // safety cap

    while ($current <= $end && $inserted < $maxRows) {
        $dow     = (int)$current->format('w'); // 0=Sun … 6=Sat
        $dateStr = $current->format('Y-m-d');

        $shouldInsert = false;
        if ($recurrenceType === 'daily' || $recurrenceType === '15days' || $recurrenceType === 'monthly') {
            $shouldInsert = true;
        } elseif ($recurrenceType === 'weekly') {
            $shouldInsert = in_array($dow, $activeDays);
        }

        if ($shouldInsert) {
            foreach ($times as $t) {
                $tEsc = mysqli_real_escape_string($con, $t);
                $mid  = (int)$meetingId;
                mysqli_query($con,
                    "INSERT IGNORE INTO meeting_occurrences
                        (meeting_id, occurrence_date, occurrence_time)
                     VALUES ($mid, '$dateStr', '$tEsc')"
                );
                $inserted++;
            }
        }

        if ($recurrenceType === '15days') {
            $current->modify('+15 days');
        } elseif ($recurrenceType === 'monthly') {
            $current->modify('+1 month');
        } else {
            $current->modify('+1 day');
        }
    }

    return $inserted;
}

$formError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['agenda'])) {
    $agenda          = mysqli_real_escape_string($con, $_POST['agenda']);
    $description     = mysqli_real_escape_string($con, $_POST['description'] ?? '');
    $meetingDate     = mysqli_real_escape_string($con, $_POST['meetingDate']);
    $meetingTime     = mysqli_real_escape_string($con, $_POST['meetingTime']);
    $mode            = mysqli_real_escape_string($con, $_POST['meetingMode']);
    $meetingLink     = mysqli_real_escape_string($con, $_POST['meetingLink'] ?? '');
    $branch          = mysqli_real_escape_string($con, $_POST['branch'] ?? '');
    $participants    = isset($_POST['participants']) && is_array($_POST['participants']) ? array_unique(array_filter(array_map(fn($p) => trim((string)$p), $_POST['participants']))) : [];
    $participantsCsv = mysqli_real_escape_string($con, implode(',', $participants));
    $coHosts         = isset($_POST['co_hosts']) && is_array($_POST['co_hosts']) ? array_unique(array_filter(array_map(fn($c) => trim((string)$c), $_POST['co_hosts']))) : [];
    $coHostsCsv      = mysqli_real_escape_string($con, implode(',', $coHosts));
    $recurrenceType  = mysqli_real_escape_string($con, $_POST['recurrence_type'] ?? '');
    $recurrenceDays  = mysqli_real_escape_string($con, $_POST['recurrence_days'] ?? '');
    $recurrenceEnd   = mysqli_real_escape_string($con, $_POST['recurrence_end'] ?? '');
    $timeSlots       = mysqli_real_escape_string($con, $_POST['time_slots'] ?? '');

    // Ensure co_hosts column exists
    $chkColCohost = mysqli_query($con, "SHOW COLUMNS FROM meetings LIKE 'co_hosts'");
    if ($chkColCohost && mysqli_num_rows($chkColCohost) === 0)
        mysqli_query($con, "ALTER TABLE meetings ADD COLUMN co_hosts TEXT DEFAULT NULL AFTER participants");

    if ($meetingDate < date('Y-m-d')) {
        $formError = "Meeting date cannot be in the past.";
    } elseif ($meetingDate === date('Y-m-d') && $meetingTime < date('H:i', strtotime('-30 minutes'))) {
        $formError = "Meeting time cannot be in the past for today.";
    } else {
        $insertQuery = "INSERT INTO meetings
            (creator_id, agenda, description, meeting_date, meeting_time, mode, meeting_link,
             participants, co_hosts, branch, recurrence_type, recurrence_days, recurrence_end, time_slots)
            VALUES
            ('$userId', '$agenda', '$description', '$meetingDate', '$meetingTime', '$mode', '$meetingLink',
             '$participantsCsv', '$coHostsCsv', '$branch', '$recurrenceType', '$recurrenceDays',
             " . ($recurrenceEnd ? "'$recurrenceEnd'" : "NULL") . ", '$timeSlots')";

        if (mysqli_query($con, $insertQuery)) {
            $newMeetingId = mysqli_insert_id($con);
            $rawMeetingLink = $_POST['meetingLink'] ?? '';

            // ── Generate occurrences if recurring ──
            if (!empty($_POST['recurrence_type'])) {
                generateOccurrences(
                    $con,
                    $newMeetingId,
                    $_POST['meetingDate'],
                    $_POST['meetingTime'],
                    $_POST['recurrence_type'],
                    $_POST['recurrence_days'] ?? '',
                    $_POST['recurrence_end'] ?? '',
                    $_POST['time_slots'] ?? ''
                );
            }

            // ── Send notifications ──
            $allNotifyIds = array_unique(array_merge($participants, $coHosts));
            foreach ($allNotifyIds as $participantId) {
                if (empty($participantId)) continue;
                $isCo = in_array($participantId, $coHosts);
                $agendaTitle = $isCo ? '👑 (Co-Host) ' . $_POST['agenda'] : $_POST['agenda'];
                sendMeetingNotification(
                    $con,
                    $participantId,
                    $agendaTitle,
                    $_POST['meetingDate'],
                    $_POST['meetingTime'],
                    $rawMeetingLink,
                    $newMeetingId,
                    $userName
                );
            }
            echo "<script>window.__meetingScheduled = true; window.__participantsEmpty = " . (empty($participants) ? 'true' : 'false') . ";</script>";
        } else {
            $formError = "Error saving meeting: " . mysqli_error($con);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>SETTribe · Schedule Meeting</title>
    <?php include('../partials/_links.php'); ?>
    <link rel="stylesheet" href="../vendors/select2/select2.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        :root { --primary:#2f55ff; --primary-light:#eef1ff; --border:#e0e4ef; --radius:8px; }
        .required { color:red; }
        .form-control,.btn { border-radius:6px; }
        .section-title { font-size:1.05rem; border-left:4px solid var(--primary); padding-left:12px; margin:24px 0 16px; font-weight:700; color:#222; }
        .radio-pill-group { display:flex; gap:8px; flex-wrap:wrap; }
        .radio-pill { position:relative; }
        .radio-pill input[type="radio"] { position:absolute; opacity:0; width:1px; height:1px; z-index:-1; }
        .radio-pill label { display:inline-flex; align-items:center; gap:5px; padding:6px 14px; border-radius:6px; border:1px solid #ced4da; background:#fff; font-size:.875rem; color:#495057; cursor:pointer; margin:0; height:38px; transition:border-color .15s,background .15s,color .15s; }
        .radio-pill label:hover { border-color:var(--primary); color:var(--primary); }
        .radio-pill input:checked + label { border-color:var(--primary); background:var(--primary-light); color:var(--primary); font-weight:600; }
        .radio-pill.br-pune   input:checked + label { border-color:#0891b2; background:#e0f7fa; color:#0891b2; }
        .radio-pill.br-kinwat input:checked + label { border-color:#d97706; background:#fef3c7; color:#b45309; }
        .radio-pill.br-all    input:checked + label { border-color:#7c3aed; background:#f3e8ff; color:#7c3aed; }
        #meetingLinkRow { overflow:hidden; transition:max-height .2s ease,opacity .2s ease; max-height:100px; opacity:1; }
        #meetingLinkRow.hidden { max-height:0; opacity:0; pointer-events:none; }
        .select2-container--default .select2-selection--multiple { border:1px solid #ced4da !important; border-radius:6px !important; min-height:38px !important; padding:2px 6px !important; font-size:.875rem !important; }
        .select2-container--default .select2-selection--multiple:focus-within { border-color:var(--primary) !important; box-shadow:0 0 0 2px rgba(47,85,255,.13) !important; }
        .select2-container--default .select2-selection--multiple .select2-selection__choice { background:var(--primary) !important; border:none !important; color:#fff !important; border-radius:4px !important; padding:1px 8px !important; font-size:.78rem !important; margin-top:4px !important; }
        .select2-container--default .select2-selection__choice__remove { color:rgba(255,255,255,.8) !important; }
        .select2-container { width:100% !important; }
        .select2-dropdown { border-radius:6px !important; border:1px solid #ced4da !important; box-shadow:0 4px 14px rgba(0,0,0,.10) !important; }
        .method-tabs { border-bottom:1.5px solid #e8eaf0; margin-bottom:20px; display:flex; }
        .method-tab-btn { padding:9px 20px; font-size:.85rem; font-weight:600; color:#9099a8; border:none; background:none; border-bottom:2.5px solid transparent; cursor:pointer; transition:color .15s,border-color .15s; margin-bottom:-1.5px; }
        .method-tab-btn.active { color:var(--primary); border-bottom-color:var(--primary); }
        .method-tab-btn:hover:not(.active) { color:#4a5568; }
        .batch-intro { display:flex; align-items:flex-start; gap:10px; background:#f0f4ff; border:1px solid #d6dfff; border-radius:8px; padding:10px 14px; margin-bottom:18px; font-size:.82rem; color:#3a4a7a; line-height:1.5; }
        .batch-intro i { color:var(--primary); margin-top:2px; flex-shrink:0; }
        .batch-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(155px,1fr)); gap:10px; margin-bottom:18px; }
        .batch-card { border:1.5px solid #e8eaf0; border-radius:12px; padding:14px 14px 12px; cursor:pointer; background:#fff; transition:border-color .18s,background .18s,box-shadow .18s; position:relative; user-select:none; z-index:1; }
        .batch-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; background:linear-gradient(90deg,var(--primary),#7b9bff); opacity:0; transition:opacity .18s; pointer-events:none; z-index:0; border-radius:12px 12px 0 0; }
        .batch-card:hover { border-color:#b8c8ff; background:#f7f9ff; box-shadow:0 2px 12px rgba(47,85,255,.08); }
        .batch-card:hover::before { opacity:1; }
        .batch-card.selected { border-color:var(--primary); background:var(--primary-light); box-shadow:0 2px 14px rgba(47,85,255,.13); }
        .batch-card.selected::before { opacity:1; }
        .batch-card.all-batches { border-style:dashed; border-color:#c5cfe8; }
        .batch-card.all-batches:hover { border-color:#7b9bff; background:#f4f6ff; }
        .batch-card.all-batches.selected { border-color:var(--primary); }
        .batch-card__tick { pointer-events:none; display:none; position:absolute; top:10px; right:10px; width:20px; height:20px; border-radius:50%; background:var(--primary); align-items:center; justify-content:center; box-shadow:0 1px 4px rgba(47,85,255,.35); z-index:3; }
        .batch-card__tick i { font-size:.55rem; color:#fff; }
        .batch-card.selected .batch-card__tick { display:flex; }
        .batch-card__code { position:relative; z-index:2; pointer-events:none; display:inline-flex; align-items:center; gap:4px; font-family:'Courier New',monospace; font-size:.68rem; font-weight:700; letter-spacing:.04em; color:var(--primary); background:var(--primary-light); border-radius:5px; padding:2px 8px; margin-bottom:9px; }
        .batch-card.all-batches .batch-card__code { background:#f0f2f8; color:#6c7a9a; }
        .batch-card__name { position:relative; z-index:2; pointer-events:none; font-size:.84rem; font-weight:600; color:#1a2340; margin-bottom:6px; line-height:1.3; }
        .batch-card__meta { position:relative; z-index:2; pointer-events:none; display:flex; align-items:center; gap:5px; font-size:.72rem; color:#8898b8; }
        .batch-card__metadot { width:4px; height:4px; border-radius:50%; background:#c8d0e8; flex-shrink:0; }
        .batch-card.selected .batch-card__metadot { background:var(--primary); }
        .batch-card__metacount { font-weight:600; color:#4a5a84; }
        .batch-card.selected .batch-card__metacount { color:var(--primary); }
        .participants-preview { border:1.5px solid #e8eaf0; border-radius:12px; background:#f8faff; overflow:hidden; margin-top:4px; }
        .participants-preview__header { display:flex; align-items:center; justify-content:space-between; padding:11px 16px; border-bottom:1px solid #e8eaf0; background:#fff; }
        .participants-preview__left { display:flex; align-items:center; gap:8px; }
        .participants-preview__icon { width:28px; height:28px; border-radius:8px; background:var(--primary-light); display:flex; align-items:center; justify-content:center; }
        .participants-preview__icon i { font-size:.75rem; color:var(--primary); }
        .participants-preview__title { font-size:.82rem; font-weight:600; color:#2d3a5a; }
        .participants-preview__badge { background:var(--primary); color:#fff; font-size:.68rem; font-weight:700; border-radius:20px; padding:2px 9px; min-width:24px; text-align:center; }
        .participants-preview__body { padding:12px 16px; min-height:64px; }
        .participants-preview__pills { display:flex; flex-wrap:wrap; gap:7px; }
        .member-pill { display:inline-flex; align-items:center; gap:6px; background:#fff; border:1px solid #e0e6f0; border-radius:20px; padding:4px 10px 4px 4px; font-size:.78rem; color:#3a4a6a; }
        .member-pill__av { width:22px; height:22px; border-radius:50%; background:var(--primary-light); color:var(--primary); font-size:.65rem; font-weight:700; display:inline-flex; align-items:center; justify-content:center; flex-shrink:0; }
        .member-pill__rm { background:none; border:none; cursor:pointer; color:#b0bccf; font-size:.72rem; padding:0; line-height:1; margin-left:2px; }
        .member-pill__rm:hover { color:#dc3545; }
        .member-pill-more { display:inline-flex; align-items:center; background:#eef1ff; color:var(--primary); border:1px solid #d0d8ff; border-radius:20px; padding:4px 12px; font-size:.75rem; font-weight:600; }
        .preview-empty { display:flex; align-items:center; gap:8px; color:#a0adca; font-size:.82rem; }
        .preview-loading { display:flex; align-items:center; gap:8px; color:#8898b8; font-size:.82rem; }
        .preview-loading i { animation:bspin .75s linear infinite; }
        @keyframes bspin { to { transform:rotate(360deg); } }
        .btn-preview-clear { font-size:.75rem; color:#a0adca; border:1px solid #e0e6f0; border-radius:6px; padding:4px 11px; cursor:pointer; background:#fff; }
        .btn-preview-clear:hover { border-color:#f5a0a0; color:#c62828; background:#fff5f5; }
        .selected-count-badge { background:var(--primary); color:#fff; font-size:.72rem; font-weight:700; border-radius:20px; padding:2px 8px; }
        #selectedMembersList { display:none; }
        #membersGrid { display:flex; flex-wrap:wrap; gap:6px; margin-top:8px; }
        .recurrence-toggle-wrap { display:flex; align-items:center; gap:10px; background:#f8f9ff; border:1.5px solid #e8eaf0; border-radius:10px; padding:12px 16px; margin-bottom:14px; cursor:pointer; user-select:none; }
        .recurrence-toggle-wrap:hover { border-color:#b8c8ff; }
        .recurrence-toggle-wrap.active { border-color:var(--primary); background:var(--primary-light); }
        .toggle-switch { position:relative; width:38px; height:22px; flex-shrink:0; }
        .toggle-switch input { opacity:0; width:0; height:0; position:absolute; }
        .toggle-slider { position:absolute; inset:0; background:#ced4da; border-radius:22px; cursor:pointer; transition:background .2s; }
        .toggle-slider::before { content:''; position:absolute; width:16px; height:16px; left:3px; top:3px; background:#fff; border-radius:50%; transition:transform .2s; box-shadow:0 1px 3px rgba(0,0,0,.2); }
        .toggle-switch input:checked ~ .toggle-slider { background:var(--primary); }
        .toggle-switch input:checked ~ .toggle-slider::before { transform:translateX(16px); }
        .recurrence-body { overflow:hidden; max-height:0; opacity:0; transition:max-height .3s ease,opacity .3s ease; }
        .recurrence-body.open { max-height:600px; opacity:1; }
        .freq-pill-group { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:14px; }
        .freq-pill { position:relative; }
        .freq-pill input[type="radio"] { position:absolute; opacity:0; width:0; height:0; }
        .freq-pill label { display:inline-flex; align-items:center; gap:5px; padding:6px 16px; border-radius:20px; border:1.5px solid #ced4da; background:#fff; font-size:.83rem; color:#495057; cursor:pointer; margin:0; transition:border-color .15s,background .15s,color .15s; }
        .freq-pill label:hover { border-color:var(--primary); color:var(--primary); }
        .freq-pill input:checked + label { border-color:var(--primary); background:var(--primary); color:#fff; font-weight:600; }
        .day-check-group { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:14px; }
        .day-check { position:relative; }
        .day-check input[type="checkbox"] { position:absolute; opacity:0; width:0; height:0; }
        .day-check label { display:inline-flex; align-items:center; justify-content:center; width:38px; height:38px; border-radius:8px; border:1.5px solid #ced4da; background:#fff; font-size:.78rem; font-weight:700; color:#6c757d; cursor:pointer; margin:0; transition:border-color .15s,background .15s,color .15s; }
        .day-check label:hover { border-color:var(--primary); color:var(--primary); }
        .day-check input:checked + label { border-color:var(--primary); background:var(--primary); color:#fff; }
        .time-slot-list { display:flex; flex-direction:column; gap:8px; margin-bottom:10px; }
        .time-slot-item { display:flex; align-items:center; gap:8px; background:#f8f9ff; border:1px solid #e8eaf0; border-radius:8px; padding:8px 12px; }
        .time-slot-item .slot-label { font-size:.78rem; color:#8898b8; min-width:56px; font-weight:600; }
        .time-slot-item .form-control { height:34px; font-size:.83rem; padding:4px 8px; }
        .time-slot-item .select-hour { width:68px !important; }
        .time-slot-item .select-min  { width:68px !important; }
        .time-slot-item .select-ampm { width:68px !important; }
        .time-slot-item .btn-rm-slot { background:none; border:none; color:#dc3545; font-size:.8rem; cursor:pointer; padding:0 4px; }
        .btn-add-slot { display:inline-flex; align-items:center; gap:6px; background:#fff; border:1.5px dashed #b8c8ff; border-radius:8px; padding:7px 14px; color:var(--primary); font-size:.82rem; font-weight:600; cursor:pointer; transition:border-color .15s,background .15s; }
        .btn-add-slot:hover { background:var(--primary-light); border-color:var(--primary); }
        .recur-summary { display:inline-flex; align-items:center; gap:6px; background:#e8efff; border:1px solid #c5d4ff; border-radius:20px; padding:4px 12px; font-size:.78rem; color:#2a3fb0; font-weight:600; margin-top:10px; }
        /* Occurrence preview box */
        .occurrence-preview-box { background:#f0f4ff; border:1.5px solid #c5d4ff; border-radius:10px; padding:14px 18px; margin-top:14px; }
        .occurrence-preview-box h6 { font-size:.82rem; font-weight:700; color:var(--primary); margin-bottom:8px; }
        .occurrence-chips { display:flex; flex-wrap:wrap; gap:6px; }
        .occurrence-chip { background:#fff; border:1px solid #d0d8ff; border-radius:6px; padding:3px 10px; font-size:.75rem; color:#3a4a7a; font-weight:600; }
        .occurrence-chip.more { background:var(--primary-light); color:var(--primary); border-color:var(--primary); }
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
                    <div class="col-12 grid-margin stretch-card">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <h4 class="card-title mb-0"><i class="fas fa-video mr-2"></i> Schedule a Meeting</h4>
                                    <div style="display:flex; gap:8px;">
                                        <a href="meetingDashboard.php" class="btn btn-outline-primary btn-sm" style="border-radius:6px; font-weight:600;"><i class="fas fa-chart-line mr-1"></i> Analytics Dashboard</a>
                                        <a href="meetingHistory.php" class="btn btn-outline-primary btn-sm" style="border-radius:6px; font-weight:600;"><i class="fas fa-history mr-1"></i> Meeting History</a>
                                    </div>
                                </div>
                                <p><strong>Scheduling as:</strong> <span style="color:var(--primary);"><?= $userName ?></span></p>
                                <?php if ($formError): ?>
                                <div class="alert alert-danger"><?= htmlspecialchars($formError) ?></div>
                                <?php endif; ?>

                                <form method="POST" action="" id="meetingForm">

                                    <div class="section-title">Meeting Details</div>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label>Agenda <span class="required">*</span></label>
                                                <input type="text" class="form-control" name="agenda" placeholder="What is this meeting about?" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label>Description</label>
                                                <input type="text" class="form-control" name="description" placeholder="Any additional context or notes…">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="section-title">Schedule</div>
                                    <div class="row">
                                        <div class="col-md-3">
                                            <div class="form-group">
                                                <label>Start Date <span class="required">*</span></label>
                                                <input type="text" class="form-control flatpickr-date" name="meetingDate" id="meetingDateInput" placeholder="Select Meeting Date" required>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-group">
                                                <label>Time <span class="required">*</span> <span id="primaryTimeLabel" style="font-size:.75rem;color:#8898b8;font-weight:400;">(primary)</span></label>
                                                <input type="hidden" name="meetingTime" id="meetingTimeHidden">
                                                <input type="text" class="form-control flatpickr-time" id="meetingTimePicker" placeholder="Select Meeting Time">
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-group">
                                                <label>Branch <span class="required">*</span></label>
                                                <div class="radio-pill-group">
                                                    <?php if ($userRole !== 'receptionist'): ?>
                                                        <div class="radio-pill br-pune">
                                                            <input type="radio" name="branch" id="branchPune" value="Pune" required>
                                                            <label for="branchPune"><i class="fas fa-city"></i> Pune</label>
                                                        </div>
                                                    <?php endif; ?>
                                                    <div class="radio-pill br-kinwat">
                                                        <input type="radio" name="branch" id="branchKinwat" value="Kinwat" <?php if ($userRole === 'receptionist') echo 'checked required'; ?>>
                                                        <label for="branchKinwat"><i class="fas fa-landmark"></i> Kinwat</label>
                                                    </div>
                                                    <?php if ($userRole !== 'receptionist'): ?>
                                                        <div class="radio-pill br-all">
                                                            <input type="radio" name="branch" id="branchAll" value="All">
                                                            <label for="branchAll"><i class="fas fa-globe"></i> All</label>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-group">
                                                <label>Mode</label>
                                                <div class="radio-pill-group">
                                                    <div class="radio-pill">
                                                        <input type="radio" name="meetingMode" id="onlineMode" value="online" checked>
                                                        <label for="onlineMode"><i class="fas fa-wifi"></i> Online</label>
                                                    </div>
                                                    <div class="radio-pill">
                                                        <input type="radio" name="meetingMode" id="offlineMode" value="offline">
                                                        <label for="offlineMode"><i class="fas fa-map-marker-alt"></i> Offline</label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row" id="meetingLinkRow">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label>Meeting Link</label>
                                                <div class="input-group">
                                                    <div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-link" style="font-size:.8rem;"></i></span></div>
                                                    <input type="url" class="form-control" name="meetingLink" placeholder="https://meet.google.com/…">
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="section-title">Recurrence <span style="font-size:.75rem;color:#8898b8;font-weight:400;font-style:italic;">optional</span></div>
                                    <div class="recurrence-toggle-wrap" id="recurToggleWrap" onclick="toggleRecurrence()">
                                        <label class="toggle-switch" onclick="event.stopPropagation()">
                                            <input type="checkbox" id="recurToggle" onchange="toggleRecurrence()">
                                            <span class="toggle-slider"></span>
                                        </label>
                                        <div>
                                            <div style="font-weight:600;font-size:.88rem;color:#2d3a5a;">Make this a recurring meeting</div>
                                            <div style="font-size:.75rem;color:#8898b8;">Separate attendance &amp; MOM will be tracked for each occurrence</div>
                                        </div>
                                        <div id="recurSummaryBadge" style="margin-left:auto;display:none;"></div>
                                    </div>
                                    <div class="recurrence-body" id="recurBody">
                                        <div style="background:#f8f9ff;border:1.5px solid #e8eaf0;border-radius:12px;padding:18px 20px;margin-bottom:16px;">
                                            <div style="margin-bottom:14px;">
                                                <label style="font-size:.82rem;font-weight:700;color:#495057;margin-bottom:8px;display:block;"><i class="fas fa-redo mr-1" style="color:var(--primary);"></i> Frequency</label>
                                                <div class="freq-pill-group">
                                                    <div class="freq-pill"><input type="radio" name="freq_ui" id="freqDaily" value="daily" checked onchange="onFreqChange()"><label for="freqDaily"><i class="fas fa-calendar-day"></i> Daily</label></div>
                                                    <div class="freq-pill"><input type="radio" name="freq_ui" id="freqWeekly" value="weekly" onchange="onFreqChange()"><label for="freqWeekly"><i class="fas fa-calendar-week"></i> Weekly</label></div>
                                                    <div class="freq-pill"><input type="radio" name="freq_ui" id="freq15Days" value="15days" onchange="onFreqChange()"><label for="freq15Days"><i class="fas fa-history"></i> Every 15 Days</label></div>
                                                    <div class="freq-pill"><input type="radio" name="freq_ui" id="freqMonthly" value="monthly" onchange="onFreqChange()"><label for="freqMonthly"><i class="fas fa-calendar-alt"></i> Monthly</label></div>
                                                </div>
                                            </div>
                                            <div id="daysOfWeekRow" style="display:none;margin-bottom:14px;">
                                                <label style="font-size:.82rem;font-weight:700;color:#495057;margin-bottom:8px;display:block;"><i class="fas fa-calendar-alt mr-1" style="color:var(--primary);"></i><span id="daysLabel">On these days</span> <span class="required" style="color:#e53935;">*</span></label>
                                                <div class="day-check-group">
                                                    <?php
                                                    $days=['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
                                                    $dayVals=['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
                                                    foreach($days as $i=>$d): ?>
                                                    <div class="day-check">
                                                        <input type="checkbox" id="day_<?=$dayVals[$i]?>" value="<?=$dayVals[$i]?>" class="day-chk" onchange="updateRecurSummary()">
                                                        <label for="day_<?=$dayVals[$i]?>"><?=$d?></label>
                                                    </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                            <div style="margin-bottom:14px;">
                                                <label style="font-size:.82rem;font-weight:700;color:#495057;margin-bottom:8px;display:block;"><i class="fas fa-clock mr-1" style="color:var(--primary);"></i> Additional Time Slots <span style="font-size:.73rem;color:#8898b8;font-weight:400;">— add more times for this recurring meeting</span></label>
                                                <div class="time-slot-list" id="extraSlotsList"></div>
                                                <button type="button" class="btn-add-slot" onclick="addTimeSlot()"><i class="fas fa-plus-circle"></i> Add another time slot</button>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-4">
                                                    <div class="form-group mb-0">
                                                        <label style="font-size:.82rem;font-weight:700;color:#495057;"><i class="fas fa-calendar-times mr-1" style="color:var(--primary);"></i> Repeat until</label>
                                                        <input type="text" class="form-control flatpickr-date" id="recurEndDate" placeholder="Select end date" onchange="updateRecurSummary()">
                                                        <small class="form-text text-muted">Leave blank to repeat for 1 year</small>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Live occurrence preview -->
                                            <div class="occurrence-preview-box" id="occurrencePreviewBox" style="display:none;">
                                                <h6><i class="fas fa-calendar-check mr-1"></i> Upcoming occurrences preview</h6>
                                                <div class="occurrence-chips" id="occurrenceChips"></div>
                                            </div>
                                        </div>
                                    </div>
                                    <input type="hidden" name="recurrence_type" id="recurTypeHidden" value="">
                                    <input type="hidden" name="recurrence_days" id="recurDaysHidden" value="">
                                    <input type="hidden" name="recurrence_end"  id="recurEndHidden"  value="">
                                    <input type="hidden" name="time_slots"      id="timeSlotsHidden" value="">

                                    <div class="section-title">Participants <span class="required" style="color:#e53935;">*</span></div>
                                    <div class="method-tabs">
                                        <button type="button" class="method-tab-btn active" id="tab-master" onclick="setMethod('master')"><i class="fas fa-search mr-1"></i> Search &amp; Select</button>
                                        <button type="button" class="method-tab-btn" id="tab-batch" onclick="setMethod('batch')"><i class="fas fa-layer-group mr-1"></i> By Batch</button>
                                    </div>

                                    <div id="masterDropdown">
                                        <div class="row">
                                            <div class="col-md-8">
                                                <div class="form-group">
                                                    <select name="participants[]" id="participantSelect" class="form-control" multiple="multiple">
                                                        <?php while ($row = mysqli_fetch_assoc($participantsQuery)): ?>
                                                        <option value="<?=$row['userId']?>"><?=htmlspecialchars($row['fullName'])?> (<?=$row['emp_id']?:$row['internId']?>)</option>
                                                        <?php endwhile; ?>
                                                    </select>
                                                    <small class="form-text text-muted">Type a name or ID to search.</small>
                                                </div>
                                                <div id="selectedMembersList">
                                                    <label style="font-size:.82rem;font-weight:600;color:#495057;">Selected Members <span id="selectedCount" class="selected-count-badge ml-1">0</span></label>
                                                    <div id="membersGrid"></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Co-Host Selection -->
                                    <div class="section-title mt-4">Meeting Co-Hosts <small class="text-muted font-weight-normal">(Optional)</small></div>
                                    <div class="row">
                                        <div class="col-md-8">
                                            <div class="form-group mb-3">
                                                <label style="font-size:.85rem; font-weight:600; color:#495057;">
                                                    <i class="fas fa-user-shield text-primary mr-1"></i> Select Co-Host(s)
                                                    <small class="text-muted font-weight-normal">— Co-hosts have full permissions to edit, reschedule, cancel &amp; manage MOM/attendance.</small>
                                                </label>
                                                <select name="co_hosts[]" id="coHostSelect" class="form-control" multiple="multiple">
                                                    <?php
                                                    mysqli_data_seek($participantsQuery, 0);
                                                    while ($row = mysqli_fetch_assoc($participantsQuery)):
                                                    ?>
                                                    <option value="<?=$row['userId']?>"><?=htmlspecialchars($row['fullName'])?> (<?=$row['emp_id']?:$row['internId']?>)</option>
                                                    <?php endwhile; ?>
                                                </select>
                                                <small class="form-text text-muted">Co-hosts receive full host administrative control for this meeting.</small>
                                            </div>
                                        </div>
                                    </div>

                                    <div id="batchDropdown" style="display:none;">
                                        <div class="batch-intro">
                                            <i class="fas fa-info-circle"></i>
                                            <span>Select one or more batches — all interns in those batches will be added as participants. Use <strong>All Batches</strong> to include every intern at once.</span>
                                        </div>
                                        <div class="batch-grid" id="batchCardGrid">
                                            <div class="batch-card all-batches" id="card-ALL" data-batchname="__ALL__">
                                                <div class="batch-card__tick"><i class="fas fa-check"></i></div>
                                                <div class="batch-card__code"><i class="fas fa-layer-group" style="font-size:.6rem;"></i> ALL</div>
                                                <div class="batch-card__name">All Batches</div>
                                                <div class="batch-card__meta"><div class="batch-card__metadot"></div><span>Include everyone</span></div>
                                            </div>
                                            <?php foreach ($allBatches as $b): ?>
                                            <div class="batch-card"
                                                 id="card-<?=htmlspecialchars($b['id'])?>"
                                                 data-batchname="<?=htmlspecialchars($b['batchName'])?>">
                                                <div class="batch-card__tick"><i class="fas fa-check"></i></div>
                                                <div class="batch-card__code"><?=htmlspecialchars($b['batchCode'])?></div>
                                                <div class="batch-card__name"><?=htmlspecialchars($b['batchName'])?></div>
                                                <div class="batch-card__meta">
                                                    <div class="batch-card__metadot"></div>
                                                    <span class="batch-card__metacount" id="batchcount-<?=htmlspecialchars($b['id'])?>"><?=$b['memberCount']?></span>
                                                    <span>interns</span>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>

                                        <div class="participants-preview">
                                            <div class="participants-preview__header">
                                                <div class="participants-preview__left">
                                                    <div class="participants-preview__icon"><i class="fas fa-users"></i></div>
                                                    <span class="participants-preview__title">Selected participants</span>
                                                    <span class="participants-preview__badge" id="batchSelectedCount">0</span>
                                                </div>
                                                <button type="button" class="btn-preview-clear" id="btnClearBatch"><i class="fas fa-times mr-1" style="font-size:.65rem;"></i> Clear all</button>
                                            </div>
                                            <div class="participants-preview__body">
                                                <div id="batchMemberPills">
                                                    <div class="preview-empty"><i class="fas fa-hand-pointer"></i>&nbsp;Click a batch above to add participants</div>
                                                </div>
                                            </div>
                                        </div>
                                        <div id="batchHiddenInputs"></div>
                                    </div>

                                    <div class="row mt-3">
                                        <div class="col-md-12">
                                            <button type="submit" class="btn btn-primary"><i class="fas fa-calendar-check mr-1"></i> Schedule Meeting</button>
                                            <a href="meetingHistory.php" class="btn btn-light ml-2">Cancel</a>
                                        </div>
                                    </div>

                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script src="../vendors/js/vendor.bundle.base.js"></script>
<script src="../vendors/select2/select2.min.js"></script>
<script src="../js/off-canvas.js"></script>
<script src="../js/hoverable-collapse.js"></script>
<script src="../js/template.js"></script>
<script src="../js/settings.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

<script>
/* ── Success ── */
<?php if (isset($_POST['agenda']) && !$formError): ?>
window.addEventListener('DOMContentLoaded', function () {
    if (window.__meetingScheduled) {
        var msg = window.__participantsEmpty ? 'Meeting created successfully, but no participants were selected.' : 'Your meeting has been scheduled and participants have been notified.';
        Swal.fire({
            icon: 'success',
            title: 'Meeting Scheduled!',
            text: msg,
            confirmButtonText: 'OK',
            confirmButtonColor: '#2f55ff'
        }).then(function(){ window.location.href = 'meetingHistory.php'; });
    }
});
<?php endif; ?>

document.addEventListener('DOMContentLoaded', function () {
    var today = new Date().toISOString().split('T')[0];
    var re = document.getElementById('recurEndDate');
    if (re) re._flatpickr && (re._flatpickr.set('minDate', today));

    var grid = document.getElementById('batchCardGrid');
    if (grid) {
        grid.addEventListener('click', function(e){
            var card = e.target;
            while (card && card !== grid) {
                if (card.classList && card.classList.contains('batch-card')) {
                    var bname = card.getAttribute('data-batchname');
                    if (bname) toggleBatch(bname, card);
                    return;
                }
                card = card.parentNode;
            }
        });
    }

    var clearBtn = document.getElementById('btnClearBatch');
    if (clearBtn) clearBtn.addEventListener('click', clearBatchSelection);
});

flatpickr(".flatpickr-date", {
    dateFormat: "Y-m-d",
    minDate: "today",
    allowInput: true,
    onChange: function(selectedDates, dateStr, instance) {
        updateRecurSummary();
    }
});

flatpickr(".flatpickr-time", {
    enableTime: true,
    noCalendar: true,
    dateFormat: "h:i K",
    time_24hr: false,
    minuteIncrement: 1,
    allowInput: true,
    onChange: syncTimePickerHidden,
    onValueUpdate: syncTimePickerHidden,
    onClose: syncTimePickerHidden
});

document.addEventListener('DOMContentLoaded', function() {
    var pickerEl = document.getElementById("meetingTimePicker");
    if (pickerEl) {
        pickerEl.addEventListener('input', syncTimePickerHidden);
        pickerEl.addEventListener('blur', syncTimePickerHidden);
    }
});

function syncTimePickerHidden() {
    var pickerEl = document.getElementById("meetingTimePicker");
    if (!pickerEl) return;
    var val = pickerEl.value.trim();
    var tp  = pickerEl._flatpickr;

    if (tp && tp.selectedDates && tp.selectedDates.length > 0) {
        var hours   = String(tp.selectedDates[0].getHours()).padStart(2, '0');
        var minutes = String(tp.selectedDates[0].getMinutes()).padStart(2, '0');
        document.getElementById("meetingTimeHidden").value = hours + ":" + minutes;
    } else if (val) {
        var match = val.match(/^(\d{1,2}):(\d{2})\s*(AM|PM|am|pm)?$/i);
        if (match) {
            var h  = parseInt(match[1], 10);
            var m  = parseInt(match[2], 10);
            var ap = match[3] ? match[3].toUpperCase() : null;
            if (ap === 'PM' && h < 12) h += 12;
            if (ap === 'AM' && h === 12) h = 0;
            document.getElementById("meetingTimeHidden").value = 
                String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0');
        }
    } else {
        document.getElementById("meetingTimeHidden").value = "";
    }
    updateRecurSummary();
}

document.getElementById('meetingForm').addEventListener('submit', function(e){
    var branchSelected = document.querySelector('input[name="branch"]:checked');
    if (!branchSelected) {
        e.preventDefault();
        Swal.fire({icon:'warning',title:'Branch Required',text:'Please select a branch.',confirmButtonColor:'#2f55ff'});
        return;
    }
    var meetingTime = document.getElementById('meetingTimeHidden').value;
    if (!meetingTime) {
        e.preventDefault();
        Swal.fire({icon:'warning',title:'Time Required',text:'Please select a valid time.',confirmButtonColor:'#2f55ff'});
        return;
    }

    // Ensure participants are selected
    if (currentMethod === 'batch') {
        rebuildBatchHiddenInputs();
        if (allSelectedUsers.size === 0) {
            e.preventDefault();
            Swal.fire({icon:'warning',title:'Participants Required',text:'Please select at least one batch.',confirmButtonColor:'#2f55ff'});
            return;
        }
    } else {
        var pSelect = document.getElementById('participantSelect');
        var pValues = pSelect ? $(pSelect).val() : null;
        if (!pValues || pValues.length === 0) {
            e.preventDefault();
            Swal.fire({icon:'warning',title:'Participants Required',text:'Please select at least one participant from the list.',confirmButtonColor:'#2f55ff'});
            return;
        }
    }
    
    // Check if date/time is in the past
    var meetingDate = document.getElementById('meetingDateInput').value;
    var todayStr = new Date().getFullYear() + '-' + 
                   String(new Date().getMonth() + 1).padStart(2, '0') + '-' + 
                   String(new Date().getDate()).padStart(2, '0');
    if (meetingDate < todayStr) {
        e.preventDefault();
        Swal.fire({icon:'warning',title:'Invalid Date',text:'Meeting date cannot be in the past.',confirmButtonColor:'#2f55ff'});
        return;
    }
    if (meetingDate === todayStr) {
        var now = new Date();
        // Allow a 30-minute grace window for meetings starting around current time
        var graceTime = new Date(now.getTime() - 30 * 60 * 1000);
        var graceHours = String(graceTime.getHours()).padStart(2, '0');
        var graceMins = String(graceTime.getMinutes()).padStart(2, '0');
        var graceTimeStr = graceHours + ':' + graceMins;
        if (meetingTime < graceTimeStr) {
            e.preventDefault();
            Swal.fire({icon:'warning',title:'Invalid Time',text:'Meeting time cannot be in the past for today.',confirmButtonColor:'#2f55ff'});
            return;
        }
    }
    if (document.getElementById('recurToggle').checked) {
        var freq = document.querySelector('input[name="freq_ui"]:checked').value;
        if (freq === 'weekly') {
            var selectedDays = Array.from(document.querySelectorAll('.day-chk:checked')).map(function(c){ return c.value; });
            if (selectedDays.length === 0) {
                e.preventDefault();
                Swal.fire({icon:'warning',title:'Select Days',text:'Please select at least one day.',confirmButtonColor:'#2f55ff'});
                return;
            }
            var sDateStr = document.getElementById('meetingDateInput').value;
            if (sDateStr) {
                var sDate = new Date(sDateStr + 'T00:00:00');
                var dayMap = {sunday:0, monday:1, tuesday:2, wednesday:3, thursday:4, friday:5, saturday:6};
                var selDayNums = selectedDays.map(function(d){ return dayMap[d.toLowerCase()]; });

                if (!selDayNums.includes(sDate.getDay())) {
                    e.preventDefault();
                    Swal.fire({icon:'error',title:'Invalid Day',text:'For a weekly meeting, the selected day must match the day of the week of the Start Date.',confirmButtonColor:'#2f55ff'});
                    return;
                }
            }
        }
        serializeRecurrence();
    }
    var masterCount = $('#participantSelect').select2('data').length;
    var batchCount = allSelectedUsers.size;
    if (masterCount === 0 && batchCount === 0) {
        e.preventDefault();
        Swal.fire({icon:'error',title:'No Participants',text:'Please select at least one participant to schedule the meeting.',confirmButtonColor:'#2f55ff'});
        return;
    }
});

var onlineRadio  = document.getElementById('onlineMode');
var offlineRadio = document.getElementById('offlineMode');
var linkRow      = document.getElementById('meetingLinkRow');
function toggleLink(){ linkRow.classList.toggle('hidden', !onlineRadio.checked); }
onlineRadio.addEventListener('change', toggleLink);
offlineRadio.addEventListener('change', toggleLink);
toggleLink();

$(document).ready(function(){
    $('#participantSelect').select2({placeholder:'Search by name or ID…',width:'100%',closeOnSelect:false,allowClear:true});
    $('#coHostSelect').select2({placeholder:'-- Search & Select Co-Host(s) --',width:'100%',closeOnSelect:false,allowClear:true});
    $('#participantSelect').on('change',function(){
        var sel = $(this).select2('data');
        var list = document.getElementById('selectedMembersList');
        var grid = document.getElementById('membersGrid');
        var cnt  = document.getElementById('selectedCount');
        grid.innerHTML = '';
        if (!sel.length){ list.style.display='none'; return; }
        list.style.display = 'block';
        cnt.textContent = sel.length;
        sel.forEach(function(item){
            var chip = document.createElement('div');
            chip.style.cssText = 'display:inline-flex;align-items:center;gap:6px;background:#f4f5f7;border:1px solid #dee2e6;border-radius:6px;padding:4px 10px;font-size:.8rem;color:#495057;';
            var av = document.createElement('span');
            av.style.cssText = 'width:20px;height:20px;border-radius:50%;background:var(--primary);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:.65rem;font-weight:700;flex-shrink:0;';
            av.textContent = (item && item.text ? String(item.text).trim().charAt(0).toUpperCase() : '');
            var nm = document.createElement('span');
            nm.textContent = (item && item.text ? String(item.text).replace(/\s*\(.*?\)\s*$/,'').trim() : '');
            chip.appendChild(av); chip.appendChild(nm);
        });
    });
});

window.__BATCH_MEMBERS_MAP__ = <?= json_encode($batchMapJS) ?>;

var currentMethod = 'master';
function setMethod(method){
    currentMethod = method;
    document.getElementById('masterDropdown').style.display = method==='master' ? 'block' : 'none';
    document.getElementById('batchDropdown').style.display  = method==='batch'  ? 'block' : 'none';
    document.getElementById('tab-master').classList.toggle('active', method==='master');
    document.getElementById('tab-batch').classList.toggle('active',  method==='batch');
}

var selectedBatches  = new Set();
var allSelectedUsers = new Map();

function getBatchMembersLocal(batchName) {
    if (window.__BATCH_MEMBERS_MAP__) {
        if (window.__BATCH_MEMBERS_MAP__[batchName]) return window.__BATCH_MEMBERS_MAP__[batchName];
        var lower = String(batchName || '').toLowerCase().trim();
        for (var k in window.__BATCH_MEMBERS_MAP__) {
            if (String(k || '').toLowerCase().trim() === lower) return window.__BATCH_MEMBERS_MAP__[k];
        }
    }
    return [];
}

function toggleBatch(batchName, cardEl){
    var isAll = (batchName === '__ALL__');
    if (isAll) {
        if (selectedBatches.has('__ALL__')) {
            selectedBatches.clear();
            allSelectedUsers.clear();
            cardEl.classList.remove('selected');
        } else {
            document.querySelectorAll('.batch-card').forEach(function(c){ c.classList.remove('selected'); });
            selectedBatches.clear();
            allSelectedUsers.clear();
            selectedBatches.add('__ALL__');
            cardEl.classList.add('selected');
            var members = getBatchMembersLocal('__ALL__');
            members.forEach(function(m){
                var key = String((m && (m.userId || m.id)) || '');
                if (key !== '') allSelectedUsers.set(key, m);
            });
        }
        renderBatchPills();
        rebuildBatchHiddenInputs();
        return;
    }

    if (selectedBatches.has('__ALL__')) {
        selectedBatches.delete('__ALL__');
        var ac = document.getElementById('card-ALL');
        if (ac) ac.classList.remove('selected');
        allSelectedUsers.clear();
    }

    if (selectedBatches.has(batchName)) {
        selectedBatches.delete(batchName);
        cardEl.classList.remove('selected');
        var members = getBatchMembersLocal(batchName);
        members.forEach(function(m){
            var key = String((m && (m.userId || m.id)) || '');
            var inOther = false;
            selectedBatches.forEach(function(bn){
                var otherMembers = getBatchMembersLocal(bn);
                if (otherMembers.some(function(x){ return String(x.userId || x.id) === key; })) inOther = true;
            });
            if (!inOther && key !== '') allSelectedUsers.delete(key);
        });
    } else {
        var members = getBatchMembersLocal(batchName);
        if (!members || members.length === 0) {
            Swal.fire({icon:'info', title:'No Interns Found', text:'No allocated interns were found in ' + batchName + '.', confirmButtonColor:'#2f55ff'});
            return;
        }
        selectedBatches.add(batchName);
        cardEl.classList.add('selected');
        members.forEach(function(m){
            var key = String((m && (m.userId || m.id)) || '');
            if (key !== '') allSelectedUsers.set(key, m);
        });
    }
    renderBatchPills();
    rebuildBatchHiddenInputs();
}

function showBatchLoading(){
    var container = document.getElementById('batchMemberPills');
    if (container) container.innerHTML = '<div class="preview-loading"><i class="fas fa-spinner fa-spin"></i>&nbsp;Loading members…</div>';
}

function renderBatchPills(){
    var container = document.getElementById('batchMemberPills');
    var badge     = document.getElementById('batchSelectedCount');
    if (badge) badge.textContent = allSelectedUsers.size;
    if (!container) return;
    if (allSelectedUsers.size === 0){
        container.innerHTML = '<div class="preview-empty"><i class="fas fa-hand-pointer"></i>&nbsp;Click a batch above to add participants</div>';
        return;
    }
    var wrap = document.createElement('div');
    wrap.className = 'participants-preview__pills';
    var shown = 0;
    var MAX = 50;
    allSelectedUsers.forEach(function(m, uid){
        if (shown >= MAX) return;
        shown++;
        var pill = document.createElement('div');
        pill.className = 'member-pill';
        var displayName = (m && m.fullName && String(m.fullName).trim() !== '') ? String(m.fullName).trim() : ((m && m.id) || 'Member ' + uid);
        var av = document.createElement('div');
        av.className = 'member-pill__av';
        av.textContent = displayName.charAt(0).toUpperCase();
        var nameSpan = document.createElement('span');
        nameSpan.textContent = displayName;
        var idSpan = document.createElement('span');
        idSpan.style.cssText = 'color:#a0b0cc;font-size:.68rem;margin-left:4px;';
        idSpan.textContent = (m.id && m.id !== displayName) ? '(' + m.id + ')' : '';
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'member-pill__rm';
        btn.innerHTML = '&#x2715;';
        btn.setAttribute('data-uid', uid);
        btn.addEventListener('click', function(e){
            e.stopPropagation();
            allSelectedUsers.delete(this.getAttribute('data-uid'));
            renderBatchPills();
            rebuildBatchHiddenInputs();
        });
        pill.appendChild(av);
        pill.appendChild(nameSpan);
        if (idSpan.textContent) pill.appendChild(idSpan);
        pill.appendChild(btn);
        wrap.appendChild(pill);
    });
    if (allSelectedUsers.size > MAX){
        var more = document.createElement('div');
        more.className = 'member-pill-more';
        more.textContent = '+' + (allSelectedUsers.size - MAX) + ' more';
        wrap.appendChild(more);
    }
    container.innerHTML = '';
    container.appendChild(wrap);
}

function clearBatchSelection(){
    selectedBatches.clear();
    allSelectedUsers.clear();
    document.querySelectorAll('.batch-card').forEach(function(c){ c.classList.remove('selected'); });
    renderBatchPills();
    rebuildBatchHiddenInputs();
}

function rebuildBatchHiddenInputs(){
    var c = document.getElementById('batchHiddenInputs');
    if (!c) return;
    c.innerHTML = '';
    allSelectedUsers.forEach(function(m, uid){
        var inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = 'participants[]';
        inp.value = uid;
        c.appendChild(inp);
    });
}

/* ── Recurrence ── */
var recurOpen = false; var extraSlotCount = 0;
function toggleRecurrence(){
    var chk  = document.getElementById('recurToggle');
    var body = document.getElementById('recurBody');
    var wrap = document.getElementById('recurToggleWrap');
    recurOpen = chk.checked;
    body.classList.toggle('open', recurOpen);
    wrap.classList.toggle('active', recurOpen);
    if (!recurOpen) {
        document.getElementById('recurTypeHidden').value = '';
        document.getElementById('recurDaysHidden').value = '';
        document.getElementById('recurEndHidden').value  = '';
        document.getElementById('timeSlotsHidden').value = '';
        document.getElementById('recurSummaryBadge').style.display = 'none';
        document.getElementById('primaryTimeLabel').style.display  = 'none';
        document.getElementById('occurrencePreviewBox').style.display = 'none';
    } else {
        document.getElementById('primaryTimeLabel').style.display = '';
        updateRecurSummary();
    }
}

function onFreqChange(){
    var freq = document.querySelector('input[name="freq_ui"]:checked').value;
    var dRow = document.getElementById('daysOfWeekRow');
    if (freq === 'weekly') {
        dRow.style.display = 'block';
        document.getElementById('daysLabel').textContent = 'On this day of the week';
    } else {
        dRow.style.display = 'none';
    }
    updateRecurSummary();
}

function buildOccurrencePreview(){
    if (!recurOpen) return;
    var startInp = document.getElementById('meetingDateInput');
    var endInp   = document.getElementById('recurEndDate');
    var startStr = startInp ? startInp.value : '';
    var endStr   = endInp   ? endInp.value   : '';
    if (!startStr) return;

    var freq = document.querySelector('input[name="freq_ui"]:checked').value;
    var activeDays = [];
    document.querySelectorAll('.day-chk:checked').forEach(function(c){
        var v = c.value.toLowerCase();
        if (DAY_MAP[v] !== undefined) activeDays.push(DAY_MAP[v]);
    });

    var start   = new Date(startStr + 'T00:00:00');
    var endDate = endStr ? new Date(endStr + 'T00:00:00') : new Date(start.getTime() + 365 * 24 * 60 * 60 * 1000);
    var MAX_SHOW = 14;
    var dates    = [];
    var cur      = new Date(start);

    while (cur <= endDate && dates.length < MAX_SHOW) {
        var dow = cur.getDay();
        if (freq === 'daily') {
            dates.push(new Date(cur));
            cur.setDate(cur.getDate() + 1);
        } else if (freq === 'weekly') {
            if (activeDays.indexOf(dow) !== -1) dates.push(new Date(cur));
            cur.setDate(cur.getDate() + 1);
        } else if (freq === '15days') {
            dates.push(new Date(cur));
            cur.setDate(cur.getDate() + 15);
        } else if (freq === 'monthly') {
            dates.push(new Date(cur));
            cur.setMonth(cur.getMonth() + 1);
        }
    }

    var box   = document.getElementById('occurrencePreviewBox');
    var chips = document.getElementById('occurrenceChips');
    if (!dates.length) { box.style.display = 'none'; return; }
    box.style.display = 'block';
    chips.innerHTML   = '';
    dates.forEach(function(d){
        var chip = document.createElement('span');
        chip.className = 'occurrence-chip';
        chip.textContent = DAYS_SHORT[d.getDay()] + ' ' + d.getDate() + ' ' + d.toLocaleString('default',{month:'short'});
        chips.appendChild(chip);
    });
    if (endStr) {
        var totalCount = countAllOccurrences(start, new Date(endStr + 'T00:00:00'), freq, activeDays);
        if (totalCount > MAX_SHOW) {
            var more = document.createElement('span');
            more.className = 'occurrence-chip more';
            more.textContent = '+' + (totalCount - MAX_SHOW) + ' more · ' + totalCount + ' total';
            chips.appendChild(more);
        }
    }
}

function countAllOccurrences(start, end, freq, activeDays){
    var count = 0; var cur = new Date(start); var MAX = 1000;
    while (cur <= end && count < MAX) {
        if (freq === 'daily') {
            count++;
            cur.setDate(cur.getDate() + 1);
        } else if (freq === 'weekly') {
            if (activeDays.indexOf(cur.getDay()) !== -1) count++;
            cur.setDate(cur.getDate() + 1);
        } else if (freq === '15days') {
            count++;
            cur.setDate(cur.getDate() + 15);
        } else if (freq === 'monthly') {
            count++;
            cur.setMonth(cur.getMonth() + 1);
        }
    }
    return count;
}

function updateRecurSummary(){
    if (!recurOpen) return;
    serializeRecurrence();
    var freq  = document.querySelector('input[name="freq_ui"]:checked').value;
    var days  = [];
    document.querySelectorAll('.day-chk:checked').forEach(function(c){ days.push(c.value.charAt(0).toUpperCase()+c.value.slice(1,3)); });
    var endDate   = document.getElementById('recurEndDate').value;
    var slotCount = document.querySelectorAll('.time-slot-item').length + 1;
    var parts = [];
    if (freq==='daily')        parts.push('Every day');
    else if (freq==='weekly')  parts.push('Weekly'+(days.length?' on '+days.join(', '):''));
    else if (freq==='15days')  parts.push('Every 15 days');
    else if (freq==='monthly') parts.push('Monthly');
    if (slotCount > 1)  parts.push(slotCount+' time slots');
    if (endDate)        parts.push('until '+endDate);
    var badge = document.getElementById('recurSummaryBadge');
    if (parts.length) {
        badge.style.display = 'inline-flex';
        badge.innerHTML = '<span class="recur-summary"><i class="fas fa-redo" style="font-size:.65rem;"></i> '+parts.join(' · ')+'</span>';
    } else {
        badge.style.display = 'none';
    }
    buildOccurrencePreview();
}

function addTimeSlot(){
    extraSlotCount++;
    var id   = 'slot_' + extraSlotCount;
    var list = document.getElementById('extraSlotsList');
    var ho   = '<option value="">hh</option>';
    for (var h=1; h<=12; h++) ho += '<option value="'+h+'">'+String(h).padStart(2,'0')+'</option>';
    var mo = '<option value="">mm</option>';
    for (var m=0; m<60; m++) mo += '<option value="'+m+'">'+String(m).padStart(2,'0')+'</option>';
    var item = document.createElement('div');
    item.className = 'time-slot-item'; item.id = id;
    item.innerHTML = '<span class="slot-label">Slot '+extraSlotCount+'</span>'
        +'<select class="form-control select-hour slot-h" onchange="updateTimeSlotsHidden()">'+ho+'</select>'
        +'<span class="time-sep" style="font-size:.9rem;">:</span>'
        +'<select class="form-control select-min slot-m" onchange="updateTimeSlotsHidden()">'+mo+'</select>'
        +'<select class="form-control select-ampm slot-ap" onchange="updateTimeSlotsHidden()"><option value="AM">AM</option><option value="PM">PM</option></select>'
        +'<button type="button" class="btn-rm-slot" onclick="removeSlot(\''+id+'\')" title="Remove"><i class="fas fa-times-circle"></i></button>';
    list.appendChild(item);
    updateTimeSlotsHidden();
}

function removeSlot(id){ var el=document.getElementById(id); if(el)el.remove(); updateTimeSlotsHidden(); updateRecurSummary(); }

function updateTimeSlotsHidden(){
    var slots = [];
    var primaryTime = document.getElementById('meetingTimeHidden').value;
    if (primaryTime) slots.push(primaryTime);
    document.querySelectorAll('.time-slot-item').forEach(function(item){
        var h  = parseInt(item.querySelector('.slot-h').value);
        var m  = parseInt(item.querySelector('.slot-m').value);
        var ap = item.querySelector('.slot-ap').value;
        if (!isNaN(h) && !isNaN(m)) {
            var h24 = h;
            if (ap==='AM'){ if(h===12)h24=0; } else { if(h!==12)h24=h+12; }
            slots.push(String(h24).padStart(2,'0')+':'+String(m).padStart(2,'0'));
        }
    });
    document.getElementById('timeSlotsHidden').value = slots.join(',');
    updateRecurSummary();
}

function serializeRecurrence(){
    if (!document.getElementById('recurToggle').checked) return;
    var freq = document.querySelector('input[name="freq_ui"]:checked').value;
    var days = [];
    document.querySelectorAll('.day-chk:checked').forEach(function(c){ days.push(c.value); });

    // If weekly frequency and no day was manually checked, auto-fill day of week from start date
    if (freq === 'weekly' && days.length === 0) {
        var startVal = document.getElementById('meetingDateInput').value;
        if (startVal) {
            var dayNames = ['sunday','monday','tuesday','wednesday','thursday','friday','saturday'];
            var dIdx = new Date(startVal + 'T00:00:00').getDay();
            days.push(dayNames[dIdx]);
            var dayChk = document.getElementById('day_' + dayNames[dIdx]);
            if (dayChk) dayChk.checked = true;
        }
    }

    document.getElementById('recurTypeHidden').value = freq;
    document.getElementById('recurDaysHidden').value = days.join(',');
    var endInp = document.getElementById('recurEndDate');
    document.getElementById('recurEndHidden').value  = endInp ? endInp.value : '';
    updateTimeSlotsHidden();
}

// ── Live occurrence preview ──
var DAYS_SHORT = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
var DAY_MAP    = {monday:1,tuesday:2,wednesday:3,thursday:4,friday:5,saturday:6,sunday:0};

function buildOccurrencePreview(){
    if (!recurOpen) return;
    var startInp = document.getElementById('meetingDateInput');
    var endInp   = document.getElementById('recurEndDate');
    var startStr = startInp ? startInp.value : '';
    var endStr   = endInp   ? endInp.value   : '';
    if (!startStr) return;

    var freq = document.querySelector('input[name="freq_ui"]:checked').value;
    var activeDays = [];
    document.querySelectorAll('.day-chk:checked').forEach(function(c){
        var v = c.value.toLowerCase();
        if (DAY_MAP[v] !== undefined) activeDays.push(DAY_MAP[v]);
    });

    var start   = new Date(startStr + 'T00:00:00');
    var endDate = endStr ? new Date(endStr + 'T00:00:00') : new Date(start.getTime() + 30 * 24 * 60 * 60 * 1000);
    var MAX_SHOW = 14;
    var dates    = [];
    var cur      = new Date(start);

    while (cur <= endDate && dates.length < MAX_SHOW) {
        var dow = cur.getDay();
        var include = false;
        if (freq === 'daily') {
            include = true;
        } else if (freq === 'weekly' || freq === 'custom') {
            include = activeDays.indexOf(dow) !== -1;
        }
        if (include) {
            dates.push(new Date(cur));
        }
        cur.setDate(cur.getDate() + 1);
    }

    var box   = document.getElementById('occurrencePreviewBox');
    var chips = document.getElementById('occurrenceChips');
    if (!dates.length) { box.style.display = 'none'; return; }
    box.style.display = 'block';
    chips.innerHTML   = '';
    dates.forEach(function(d){
        var chip = document.createElement('span');
        chip.className = 'occurrence-chip';
        chip.textContent = DAYS_SHORT[d.getDay()] + ' ' + d.getDate() + ' ' + d.toLocaleString('default',{month:'short'});
        chips.appendChild(chip);
    });
    // Show total count if end date set and more than preview
    if (endStr) {
        var totalCount = countAllOccurrences(start, new Date(endStr + 'T00:00:00'), freq, activeDays);
        if (totalCount > MAX_SHOW) {
            var more = document.createElement('span');
            more.className = 'occurrence-chip more';
            more.textContent = '+' + (totalCount - MAX_SHOW) + ' more · ' + totalCount + ' total';
            chips.appendChild(more);
        }
    }
}

function countAllOccurrences(start, end, freq, activeDays){
    var count = 0; var cur = new Date(start); var MAX = 1000;
    while (cur <= end && count < MAX) {
        var dow = cur.getDay();
        if (freq === 'daily') count++;
        else if ((freq === 'weekly' || freq === 'custom') && activeDays.indexOf(dow) !== -1) count++;
        cur.setDate(cur.getDate() + 1);
    }
    return count;
}

function updateRecurSummary(){
    if (!recurOpen) return;
    serializeRecurrence();
    var freq  = document.querySelector('input[name="freq_ui"]:checked').value;
    var days  = [];
    document.querySelectorAll('.day-chk:checked').forEach(function(c){ days.push(c.value.charAt(0).toUpperCase()+c.value.slice(1,3)); });
    var endDate   = document.getElementById('recurEndDate').value;
    var slotCount = document.querySelectorAll('.time-slot-item').length + 1;
    var parts = [];
    if (freq==='daily')       parts.push('Every day');
    else if (freq==='weekly') parts.push('Weekly'+(days.length?' on '+days.join(', '):''));
    else if (freq==='custom') parts.push(days.length?days.join(', '):'Custom days');
    if (slotCount > 1)  parts.push(slotCount+' time slots');
    if (endDate)        parts.push('until '+endDate);
    var badge = document.getElementById('recurSummaryBadge');
    if (parts.length) {
        badge.style.display = 'inline-flex';
        badge.innerHTML = '<span class="recur-summary"><i class="fas fa-redo" style="font-size:.65rem;"></i> '+parts.join(' · ')+'</span>';
    } else {
        badge.style.display = 'none';
    }
    buildOccurrencePreview();
}
</script>
</body>
</html>