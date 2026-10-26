<?php
session_start();
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}

include('../include/db_config.php');

$userId = $_SESSION['userId'];
// session_write_close(); // Release session lock — this page only reads session data

$userRow = mysqli_fetch_assoc(mysqli_query($con,
    "SELECT fullName, userRole, designation, emp_id, internId FROM signup WHERE userId='$userId'"
));
$userName     = $userRow ? htmlspecialchars($userRow['fullName']) : 'Unknown';
$userRoleVal  = strtolower(trim($userRow['userRole'] ?? ''));
$userDesigVal = strtolower(trim($userRow['designation'] ?? ''));
$userEmpId    = $userRow ? trim($userRow['emp_id'] ?? '') : '';
$userInternId = $userRow ? trim($userRow['internId'] ?? '') : '';

$rhRoles  = ['hr', 'admin', 'superadmin', 'resourceallocator', 'resource_allocator', 'resourcehead', 'resource_head', 'resource allocator'];
$isHR             = in_array($userRoleVal, $rhRoles) || in_array($userDesigVal, $rhRoles);
$creatorRoles     = ['seniorintern','senior_intern','receptionist', 'admin', 'hr', 'resourceallocator', 'resource_allocator', 'resourcehead', 'resource_head'];
$isAllowedCreator = in_array($userRoleVal, $creatorRoles) || in_array($userDesigVal, $creatorRoles);
$isIntern         = !$isAllowedCreator;

// ── Fetch map of all ACTIVE user IDs in signup ──
$activeUserIds = [];
$userNameMap   = [];
$actQ = mysqli_query($con, "
    SELECT userId, internId, emp_id, fullName
    FROM signup 
    WHERE (TRIM(LOWER(status)) = 'active' OR status = '1' OR status = 1)
");
if ($actQ) {
    while ($actRow = mysqli_fetch_assoc($actQ)) {
        $fn = trim($actRow['fullName']);
        if (!empty($actRow['userId']))   { $activeUserIds[trim($actRow['userId'])] = true;   $userNameMap[trim($actRow['userId'])] = $fn; }
        if (!empty($actRow['internId'])) { $activeUserIds[trim($actRow['internId'])] = true; $userNameMap[trim($actRow['internId'])] = $fn; }
        if (!empty($actRow['emp_id']))   { $activeUserIds[trim($actRow['emp_id'])] = true;   $userNameMap[trim($actRow['emp_id'])] = $fn; }
    }
}

// ── Ensure all columns/tables exist ──
$chkStatus = mysqli_query($con, "SHOW COLUMNS FROM meetings LIKE 'status'");
if ($chkStatus && mysqli_num_rows($chkStatus) === 0)
    mysqli_query($con, "ALTER TABLE meetings ADD COLUMN status VARCHAR(20) DEFAULT NULL");

$chkMLink = mysqli_query($con, "SHOW COLUMNS FROM meetings LIKE 'meeting_link'");
if ($chkMLink && mysqli_num_rows($chkMLink) === 0)
    mysqli_query($con, "ALTER TABLE meetings ADD COLUMN meeting_link VARCHAR(500) DEFAULT NULL");

$chkColCohost = mysqli_query($con, "SHOW COLUMNS FROM meetings LIKE 'co_hosts'");
if ($chkColCohost && mysqli_num_rows($chkColCohost) === 0)
    mysqli_query($con, "ALTER TABLE meetings ADD COLUMN co_hosts TEXT DEFAULT NULL AFTER participants");

function isHostOrCoHost($con, $meetingId, $userId, $userEmpId = '', $userInternId = '') {
    if (empty($userEmpId) && isset($GLOBALS['userEmpId'])) $userEmpId = $GLOBALS['userEmpId'];
    if (empty($userInternId) && isset($GLOBALS['userInternId'])) $userInternId = $GLOBALS['userInternId'];
    if (isset($GLOBALS['isHR']) && $GLOBALS['isHR']) return true;

    $midEsc = intval($meetingId);
    $q = mysqli_query($con, "SELECT creator_id, co_hosts FROM meetings WHERE id=$midEsc LIMIT 1");
    if ($q && $r = mysqli_fetch_assoc($q)) {
        $creator = (string)$r['creator_id'];
        if ($creator === (string)$userId || (!empty($userEmpId) && $creator === (string)$userEmpId) || (!empty($userInternId) && $creator === (string)$userInternId)) {
            return true;
        }
        $coHosts = array_filter(array_map('trim', explode(',', $r['co_hosts'] ?? '')));
        if (in_array((string)$userId, $coHosts) || (!empty($userEmpId) && in_array((string)$userEmpId, $coHosts)) || (!empty($userInternId) && in_array((string)$userInternId, $coHosts))) {
            return true;
        }
    }
    return false;
}

mysqli_query($con, "
    CREATE TABLE IF NOT EXISTS meeting_occurrences (
        id INT AUTO_INCREMENT PRIMARY KEY,
        meeting_id INT NOT NULL,
        occurrence_date DATE NOT NULL,
        occurrence_time TIME NOT NULL,
        status VARCHAR(20) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_meeting_occurrence (meeting_id, occurrence_date, occurrence_time),
        KEY idx_meeting_id (meeting_id),
        KEY idx_occurrence_date (occurrence_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── meeting_attendance: ensure occurrence_id column exists ──
$chkOccAtt = mysqli_query($con, "SHOW COLUMNS FROM meeting_attendance LIKE 'occurrence_id'");
if ($chkOccAtt && mysqli_num_rows($chkOccAtt) === 0)
    mysqli_query($con, "ALTER TABLE meeting_attendance ADD COLUMN occurrence_id INT NOT NULL DEFAULT 0 AFTER meetingId, ADD KEY idx_occ_att (occurrence_id)");

// Normalise any legacy NULL rows to 0 (cheap no-op when none exist)
mysqli_query($con, "UPDATE meeting_attendance SET occurrence_id = 0 WHERE occurrence_id IS NULL");

// Drop ALL old single-session unique keys (meetingId+userId only, no occurrence_id).
// The original table key name is unpredictable - enumerate all indexes and drop
// any unique index covering (meetingId, userId) WITHOUT occurrence_id.
// These cause ON DUPLICATE KEY to overwrite session-1 data when session-2 is marked.
$allIndexes = mysqli_query($con, "SHOW INDEX FROM meeting_attendance");
$indexesByName = [];

// ── Helper: Check if meeting/occurrence time has passed or cancelled ──
function checkMeetingStartedOrExit($con, $mid, $occId) {
    date_default_timezone_set('Asia/Kolkata');
    $now = date('Y-m-d H:i:s');
    $midEsc = intval($mid);

    $qM = mysqli_query($con, "SELECT meeting_date, meeting_time, status FROM meetings WHERE id=$midEsc LIMIT 1");
    if ($qM && $rM = mysqli_fetch_assoc($qM)) {
        if (strtolower(trim($rM['status'] ?? '')) === 'cancelled') {
            echo json_encode(['success' => false, 'error' => 'This meeting has been cancelled. MOM filling and attendance marking are disabled.']);
            exit;
        }
        if (empty($occId) || intval($occId) === 0) {
            $mDT = $rM['meeting_date'] . ' ' . $rM['meeting_time'];
            if ($now < $mDT) {
                $fDT = date('d M Y h:i A', strtotime($mDT));
                echo json_encode(['success' => false, 'error' => "MOM filling and attendance marking are only allowed after the scheduled meeting time ($fDT)."]);
                exit;
            }
        }
    }

    if (!empty($occId) && intval($occId) > 0) {
        $occIdEsc = intval($occId);
        $qOcc = mysqli_query($con, "SELECT occurrence_date, occurrence_time, status FROM meeting_occurrences WHERE id=$occIdEsc LIMIT 1");
        if ($qOcc && $rOcc = mysqli_fetch_assoc($qOcc)) {
            if (strtolower(trim($rOcc['status'] ?? '')) === 'cancelled') {
                echo json_encode(['success' => false, 'error' => 'This session has been cancelled. MOM filling and attendance marking are disabled.']);
                exit;
            }
            $occDT = $rOcc['occurrence_date'] . ' ' . $rOcc['occurrence_time'];
            if ($now < $occDT) {
                $fTime = date('h:i A', strtotime($rOcc['occurrence_time']));
                echo json_encode(['success' => false, 'error' => "MOM filling and attendance marking are only allowed after the scheduled session time ($fTime)."]);
                exit;
            }
        }
    }
}

// ── Helper: generate occurrences for a recurring meeting ──
function generateOccurrences($con, $meetingId, $startDate, $primaryTime, $recurrenceType, $recurrenceDays, $recurrenceEnd, $timeSlots) {
    // Build list of times to schedule each day (ensure HH:MM format)
    $rawTimes = [];
    if (!empty($timeSlots)) {
        $rawTimes = array_filter(array_map('trim', explode(',', $timeSlots)));
    }
    if (empty($rawTimes) && !empty($primaryTime)) {
        $rawTimes = [trim($primaryTime)];
    }

    $times = [];
    foreach ($rawTimes as $t) {
        $t = trim($t);
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
            $d = strtolower(trim($d));
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
while ($idxRow = mysqli_fetch_assoc($allIndexes)) {
    $kn = $idxRow['Key_name'];
    if (!isset($indexesByName[$kn])) $indexesByName[$kn] = [];
    $indexesByName[$kn][] = $idxRow['Column_name'];
}
foreach ($indexesByName as $keyName => $cols) {
    if ($keyName === 'PRIMARY' || $keyName === 'uq_meeting_att_occ') continue;
    $hasOccurrence = in_array('occurrence_id', $cols);
    $hasMeeting    = in_array('meetingId',     $cols);
    $hasUser       = in_array('userId',        $cols);
    // Drop any unique index covering meetingId+userId but missing occurrence_id
    if ($hasMeeting && $hasUser && !$hasOccurrence) {
        mysqli_query($con, "ALTER TABLE meeting_attendance DROP INDEX `$keyName`");
    }
}

// Add the correct 3-column unique key if missing
$chkUniqueKey = mysqli_query($con, "SHOW INDEX FROM meeting_attendance WHERE Key_name = 'uq_meeting_att_occ'");
if ($chkUniqueKey && mysqli_num_rows($chkUniqueKey) === 0) {
    mysqli_query($con, "ALTER TABLE meeting_attendance MODIFY COLUMN occurrence_id INT NOT NULL DEFAULT 0");
    mysqli_query($con, "ALTER TABLE meeting_attendance ADD UNIQUE KEY uq_meeting_att_occ (meetingId, occurrence_id, userId)");
}

$momTableCheck = mysqli_query($con, "SHOW TABLES LIKE 'meeting_mom'");
if (!$momTableCheck || mysqli_num_rows($momTableCheck) === 0) {
    mysqli_query($con, "
        CREATE TABLE IF NOT EXISTS meeting_mom (
            id INT AUTO_INCREMENT PRIMARY KEY,
            meeting_id INT NOT NULL,
            occurrence_id INT DEFAULT NULL,
            discussion_points TEXT,
            mom_finalised TINYINT(1) DEFAULT 0,
            attendance_synced TINYINT(1) DEFAULT 0,
            created_by VARCHAR(50),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_meeting_id (meeting_id),
            KEY idx_occ_mom (occurrence_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} else {
    $chkOccMom = mysqli_query($con, "SHOW COLUMNS FROM meeting_mom LIKE 'occurrence_id'");
    if ($chkOccMom && mysqli_num_rows($chkOccMom) === 0)
        mysqli_query($con, "ALTER TABLE meeting_mom ADD COLUMN occurrence_id INT DEFAULT NULL AFTER meeting_id, ADD KEY idx_occ_mom (occurrence_id)");
}

/* =============================================================
   AJAX HANDLERS
   ============================================================= */

if (isset($_POST['action']) && $_POST['action'] === 'get_all_users') {
    header('Content-Type: application/json');
    $q = mysqli_query($con, "SELECT userId, fullName, emp_id, internId FROM signup WHERE (TRIM(LOWER(status)) = 'active' OR status = '1' OR status = 1) ORDER BY fullName ASC");
    $users = [];
    while ($row = mysqli_fetch_assoc($q)) {
        $users[] = ['userId' => $row['userId'], 'fullName' => $row['fullName'], 'id' => $row['emp_id'] ?: $row['internId']];
    }

    $batchesList = [];
    $bQ = mysqli_query($con, "SELECT id, batchName, batchCode FROM batches WHERE (TRIM(LOWER(status)) = 'active' OR status IS NULL OR status = '1' OR status = 1) ORDER BY batchName ASC");
    if ($bQ) {
        while ($b = mysqli_fetch_assoc($bQ)) {
            $batchesList[] = [
                'id'        => $b['id'],
                'batchName' => trim((string)$b['batchName']),
                'batchCode' => trim((string)$b['batchCode'])
            ];
        }
    }

    $batchMembersMap = [];
    $bmQ = mysqli_query($con, "
        SELECT DISTINCT batch_val, userId FROM (
            SELECT COALESCE(NULLIF(s.userId,''), NULLIF(i.internId,''), i.id) AS userId,
                   i.batch AS batch_val
            FROM interns i
            INNER JOIN signup s ON (TRIM(s.internId) = TRIM(i.internId) OR (s.email IS NOT NULL AND s.email != '' AND LOWER(TRIM(s.email)) = LOWER(TRIM(i.email))))
            WHERE i.batch IS NOT NULL AND TRIM(i.batch) != ''
              AND (TRIM(LOWER(s.status)) = 'active' OR s.status = '1' OR s.status = 1)

            UNION

            SELECT COALESCE(NULLIF(s.userId,''), NULLIF(s.internId,''), s.emp_id) AS userId,
                   s.batch_number AS batch_val
            FROM signup s
            WHERE s.batch_number IS NOT NULL AND TRIM(s.batch_number) != ''
              AND (TRIM(LOWER(s.status)) = 'active' OR s.status = '1' OR s.status = 1)
         ) combined
         WHERE userId IS NOT NULL AND userId != ''
    ");
    if ($bmQ) {
        while ($r = mysqli_fetch_assoc($bmQ)) {
            $bKey = strtolower(trim((string)$r['batch_val']));
            $uId  = (string)$r['userId'];
            if ($bKey !== '') {
                if (!isset($batchMembersMap[$bKey])) $batchMembersMap[$bKey] = [];
                if (!in_array($uId, $batchMembersMap[$bKey])) $batchMembersMap[$bKey][] = $uId;
            }
        }
    }

    echo json_encode([
        'success'      => true,
        'users'        => $users,
        'batches'      => $batchesList,
        'batchMembers' => $batchMembersMap
    ]);
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'fetch_occurrences') {
    header('Content-Type: application/json');
    $mid = intval($_POST['meeting_id']);
    $q   = mysqli_query($con,
        "SELECT id, occurrence_date, occurrence_time, status
         FROM meeting_occurrences
         WHERE meeting_id = $mid
         ORDER BY occurrence_date ASC, occurrence_time ASC"
    );
    $rows = [];
    while ($r = mysqli_fetch_assoc($q)) $rows[] = $r;
    echo json_encode(['success' => true, 'occurrences' => $rows]);
    exit;
}

/* ── debug_attendance ── (remove after diagnosis) */
if (isset($_POST['action']) && $_POST['action'] === 'debug_attendance') {
    header('Content-Type: application/json');
    if (!$isHR && !$isAllowedCreator) { echo json_encode(['error'=>'Not authorized']); exit; }
    $mid = intval($_POST['meeting_id']);
    $attRows = []; $occRows = [];
    $qAtt = mysqli_query($con,
        "SELECT id, meetingId, occurrence_id, userId, status, markedAt
         FROM meeting_attendance WHERE meetingId='$mid'
         ORDER BY occurrence_id ASC, id DESC"
    );
    while ($r = mysqli_fetch_assoc($qAtt)) $attRows[] = $r;
    $qOcc = mysqli_query($con,
        "SELECT id, occurrence_date, occurrence_time, status
         FROM meeting_occurrences WHERE meeting_id=$mid
         ORDER BY occurrence_date ASC"
    );
    while ($r = mysqli_fetch_assoc($qOcc)) $occRows[] = $r;
    echo json_encode(['meeting_id'=>$mid,'attendance'=>$attRows,'occurrences'=>$occRows]);
    exit;
}

/* ── mark_attendance ──
   FIX: Always use the actual occurrence_id passed from JS.
   For recurring meetings JS always sends the real occ.id (>=1).
   For non-recurring meetings JS sends 0. Never fall back to 0
   when a real id is provided. */
if (isset($_POST['action']) && $_POST['action'] === 'mark_attendance') {
    header('Content-Type: application/json');
    $mid        = mysqli_real_escape_string($con, trim($_POST['meeting_id']));
    $occId      = isset($_POST['occurrence_id']) ? intval($_POST['occurrence_id']) : 0;
    $targetUser = mysqli_real_escape_string($con, trim($_POST['target_user_id']));
    $status     = in_array($_POST['status'], ['present','absent']) ? $_POST['status'] : 'present';

    if (!isHostOrCoHost($con, $mid, $userId)) {
        echo json_encode(['success' => false, 'error' => 'Not authorized. Only the meeting creator or co-host can mark attendance.']); exit;
    }
    checkMeetingStartedOrExit($con, $mid, $occId);

    $participantRow = mysqli_fetch_assoc(mysqli_query($con, "SELECT fullName FROM signup WHERE userId='$targetUser'"));
    if (!$participantRow)
        $participantRow = mysqli_fetch_assoc(mysqli_query($con, "SELECT fullName FROM interns WHERE internId='$targetUser'"));
    $participantName = $participantRow ? mysqli_real_escape_string($con, $participantRow['fullName']) : 'Unknown';

    $markerRow    = mysqli_fetch_assoc(mysqli_query($con, "SELECT fullName FROM signup WHERE userId='$userId'"));
    $markedByName = $markerRow ? $markerRow['fullName'] : 'Unknown';

    // Always use the actual $occId (0 for non-recurring, real id for recurring)
    $q = mysqli_query($con,
        "INSERT INTO meeting_attendance (meetingId, occurrence_id, userId, participantName, status, markedBy, markedAt)
         VALUES ('$mid', $occId, '$targetUser', '$participantName', '$status', '$userId', NOW())
         ON DUPLICATE KEY UPDATE participantName='$participantName', status='$status', markedBy='$userId', markedAt=NOW()"
    );

    echo $q
        ? json_encode(['success' => true, 'participantName' => $participantName, 'markedByName' => $markedByName])
        : json_encode(['success' => false, 'error' => mysqli_error($con)]);
    exit;
}

/* ── fetch_attendance ──
   For recurring sessions (occId > 0), query with the actual occurrence_id.
   Legacy fallback (occurrence_id = 0 / NULL) is ONLY applied for the very
   first occurrence in the series — later sessions never had legacy data.
   For non-recurring (occId = 0), query occurrence_id = 0 / NULL. */
if (isset($_POST['action']) && $_POST['action'] === 'fetch_attendance') {
    header('Content-Type: application/json');
    $mid            = mysqli_real_escape_string($con, trim($_POST['meeting_id']));
    $occId          = isset($_POST['occurrence_id']) ? intval($_POST['occurrence_id']) : 0;
    $participantStr = $_POST['participants'] ?? '';
    $pIds           = array_filter(array_map('trim', explode(',', $participantStr)));
    $result         = [];

    if (empty($pIds)) {
        echo json_encode(['success' => true, 'participants' => [], 'grouped' => []]);
        exit;
    }

    // ── OPTIMIZATION: Bulk-fetch all participant details in 2 queries (signup + interns)
    // instead of 2 queries per participant inside a loop.
    $escapedIds = implode("','", array_map(fn($id) => mysqli_real_escape_string($con, $id), $pIds));

    $signupMap  = [];
    $qSignup = mysqli_query($con,
        "SELECT userId, fullName, emp_id, internId, batch_number
         FROM signup WHERE userId IN ('$escapedIds')
         AND (TRIM(LOWER(status)) = 'active' OR status = '1' OR status = 1)"
    );
    while ($row = mysqli_fetch_assoc($qSignup)) {
        $signupMap[$row['userId']] = $row;
    }

    $internMap = [];
    // Only fetch active interns for IDs not already found in signup
    $missingIds = array_filter($pIds, fn($id) => !isset($signupMap[$id]));
    if (!empty($missingIds)) {
        $escapedMissing = implode("','", array_map(fn($id) => mysqli_real_escape_string($con, $id), $missingIds));
        $qIntern = mysqli_query($con,
            "SELECT i.internId as userId, i.fullName, i.internId as emp_id, i.internId, i.batch as batch_number
             FROM interns i
             LEFT JOIN signup s ON (TRIM(s.internId) = TRIM(i.internId) OR (s.email IS NOT NULL AND s.email != '' AND LOWER(TRIM(s.email)) = LOWER(TRIM(i.email))))
             WHERE i.internId IN ('$escapedMissing')
               AND (s.status IS NULL OR TRIM(LOWER(s.status)) = 'active' OR s.status = '1' OR s.status = 1)"
        );
        while ($row = mysqli_fetch_assoc($qIntern)) {
            $internMap[$row['userId']] = $row;
        }
    }

    // ── Determine ONCE whether this occurrence is the first in the series ──
    $isFirstOccurrence = false;
    if ($occId > 0) {
        $meetingIdForOcc = mysqli_fetch_assoc(mysqli_query($con,
            "SELECT meeting_id FROM meeting_occurrences WHERE id = $occId LIMIT 1"
        ));
        if ($meetingIdForOcc) {
            $mIdForOcc = intval($meetingIdForOcc['meeting_id']);
            $firstOcc  = mysqli_fetch_assoc(mysqli_query($con,
                "SELECT id FROM meeting_occurrences
                 WHERE meeting_id = $mIdForOcc
                 ORDER BY occurrence_date ASC, occurrence_time ASC LIMIT 1"
            ));
            $isFirstOccurrence = ($firstOcc && intval($firstOcc['id']) === $occId);
        }
    }

    // ── Determine session date for Leave/Tour/Roster Off checking ──
    $sessionDate = date('Y-m-d');
    if ($occId > 0) {
        $qOccDate = mysqli_query($con, "SELECT occurrence_date FROM meeting_occurrences WHERE id=$occId LIMIT 1");
        if ($qOccDate && $rOccDate = mysqli_fetch_assoc($qOccDate)) {
            $sessionDate = $rOccDate['occurrence_date'];
        }
    } else {
        $qMDate = mysqli_query($con, "SELECT meeting_date FROM meetings WHERE id=$mid LIMIT 1");
        if ($qMDate && $rMDate = mysqli_fetch_assoc($qMDate)) {
            $sessionDate = $rMDate['meeting_date'];
        }
    }

    // ── Fetch Leaves & Tours for this session date ──
    $leaveMap = [];
    $tourMap  = [];

    $qLeave = mysqli_query($con, "
        SELECT userId, leave_type, leave_name, reason
        FROM leaves
        WHERE (TRIM(LOWER(status)) = 'approved' OR status = '1' OR status = 1)
          AND '$sessionDate' BETWEEN DATE(from_date) AND DATE(to_date)
    ");
    if ($qLeave) {
        while ($lRow = mysqli_fetch_assoc($qLeave)) {
            $uid = trim($lRow['userId']);
            $lt  = strtolower(trim($lRow['leave_type'] ?? ''));
            $ln  = strtolower(trim($lRow['leave_name'] ?? ''));
            if ($lt === 'ro' || strpos($ln, 'roster') !== false) {
                $leaveMap[$uid] = ['type' => 'roster_off', 'label' => '🔒 Rostering Off'];
            } else {
                $leaveMap[$uid] = ['type' => 'on_leave', 'label' => '🏖️ On Leave'];
            }
        }
    }

    $qTour = mysqli_query($con, "
        SELECT userID as userId, reason
        FROM tour
        WHERE (TRIM(LOWER(status)) = 'approved' OR status = '1' OR status = 1)
          AND '$sessionDate' BETWEEN DATE(fromDate) AND DATE(toDate)
    ");
    if ($qTour) {
        while ($tRow = mysqli_fetch_assoc($qTour)) {
            $uid = trim($tRow['userId']);
            $tourMap[$uid] = ['type' => 'on_tour', 'label' => '✈️ On Tour'];
        }
    }

    // ── OPTIMIZATION: Bulk-fetch all attendance records for this meeting+occurrence
    $attMap = []; // userId => attendance row
    if ($occId > 0) {
        // Exact match for this occurrence
        $qAtt = mysqli_query($con,
            "SELECT ma.userId, ma.status, ma.participantName, ma.occurrence_id,
                    s.fullName as markedByName
             FROM meeting_attendance ma
             LEFT JOIN signup s ON s.userId = ma.markedBy
             WHERE ma.meetingId='$mid'
               AND ma.occurrence_id = $occId
             ORDER BY ma.id DESC"
        );
        while ($row = mysqli_fetch_assoc($qAtt)) {
            if (!isset($attMap[$row['userId']])) {
                $attMap[$row['userId']] = $row;
            }
        }

        // Legacy fallback for first occurrence only
        if ($isFirstOccurrence) {
            $qLegacy = mysqli_query($con,
                "SELECT ma.userId, ma.status, ma.participantName, ma.occurrence_id,
                        s.fullName as markedByName
                 FROM meeting_attendance ma
                 LEFT JOIN signup s ON s.userId = ma.markedBy
                 WHERE ma.meetingId='$mid'
                   AND (ma.occurrence_id = 0 OR ma.occurrence_id IS NULL)
                 ORDER BY ma.id DESC"
            );
            while ($row = mysqli_fetch_assoc($qLegacy)) {
                if (!isset($attMap[$row['userId']]) && in_array($row['status'], ['present','absent','absent_intimated','on_leave','on_tour','roster_off'])) {
                    $attMap[$row['userId']] = $row;
                }
            }
        }
    } else {
        // Non-recurring or legacy: occurrence_id = 0 / NULL
        $qAtt = mysqli_query($con,
            "SELECT ma.userId, ma.status, ma.participantName, ma.occurrence_id,
                    s.fullName as markedByName
             FROM meeting_attendance ma
             LEFT JOIN signup s ON s.userId = ma.markedBy
             WHERE ma.meetingId='$mid'
               AND (ma.occurrence_id = 0 OR ma.occurrence_id IS NULL)
             ORDER BY ma.id DESC"
        );
        while ($row = mysqli_fetch_assoc($qAtt)) {
            if (!isset($attMap[$row['userId']])) {
                $attMap[$row['userId']] = $row;
            }
        }
    }

    // ── Assemble result from maps — zero additional DB queries ──
    foreach ($pIds as $pid) {
        $pid = trim($pid);
        if ($pid === '') continue;

        $u   = $signupMap[$pid] ?? $internMap[$pid] ?? null;
        $att = $attMap[$pid] ?? null;

        if ($u) {
            $systemStatus = $tourMap[$pid] ?? $leaveMap[$pid] ?? null;

            if ($att && in_array($att['status'], ['present','absent','absent_intimated','on_leave','on_tour','roster_off'])) {
                $u['attendance'] = $att['status'];
            } elseif ($systemStatus) {
                $u['attendance'] = $systemStatus['type'];
            } else {
                $u['attendance'] = null;
            }

            $u['systemStatus']    = $systemStatus;
            $u['participantName'] = ($att && !empty($att['participantName'])) ? $att['participantName'] : $u['fullName'];
            $u['markedByName']    = ($att && !empty($att['markedByName'])) ? $att['markedByName'] : ($systemStatus ? 'System (Auto)' : null);
            $result[] = $u;
        }
    }

    $grouped = [];
    foreach ($result as $p) {
        $batch = $p['batch_number'] ?: 'Unassigned';
        $grouped[$batch][] = $p;
    }

    echo json_encode(['success' => true, 'participants' => $result, 'grouped' => $grouped]);
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'add_point') {
    header('Content-Type: application/json');
    $mid   = mysqli_real_escape_string($con, trim($_POST['meeting_id']));
    $occId = isset($_POST['occurrence_id']) ? intval($_POST['occurrence_id']) : 0;
    $point = mysqli_real_escape_string($con, trim($_POST['point']));

    if (!isHostOrCoHost($con, $mid, $userId)) {
        echo json_encode(['success' => false, 'error' => 'Not authorized. Only the meeting creator or co-host can add MOM points.']); exit;
    }
    checkMeetingStartedOrExit($con, $mid, $occId);

    if ($point !== '') {
        $occCond = $occId > 0 ? "AND occurrence_id=$occId" : "AND (occurrence_id IS NULL OR occurrence_id=0)";
        $mom = mysqli_fetch_assoc(mysqli_query($con,
            "SELECT id, discussion_points FROM meeting_mom WHERE meeting_id='$mid' $occCond ORDER BY id DESC LIMIT 1"
        ));
        if ($mom) {
            $updated = $mom['discussion_points'] ? $mom['discussion_points'].'||'.$point : $point;
            $updated = mysqli_real_escape_string($con, $updated);
            mysqli_query($con, "UPDATE meeting_mom SET discussion_points='$updated', updated_at=NOW() WHERE id='{$mom['id']}'");
        } else {
            $occVal = $occId > 0 ? $occId : 'NULL';
            mysqli_query($con, "INSERT INTO meeting_mom (meeting_id, occurrence_id, discussion_points, created_by, created_at) VALUES ('$mid',$occVal,'$point','$userId',NOW())");
        }
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false]);
    }
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'edit_point') {
    header('Content-Type: application/json');
    $mid     = mysqli_real_escape_string($con, trim($_POST['meeting_id']));
    $occId   = isset($_POST['occurrence_id']) ? intval($_POST['occurrence_id']) : 0;
    $index   = intval($_POST['point_index']);
    $newText = mysqli_real_escape_string($con, trim($_POST['new_text']));

    if (!isHostOrCoHost($con, $mid, $userId)) {
        echo json_encode(['success' => false, 'error' => 'Not authorized. Only the meeting creator or co-host can edit MOM points.']); exit;
    }
    checkMeetingStartedOrExit($con, $mid, $occId);

    if ($newText !== '') {
        $occCond = $occId > 0 ? "AND occurrence_id=$occId" : "AND (occurrence_id IS NULL OR occurrence_id=0)";
        $mom = mysqli_fetch_assoc(mysqli_query($con,
            "SELECT id, discussion_points FROM meeting_mom WHERE meeting_id='$mid' $occCond ORDER BY id DESC LIMIT 1"
        ));
        if ($mom) {
            $pts = explode('||', $mom['discussion_points']);
            if (isset($pts[$index])) {
                $pts[$index] = $newText;
                $updated = mysqli_real_escape_string($con, implode('||', $pts));
                mysqli_query($con, "UPDATE meeting_mom SET discussion_points='$updated', updated_at=NOW() WHERE id='{$mom['id']}'");
                echo json_encode(['success' => true]);
            } else { echo json_encode(['success' => false, 'error' => 'Invalid index.']); }
        } else { echo json_encode(['success' => false, 'error' => 'No MOM found.']); }
    } else { echo json_encode(['success' => false, 'error' => 'Text cannot be empty.']); }
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'delete_point') {
    header('Content-Type: application/json');
    $mid   = mysqli_real_escape_string($con, trim($_POST['meeting_id']));
    $occId = isset($_POST['occurrence_id']) ? intval($_POST['occurrence_id']) : 0;
    $index = intval($_POST['point_index']);

    if (!isHostOrCoHost($con, $mid, $userId)) {
        echo json_encode(['success' => false, 'error' => 'Not authorized. Only the meeting creator or co-host can delete MOM points.']); exit;
    }
    checkMeetingStartedOrExit($con, $mid, $occId);

    $occCond = $occId > 0 ? "AND occurrence_id=$occId" : "AND (occurrence_id IS NULL OR occurrence_id=0)";
    $mom = mysqli_fetch_assoc(mysqli_query($con,
        "SELECT id, discussion_points FROM meeting_mom WHERE meeting_id='$mid' $occCond ORDER BY id DESC LIMIT 1"
    ));
    if ($mom) {
        $pts = explode('||', $mom['discussion_points']);
        array_splice($pts, $index, 1);
        $updated = mysqli_real_escape_string($con, implode('||', $pts));
        mysqli_query($con, "UPDATE meeting_mom SET discussion_points='$updated', updated_at=NOW() WHERE id='{$mom['id']}'");
    }
    echo json_encode(['success' => true]);
    exit;
}

/* ── mark_batch_attendance ──
   FIX: Same as mark_attendance — always use the actual $occId. */
if (isset($_POST['action']) && $_POST['action'] === 'mark_batch_attendance') {
    header('Content-Type: application/json');
    $mid    = mysqli_real_escape_string($con, trim($_POST['meeting_id']));
    $occId  = isset($_POST['occurrence_id']) ? intval($_POST['occurrence_id']) : 0;
    $status = in_array($_POST['status'], ['present','absent','absent_intimated']) ? $_POST['status'] : 'present';
    $userIds = isset($_POST['user_ids']) ? (array)$_POST['user_ids'] : [];

    if (!isHostOrCoHost($con, $mid, $userId)) {
        echo json_encode(['success' => false, 'error' => 'Not authorized. Only the meeting creator or co-host can mark attendance.']); exit;
    }
    checkMeetingStartedOrExit($con, $mid, $occId);

    // ── OPTIMIZATION: Bulk-fetch all participant names in 2 queries
    // instead of 2 queries per user inside the loop.
    $cleanIds = array_filter(array_map('trim', $userIds));
    if (empty($cleanIds)) {
        echo json_encode(['success' => true]); exit;
    }

    $escapedIds = implode("','", array_map(fn($id) => mysqli_real_escape_string($con, $id), $cleanIds));

    $nameMap = [];
    $qNames = mysqli_query($con, "SELECT userId, fullName FROM signup WHERE userId IN ('$escapedIds')");
    while ($row = mysqli_fetch_assoc($qNames)) {
        $nameMap[$row['userId']] = $row['fullName'];
    }
    // Fetch active interns not found in signup
    $missingIds = array_filter($cleanIds, fn($id) => !isset($nameMap[$id]));
    if (!empty($missingIds)) {
        $escapedMissing = implode("','", array_map(fn($id) => mysqli_real_escape_string($con, $id), $missingIds));
        $qInternNames = mysqli_query($con,
            "SELECT i.internId, i.fullName
             FROM interns i
             LEFT JOIN signup s ON (TRIM(s.internId) = TRIM(i.internId) OR (s.email IS NOT NULL AND s.email != '' AND LOWER(TRIM(s.email)) = LOWER(TRIM(i.email))))
             WHERE i.internId IN ('$escapedMissing')
               AND (s.status IS NULL OR TRIM(LOWER(s.status)) = 'active' OR s.status = '1' OR s.status = 1)"
        );
        while ($row = mysqli_fetch_assoc($qInternNames)) {
            $nameMap[$row['internId']] = $row['fullName'];
        }
    }

    foreach ($cleanIds as $uid) {
        $uid   = mysqli_real_escape_string($con, $uid);
        $pName = isset($nameMap[$uid]) ? mysqli_real_escape_string($con, $nameMap[$uid]) : 'Unknown';

        // Always use the actual $occId (0 for non-recurring, real id for recurring)
        mysqli_query($con,
            "INSERT INTO meeting_attendance (meetingId, occurrence_id, userId, participantName, status, markedBy, markedAt)
             VALUES ('$mid', $occId, '$uid', '$pName', '$status', '$userId', NOW())
             ON DUPLICATE KEY UPDATE participantName='$pName', status='$status', markedBy='$userId', markedAt=NOW()"
        );
    }
    echo json_encode(['success' => true]);
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'complete_occurrence') {
    header('Content-Type: application/json');
    $mid   = intval($_POST['meeting_id']);
    $occId = intval($_POST['occurrence_id']);

    if (!isHostOrCoHost($con, $mid, $userId)) {
        echo json_encode(['success' => false, 'error' => 'Not authorized. Only the meeting creator or co-host can complete this meeting.']); exit;
    }
    checkMeetingStartedOrExit($con, $mid, $occId);

    if ($occId > 0) {
        $q = mysqli_query($con, "UPDATE meeting_occurrences SET status='completed' WHERE id=$occId AND meeting_id=$mid");
        mysqli_query($con, "UPDATE meeting_mom SET mom_finalised=1 WHERE meeting_id=$mid AND occurrence_id=$occId");
        echo $q ? json_encode(['success' => true]) : json_encode(['success' => false, 'error' => mysqli_error($con)]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid occurrence.']);
    }
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'complete_meeting') {
    header('Content-Type: application/json');
    $mid = intval($_POST['meeting_id']);

    if (!isHostOrCoHost($con, $mid, $userId)) {
        echo json_encode(['success' => false, 'error' => 'Not authorized. Only the meeting creator or co-host can complete this meeting.']); exit;
    }
    checkMeetingStartedOrExit($con, $mid, 0);

    $q = mysqli_query($con, "UPDATE meetings SET status='completed' WHERE id='$mid'");
    mysqli_query($con, "UPDATE meeting_mom SET mom_finalised=1 WHERE meeting_id='$mid'");
    echo $q ? json_encode(['success' => true]) : json_encode(['success' => false, 'error' => mysqli_error($con)]);
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'edit_meeting') {
    header('Content-Type: application/json');
    $mid = intval($_POST['meeting_id']);

    $chk = mysqli_fetch_assoc(mysqli_query($con, "SELECT meeting_date, status, creator_id FROM meetings WHERE id='$mid'"));
    if (!$chk) { echo json_encode(['success' => false, 'error' => 'Meeting not found.']); exit; }
    if ($chk['status'] === 'completed') { echo json_encode(['success' => false, 'error' => 'Completed meetings cannot be edited.']); exit; }
    if (!isHostOrCoHost($con, $mid, $userId)) { echo json_encode(['success' => false, 'error' => 'Not authorized. Only the meeting creator or co-host can edit this meeting.']); exit; }

    $agenda      = mysqli_real_escape_string($con, trim($_POST['agenda']));
    $description = mysqli_real_escape_string($con, trim($_POST['description']));
    $meetingDate = mysqli_real_escape_string($con, $_POST['meeting_date']);
    $meetingTime = mysqli_real_escape_string($con, $_POST['meeting_time']);
    $mode        = mysqli_real_escape_string($con, $_POST['mode']);
    $link        = mysqli_real_escape_string($con, trim($_POST['meeting_link'] ?? ''));
    $branch      = mysqli_real_escape_string($con, trim($_POST['branch'] ?? ''));

    if (!$meetingDate || !$meetingTime) { echo json_encode(['success' => false, 'error' => 'Date and time are required.']); exit; }

    $recurrenceType = mysqli_real_escape_string($con, $_POST['recurrence_type'] ?? '');
    $recurrenceDays = mysqli_real_escape_string($con, $_POST['recurrence_days'] ?? '');
    $recurrenceEnd  = mysqli_real_escape_string($con, $_POST['recurrence_end'] ?? '');
    $timeSlots      = mysqli_real_escape_string($con, $_POST['time_slots'] ?? '');

    $newParticipants = isset($_POST['participants']) ? array_unique(array_filter(array_map('trim', (array)$_POST['participants']))) : null;
    $participantsSql = '';
    if ($newParticipants !== null) {
        $participantsCsv = mysqli_real_escape_string($con, implode(',', $newParticipants));
        $participantsSql = ", participants='$participantsCsv'";
    }

    $newCoHosts = isset($_POST['co_hosts']) ? array_unique(array_filter(array_map('trim', (array)$_POST['co_hosts']))) : null;
    $coHostsSql = '';
    if ($newCoHosts !== null) {
        $coHostsCsv = mysqli_real_escape_string($con, implode(',', $newCoHosts));
        $coHostsSql = ", co_hosts='$coHostsCsv'";
    }

    mysqli_query($con,
        "UPDATE meetings SET
            agenda='$agenda', description='$description',
            meeting_date='$meetingDate', meeting_time='$meetingTime',
            mode='$mode', meeting_link='$link', branch='$branch',
            recurrence_type='$recurrenceType', recurrence_days='$recurrenceDays',
            recurrence_end=" . ($recurrenceEnd ? "'$recurrenceEnd'" : "NULL") . ",
            time_slots='$timeSlots'
            $participantsSql
            $coHostsSql
         WHERE id='$mid'"
    );

    if (!empty($recurrenceType)) {
        mysqli_query($con, "DELETE FROM meeting_occurrences WHERE meeting_id='$mid' AND (status IS NULL OR status != 'completed')");
        generateOccurrences($con, $mid, $meetingDate, $meetingTime, $recurrenceType, $recurrenceDays, $recurrenceEnd, $timeSlots);
    }

    echo json_encode(['success' => true]);
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'reschedule_meeting') {
    header('Content-Type: application/json');
    $mid         = intval($_POST['meeting_id']);
    $newDate     = mysqli_real_escape_string($con, trim($_POST['new_date']));
    $newTime     = mysqli_real_escape_string($con, trim($_POST['new_time']));
    $reschedNote = mysqli_real_escape_string($con, trim($_POST['reschedule_note'] ?? ''));

    if (!$newDate || !$newTime) { echo json_encode(['success' => false, 'error' => 'Date and time are required.']); exit; }
    if ($newDate < date('Y-m-d')) { echo json_encode(['success' => false, 'error' => 'Rescheduled date cannot be in the past.']); exit; }
    if ($newDate === date('Y-m-d') && $newTime < date('H:i', strtotime('-30 minutes'))) { echo json_encode(['success' => false, 'error' => 'Rescheduled time cannot be in the past for today.']); exit; }

    $existing = mysqli_fetch_assoc(mysqli_query($con, "SELECT creator_id, description, participants, agenda FROM meetings WHERE id='$mid'"));
    if (!$existing) { echo json_encode(['success' => false, 'error' => 'Meeting not found.']); exit; }
    if (!isHostOrCoHost($con, $mid, $userId)) { echo json_encode(['success' => false, 'error' => 'Not authorized. Only creator or co-host can reschedule this meeting.']); exit; }
    $newDesc  = $existing['description'] ?? '';
    if ($reschedNote) {
        $ts      = date('d M Y h:i A');
        $newDesc = $newDesc ? $newDesc . ' | Rescheduled on ' . $ts . ': ' . $reschedNote : 'Rescheduled on ' . $ts . ': ' . $reschedNote;
        $newDesc = mysqli_real_escape_string($con, $newDesc);
    }

    $q = mysqli_query($con,
        "UPDATE meetings SET meeting_date='$newDate', meeting_time='$newTime', description='$newDesc', status=NULL WHERE id='$mid'"
    );

    if ($q && !empty($existing['participants'])) {
        $pIds = array_filter(array_map('trim', explode(',', $existing['participants'])));
        $agendaSafe = mysqli_real_escape_string($con, $existing['agenda']);
        $fDate = date('d M Y', strtotime($newDate));
        $fTime = date('h:i A', strtotime($newTime));
        include_once('../include/notifications.php');
        foreach ($pIds as $pid) {
            $pid = mysqli_real_escape_string($con, $pid);
            if (!$pid) continue;
            mysqli_query($con,
                "INSERT INTO notifications (userId, title, message, type, status, is_read, created_at)
                 VALUES ('$pid', '📅 Meeting Rescheduled', 'Meeting \"$agendaSafe\" has been rescheduled to $fDate at $fTime.', 'meeting', 'active', 0, NOW())"
            );
        }
    }

    echo $q
        ? json_encode(['success' => true, 'new_date' => $newDate, 'new_time' => $newTime])
        : json_encode(['success' => false, 'error' => mysqli_error($con)]);
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'cancel_meeting') {
    header('Content-Type: application/json');
    $mid = intval($_POST['meeting_id']);

    $chk = mysqli_fetch_assoc(mysqli_query($con, "SELECT agenda, meeting_date, meeting_time, status, creator_id, participants FROM meetings WHERE id='$mid'"));
    if (!$chk) { echo json_encode(['success' => false, 'error' => 'Meeting not found.']); exit; }
    if ($chk['status'] === 'completed') { echo json_encode(['success' => false, 'error' => 'Completed meetings cannot be cancelled.']); exit; }
    if ($chk['status'] === 'cancelled') { echo json_encode(['success' => false, 'error' => 'Meeting is already cancelled.']); exit; }
    if (!isHostOrCoHost($con, $mid, $userId)) { echo json_encode(['success' => false, 'error' => 'Not authorized. Only creator or co-host can cancel this meeting.']); exit; }

    $q = mysqli_query($con, "UPDATE meetings SET status='cancelled' WHERE id='$mid'");
    if ($q) {
        mysqli_query($con, "UPDATE meeting_occurrences SET status='cancelled' WHERE meeting_id='$mid' AND (status IS NULL OR status != 'completed')");
        
        if (!empty($chk['participants'])) {
            $pIds = array_filter(array_map('trim', explode(',', $chk['participants'])));
            $agendaSafe = mysqli_real_escape_string($con, $chk['agenda']);
            $fDate = date('d M Y', strtotime($chk['meeting_date']));
            include_once('../include/notifications.php');
            foreach ($pIds as $pid) {
                $pidEsc = mysqli_real_escape_string($con, $pid);
                if (!$pidEsc) continue;
                mysqli_query($con,
                    "INSERT INTO notifications (userId, title, message, type, status, is_read, created_at)
                     VALUES ('$pidEsc', '❌ Meeting Cancelled', 'Meeting \"$agendaSafe\" scheduled for $fDate has been cancelled.', 'meeting', 'active', 0, NOW())"
                );
            }
        }
    }

    echo $q ? json_encode(['success' => true]) : json_encode(['success' => false, 'error' => mysqli_error($con)]);
    exit;
}


/* AJAX: FETCH MOM POINTS */
if (isset($_POST['action']) && $_POST['action'] === 'fetch_mom_points') {
    header('Content-Type: application/json');
    $mid   = intval($_POST['meeting_id']);
    $occId = isset($_POST['occurrence_id']) ? intval($_POST['occurrence_id']) : 0;

    $occCond = $occId > 0
        ? "AND occurrence_id=$occId"
        : "AND (occurrence_id IS NULL OR occurrence_id=0)";

    $mom = mysqli_fetch_assoc(mysqli_query($con,
        "SELECT discussion_points FROM meeting_mom
         WHERE meeting_id='$mid' $occCond
         ORDER BY id DESC LIMIT 1"
    ));

    $points = ($mom && $mom['discussion_points'])
        ? explode('||', $mom['discussion_points'])
        : [];

    echo json_encode(['success' => true, 'points' => $points]);
    exit;
}

/* ── FETCH ALL MEETINGS (STRICT ROLE & INVITATION ACCESS) ── */
$matchClause = "(
    FIND_IN_SET('$userId', REPLACE(m.participants, ' ', ''))
    OR FIND_IN_SET('$userId', REPLACE(m.co_hosts, ' ', ''))
    OR m.creator_id COLLATE utf8mb4_unicode_ci = '$userId'
";
if (!empty($userEmpId)) {
    $matchClause .= " OR FIND_IN_SET('$userEmpId', REPLACE(m.participants, ' ', '')) OR FIND_IN_SET('$userEmpId', REPLACE(m.co_hosts, ' ', '')) OR m.creator_id COLLATE utf8mb4_unicode_ci = '$userEmpId'";
}
if (!empty($userInternId)) {
    $matchClause .= " OR FIND_IN_SET('$userInternId', REPLACE(m.participants, ' ', '')) OR FIND_IN_SET('$userInternId', REPLACE(m.co_hosts, ' ', '')) OR m.creator_id COLLATE utf8mb4_unicode_ci = '$userInternId'";
}
$matchClause .= ")";

if ($isHR) {
    $meetingsQuery = mysqli_query($con,
        "SELECT m.*, s.fullName AS creator_name
         FROM meetings m
         LEFT JOIN signup s ON s.userId COLLATE utf8mb4_unicode_ci = m.creator_id COLLATE utf8mb4_unicode_ci
         ORDER BY m.meeting_date DESC, m.meeting_time DESC"
    );
} elseif ($isAllowedCreator) {
    $meetingsQuery = mysqli_query($con,
        "SELECT m.*, s.fullName AS creator_name
         FROM meetings m
         LEFT JOIN signup s ON s.userId COLLATE utf8mb4_unicode_ci = m.creator_id COLLATE utf8mb4_unicode_ci
         WHERE m.creator_id COLLATE utf8mb4_unicode_ci = '$userId'
            OR $matchClause
         ORDER BY m.meeting_date DESC, m.meeting_time DESC"
    );
} else {
    $meetingsQuery = mysqli_query($con,
        "SELECT m.*, s.fullName AS creator_name
         FROM meetings m
         LEFT JOIN signup s ON s.userId COLLATE utf8mb4_unicode_ci = m.creator_id COLLATE utf8mb4_unicode_ci
         WHERE $matchClause
         ORDER BY m.meeting_date DESC, m.meeting_time DESC"
    );
}
$meetings = [];
while ($row = mysqli_fetch_assoc($meetingsQuery)) $meetings[] = $row;

function isUserInvolvedInMeeting($m, $userId, $userEmpId = '', $userInternId = '') {
    if (empty($m)) return false;
    $creator = trim((string)($m['creator_id'] ?? ''));
    if ($creator === (string)$userId || (!empty($userEmpId) && $creator === (string)$userEmpId) || (!empty($userInternId) && $creator === (string)$userInternId)) {
        return true;
    }
    $coHosts = array_filter(array_map('trim', explode(',', $m['co_hosts'] ?? '')));
    if (in_array((string)$userId, $coHosts) || (!empty($userEmpId) && in_array((string)$userEmpId, $coHosts)) || (!empty($userInternId) && in_array((string)$userInternId, $coHosts))) {
        return true;
    }
    $participants = array_filter(array_map('trim', explode(',', $m['participants'] ?? '')));
    if (in_array((string)$userId, $participants) || (!empty($userEmpId) && in_array((string)$userEmpId, $participants)) || (!empty($userInternId) && in_array((string)$userInternId, $participants))) {
        return true;
    }
    return false;
}

function getMeetingEffectiveStatus($con, $m, $todayStr, $nowDT) {
    $rawStatus = strtolower(trim($m['status'] ?? ''));
    if ($rawStatus === 'completed' || $rawStatus === 'cancelled') {
        return $rawStatus;
    }
    if (!empty($m['recurrence_type'])) {
        return 'upcoming';
    }

    $mid   = $m['id'];
    $mDate = $m['meeting_date'];
    $mTime = $m['meeting_time'];
    $mDT   = $mDate . ' ' . $mTime;

    $hasAtt = hasAttendance($con, $mid);
    $points = getMomPoints($con, $mid);

    if ($mDate > $todayStr) {
        return 'upcoming';
    } elseif ($mDate === $todayStr) {
        if ($hasAtt || count($points) > 0 || $mDT < $nowDT) {
            return 'completed';
        }
        return 'upcoming';
    } else {
        return ($hasAtt || count($points) > 0) ? 'completed' : 'missed';
    }
}

$todayStr = date('Y-m-d');
$nowDTStr = date('Y-m-d H:i:s');

usort($meetings, function($a, $b) use ($con, $todayStr, $nowDTStr, $userId, $userEmpId, $userInternId) {
    $aEffStatus = getMeetingEffectiveStatus($con, $a, $todayStr, $nowDTStr);
    $bEffStatus = getMeetingEffectiveStatus($con, $b, $todayStr, $nowDTStr);

    $aIsUpcoming = ($aEffStatus === 'upcoming');
    $bIsUpcoming = ($bEffStatus === 'upcoming');

    $aInvolved = isUserInvolvedInMeeting($a, $userId, $userEmpId, $userInternId);
    $bInvolved = isUserInvolvedInMeeting($b, $userId, $userEmpId, $userInternId);

    // Rank 1: Upcoming + I am participant / host / co-host
    // Rank 2: Upcoming + I am not participant
    // Rank 3: Past / Completed / Cancelled / Missed
    $aRank = $aIsUpcoming ? ($aInvolved ? 1 : 2) : 3;
    $bRank = $bIsUpcoming ? ($bInvolved ? 1 : 2) : 3;

    if ($aRank !== $bRank) {
        return $aRank - $bRank;
    }

    // Rank 1 & 2 (Upcoming): Sort FORWARD in time (ASC: earliest upcoming meeting first)
    if ($aRank < 3) {
        if ($a['meeting_date'] !== $b['meeting_date']) {
            return strcmp($a['meeting_date'], $b['meeting_date']);
        }
        return strcmp($a['meeting_time'], $b['meeting_time']);
    }

    // Rank 3 (Past): Sort BACKWARD in time (DESC: most recent past meeting first)
    if ($a['meeting_date'] !== $b['meeting_date']) {
        return strcmp($b['meeting_date'], $a['meeting_date']);
    }
    return strcmp($b['meeting_time'], $a['meeting_time']);
});

function getMomPoints($con, $meetingId, $occId = 0) {
    $occCond = $occId > 0 ? "AND occurrence_id=$occId" : "AND (occurrence_id IS NULL OR occurrence_id=0)";
    $mom = mysqli_fetch_assoc(mysqli_query($con,
        "SELECT discussion_points FROM meeting_mom WHERE meeting_id='$meetingId' $occCond ORDER BY id DESC LIMIT 1"
    ));
    return ($mom && $mom['discussion_points']) ? explode('||', $mom['discussion_points']) : [];
}

function hasAttendance($con, $meetingId) {
    $r = mysqli_fetch_assoc(mysqli_query($con,
        "SELECT COUNT(*) as cnt FROM meeting_attendance WHERE meetingId='$meetingId'"
    ));
    return $r && $r['cnt'] > 0;
}

function getOccurrenceCount($con, $meetingId) {
    $r = mysqli_fetch_assoc(mysqli_query($con,
        "SELECT COUNT(*) as cnt FROM meeting_occurrences WHERE meeting_id='$meetingId'"
    ));
    return $r ? (int)$r['cnt'] : 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>SETTribe · Meeting History</title>
    <?php include('../partials/_links.php'); ?>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="../vendors/select2/select2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <style>
        :root { --primary:#2f55ff; --primary-light:#eef1ff; --success:#15803d; --danger:#c62828; --warning:#b45309; --border:#e0e4ef; --radius:8px; }
        .required { color:red; }
        .form-control,.btn { border-radius:6px; }
        .section-title { font-size:.95rem; border-left:4px solid var(--primary); padding-left:10px; margin:16px 0 10px; font-weight:700; color:#343a40; }
        .badge-status { padding:3px 10px; border-radius:20px; font-size:.72rem; font-weight:600; display:inline-block; white-space:nowrap; }
        .badge-upcoming  { background:#e3f2fd; color:#1565c0; }
        .badge-today     { background:#fff8e1; color:#f57f17; }
        .badge-completed { background:#e8f5e9; color:#2e7d32; }
        .badge-cancelled { background:#ffebee; color:#c62828; border:1px solid #ffcdd2; }
        .badge-rescheduled { background:#fff3e0; color:#e65100; }
        .badge-online    { background:#e8f5e9; color:#2e7d32; }
        .badge-offline   { background:#fff3e0; color:#e65100; }
        .badge-recurring { background:#f3e8ff; color:#7c3aed; border:1px solid #d8b4fe; }

        .att-on_leave    { background:#e0f2fe !important; color:#0369a1 !important; border:1.5px solid #7dd3fc !important; font-weight:700; }
        .att-on_tour     { background:#f3e8ff !important; color:#7e22ce !important; border:1.5px solid #d8b4fe !important; font-weight:700; }
        .att-roster_off  { background:#f1f5f9 !important; color:#475569 !important; border:1.5px solid #cbd5e1 !important; font-weight:700; }

        /* ── VIEW SWITCHER STYLES ── */
        .view-btn-group { display:inline-flex; background:#eef2ff; border-radius:8px; padding:3px; border:1.5px solid #dbe2fe; }
        .view-btn { border:none; background:transparent; padding:5px 14px; font-size:.8rem; font-weight:600; color:#64748b; border-radius:6px; cursor:pointer; transition:all .2s ease; display:inline-flex; align-items:center; gap:6px; }
        .view-btn:hover { color:var(--primary); }
        .view-btn.active { background:#ffffff; color:var(--primary); box-shadow:0 2px 6px rgba(47,85,255,.15); font-weight:700; }

        /* ── CARDS GRID STYLES ── */
        .meetings-grid-wrapper { margin-top:14px; }
        .meeting-card { background:#ffffff; border-radius:12px; border:1.5px solid #e2e8f0; box-shadow:0 2px 8px rgba(0,0,0,0.04); transition:all .25s ease; position:relative; overflow:hidden; display:flex; flex-direction:column; height:100%; }
        .meeting-card:hover { transform:translateY(-3px); box-shadow:0 10px 24px rgba(47,85,255,0.08); border-color:#cbd5e1; }
        
        .meeting-card::before { content:''; position:absolute; left:0; top:0; bottom:0; width:4px; background:#cbd5e1; }
        .meeting-card.accent-upcoming::before  { background:#2563eb; }
        .meeting-card.accent-today::before     { background:#d97706; }
        .meeting-card.accent-completed::before { background:#16a34a; }
        .meeting-card.accent-cancelled::before { background:#dc2626; }
        .meeting-card.accent-missed::before    { background:#e65100; }
        .meeting-card.accent-recurring::before { background:#7c3aed; }

        .meeting-card-header { padding:14px 16px 10px 18px; display:flex; align-items:flex-start; justify-content:space-between; gap:10px; border-bottom:1px solid #f1f5f9; }
        .meeting-card-title { font-size:.95rem; font-weight:700; color:#1e293b; line-height:1.35; margin-bottom:2px; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
        .meeting-card-body { padding:12px 16px 12px 18px; flex:1; display:flex; flex-direction:column; gap:8px; }
        .card-meta-row { display:flex; align-items:center; gap:8px; flex-wrap:wrap; font-size:.8rem; color:#475569; }
        .card-meta-pill { display:inline-flex; align-items:center; gap:5px; font-size:.74rem; font-weight:600; padding:3px 9px; border-radius:6px; background:#f8fafc; border:1px solid #e2e8f0; color:#475569; }
        .card-meta-pill i { font-size:.7rem; opacity:.8; }
        .meeting-card-footer { padding:10px 16px 12px 18px; background:#fafafa; border-top:1px solid #f1f5f9; display:flex; align-items:center; justify-content:space-between; gap:8px; flex-wrap:wrap; }
        .meeting-card-actions { display:flex; align-items:center; gap:6px; flex-wrap:wrap; margin-left:auto; }

        @media (max-width: 767.98px) {
            .btn-actions { flex-wrap:wrap; }
            .btn-action-sm { min-width:unset; flex:1; }
            .meeting-card-header { padding:12px; }
            .meeting-card-body { padding:12px; }
            .meeting-card-footer { padding:10px 12px; }
        }

        .table-scroll-wrapper { overflow-x:auto; overflow-y:visible; border-radius:var(--radius); border:1px solid var(--border); -webkit-overflow-scrolling:touch; width:100%; }
        .meetings-table { table-layout:auto; width:100%; min-width:1100px; border-collapse:collapse; }
        .meetings-table thead tr th { position:sticky; top:0; z-index:3; background:#f4f6fb; font-size:.73rem; font-weight:700; color:#6c7a9a; border-bottom:2px solid #dde2ef; white-space:nowrap; padding:11px 12px; text-align:left; letter-spacing:.04em; text-transform:uppercase; vertical-align:middle; }
        .meetings-table td { font-size:.855rem; padding:10px 12px; border-bottom:1px solid #f0f2f8; vertical-align:middle; }
        .meetings-table tbody tr:last-child td { border-bottom:none; }
        .meetings-table tbody tr:hover td { background-color:#f7f9ff; }
        .col-num { width:38px; text-align:center; white-space:nowrap; } .col-agenda { min-width:180px; max-width:240px; } .col-datetime { width:105px; white-space:nowrap; }
        .col-branch { width:70px; text-align:center; white-space:nowrap; } .col-mode { width:80px; text-align:center; white-space:nowrap; }
        .col-link { min-width:190px; text-align:center; white-space:nowrap; } .col-members { width:72px; text-align:center; white-space:nowrap; }
        .col-status { width:95px; text-align:center; white-space:nowrap; } .col-actions { width:310px; min-width:310px; }
        th.col-branch,th.col-mode,th.col-members,th.col-status,th.col-num,th.col-link { text-align:center; }
        .btn-join-link { display:inline-flex; align-items:center; gap:4px; background:#e8f5e9; color:#2e7d32; border:1.5px solid #a5d6a7; border-radius:6px; padding:3px 9px; font-size:.72rem; font-weight:600; text-decoration:none; white-space:nowrap; }
        .btn-join-link:hover { background:#2e7d32; color:#fff; border-color:#2e7d32; text-decoration:none; }
        .no-link-text { color:#b0bccf; font-size:.72rem; }
        .agenda-cell { max-width:0; }
        .agenda-text { font-weight:600; color:#1a2340; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; display:block; margin-bottom:2px; }
        .agenda-desc { font-size:.72rem; color:#6c757d; line-height:1.5; word-break:break-word; white-space:normal; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; max-height:calc(1.5em * 2); transition:max-height .25s ease; }
        .agenda-desc.expanded { display:block; max-height:400px; -webkit-line-clamp:unset; }
        .btn-desc-toggle { font-size:.67rem; color:var(--primary); background:none; border:none; padding:0; cursor:pointer; margin-top:2px; display:inline-block; }
        .btn-desc-toggle:hover { text-decoration:underline; }
        .meta-text { font-size:.74rem; color:#8898b8; }
        .btn-actions { display:flex; flex-direction:row; gap:4px; align-items:center; flex-wrap:nowrap; }
        .btn-action-sm { height:30px; display:inline-flex; align-items:center; justify-content:center; gap:3px; font-size:.72rem; padding:0 8px; white-space:nowrap; line-height:1; flex-shrink:0; min-width:0; }
        .btn-action-sm i { flex-shrink:0; font-size:.7rem; }
        .btn-complete { background:#e8f5e9; color:#2e7d32; border:1.5px solid #a5d6a7; }
        .btn-complete:hover { background:#2e7d32; color:#fff; border-color:#2e7d32; }
        .btn-allocate { background:#fff8e1; color:#b45309; border:1.5px solid #ffe082; }
        .btn-allocate:hover { background:#b45309; color:#fff; border-color:#b45309; }

        /* ── SESSION PICKER ── */
        .session-picker-wrap {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            padding: 12px;
            margin-bottom: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            overflow: hidden;
        }
        .session-picker-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; flex-wrap:wrap; gap:8px; }
        .session-picker-label { font-size:.78rem; font-weight:700; color:var(--primary); display:flex; align-items:center; gap:6px; text-transform:uppercase; letter-spacing:.05em; }
        .session-picker-stats { display:flex; gap:10px; flex-wrap:wrap; }
        .session-stat-chip { display:inline-flex; align-items:center; gap:4px; font-size:.72rem; font-weight:600; padding:2px 9px; border-radius:20px; }
        .chip-total   { background:#eef1ff; color:var(--primary); }
        .chip-done    { background:#e8f5e9; color:#2e7d32; }
        .chip-pending { background:#fff3e0; color:#b45309; }
        .session-month-nav { display:flex; align-items:center; gap:8px; margin-bottom:10px; }
        .session-month-nav button { width:28px; height:28px; border:1.5px solid #dde3f8; background:#fff; border-radius:6px; cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:.75rem; color:#6c757d; transition:all .15s; flex-shrink:0; }
        .session-month-nav button:hover { background:var(--primary); color:#fff; border-color:var(--primary); }
        .session-month-nav button:disabled { opacity:.35; cursor:not-allowed; }
        .session-month-title { font-size:.85rem; font-weight:700; color:#2d3a5a; min-width:100px; text-align:center; }
        .session-month-jump { margin-left:auto; display:flex; align-items:center; gap:6px; }
        .session-month-jump select { font-size:.75rem; border:1.5px solid #dde3f8; border-radius:6px; padding:3px 6px; color:#2d3a5a; background:#fff; cursor:pointer; }
        .session-day-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:6px; }
        .session-day-header { text-align:center; font-size:.62rem; font-weight:700; color:#aab; text-transform:uppercase; letter-spacing:.04em; padding:3px 0; }
        .session-day-cell { height:50px; min-height:50px; padding:6px; border-radius:8px; display:flex; align-items:flex-start; justify-content:flex-start; transition:all .2s ease; }
        .session-day-cell.empty { height:50px; min-height:50px; }
        .session-day-cell.has-session { background:#fff; border:1.5px solid #dde3f8; color:#2d3a5a; cursor:pointer; }
        .session-day-cell.has-session:hover { border-color:var(--primary); background:var(--primary-light); color:var(--primary); }
        .session-day-cell.has-session.is-completed { background:#f0fff4; border-color:#a5d6a7; color:#2e7d32; }
        .session-day-cell.has-session.is-active { background:var(--primary); border-color:var(--primary); color:#fff; transform:scale(1.03); box-shadow:0 2px 6px rgba(47,85,255,.20); }
        .session-day-cell.has-session.is-completed.is-active { background:#2e7d32; border-color:#2e7d32; color:#fff; box-shadow:0 3px 10px rgba(46,125,50,.3); }
        .session-day-cell.is-today-marker::after { content:''; position:absolute; bottom:3px; width:4px; height:4px; border-radius:50%; background:currentColor; opacity:.6; }
        .session-day-num { font-size:.85rem; font-weight:700; line-height:1; }
        .session-day-dot { width:5px; height:5px; border-radius:50%; margin-top:2px; background:currentColor; opacity:.5; }
        .session-day-cell.has-session .session-day-dot { opacity:.7; }
        .session-day-cell.is-active .session-day-dot { background:#fff; opacity:.8; }
        .session-day-cell.has-session.is-completed .session-day-dot { background:#2e7d32; }
        .session-selected-bar { display:none; margin-top:12px; background:#fff; border:1.5px solid var(--primary); border-radius:8px; padding:9px 14px; align-items:center; gap:10px; flex-wrap:wrap; }
        .session-selected-bar.visible { display:flex; }
        .session-selected-date { font-size:.85rem; font-weight:700; color:var(--primary); display:flex; align-items:center; gap:6px; }
        .session-selected-status { font-size:.72rem; font-weight:600; padding:2px 10px; border-radius:20px; }
        .session-no-sessions { text-align:center; padding:20px; color:#aab; font-size:.82rem; }
        .att-table { width:100%; border-collapse:collapse; font-size:.85rem; }
        .att-table th { background:#f8f9fa; padding:7px 10px; font-size:.76rem; font-weight:600; color:#6c757d; border-bottom:2px solid #dee2e6; }
        .att-table td { padding:8px 10px; border-bottom:1px solid #f0f0f0; vertical-align:middle; }
        .att-table tr:last-child td { border-bottom:none; }
        .att-badge { display:inline-block; padding:2px 10px; border-radius:20px; font-size:.72rem; font-weight:600; }
        .att-present { background:#e8f5e9; color:#2e7d32; }
        .att-absent  { background:#ffebee; color:#c62828; }
        .att-absent_intimated { background:#fff3e0; color:#b45309; border:1px solid #fde68a; }
        .att-none    { background:#f5f5f5; color:#999; }
        .marked-by-text { font-size:.72rem; color:#6c757d; font-style:italic; }
        .att-btn-group .btn { font-size:.72rem; padding:2px 8px; }
        .att-btn-group .btn.active-present { background:#2e7d32; color:#fff; border-color:#2e7d32; }
        .att-btn-group .btn.active-absent  { background:#c62828; color:#fff; border-color:#c62828; }
        .att-btn-group .btn.active-intimated { background:#d97706; color:#fff; border-color:#d97706; }
        .batch-group-header { background:var(--primary-light); border-left:3px solid var(--primary); padding:6px 12px; margin:10px 0 6px; border-radius:0 6px 6px 0; display:flex; align-items:center; justify-content:space-between; gap:10px; }
        .batch-group-header__title { font-size:.78rem; font-weight:700; color:var(--primary); display:flex; align-items:center; gap:6px; }
        .batch-group-header__actions { display:flex; gap:5px; }
        .btn-mark-all { font-size:.72rem; padding:3px 10px; border-radius:20px; border:none; cursor:pointer; font-weight:600; transition:all .15s; }
        .btn-mark-all-present { background:#e8f5e9; color:#2e7d32; }
        .btn-mark-all-present:hover { background:#2e7d32; color:#fff; }
        .btn-mark-all-intimated { background:#fff3e0; color:#b45309; border:1px solid #fde68a; }
        .btn-mark-all-intimated:hover { background:#d97706; color:#fff; }
        .btn-mark-all-absent  { background:#ffebee; color:#c62828; }
        .btn-mark-all-absent:hover  { background:#c62828; color:#fff; }
        .att-summary-bar { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:12px; padding:10px 14px; background:#f8f9fa; border-radius:var(--radius); border:1px solid var(--border); }
        .att-summary-chip { display:inline-flex; align-items:center; gap:5px; font-size:.78rem; font-weight:600; }
        .att-summary-dot { width:8px; height:8px; border-radius:50%; display:inline-block; }
        .att-readonly-notice { display:inline-flex; align-items:center; gap:6px; background:#fff8e1; border:1px solid #ffe082; border-radius:8px; padding:6px 12px; font-size:.78rem; color:#7c5e00; margin-bottom:10px; }
        .mom-completed-banner { display:flex; align-items:center; gap:8px; background:#e8f5e9; border:1px solid #a5d6a7; border-radius:8px; padding:8px 14px; margin-bottom:14px; font-size:.82rem; color:#2e7d32; font-weight:600; }
        .point-item { display:flex; align-items:center; gap:8px; padding:7px 12px; background:#f8f9fa; border-left:3px solid var(--primary); border-radius:0 6px 6px 0; margin-bottom:6px; font-size:.85rem; position:relative; }
        .point-num  { color:#aaa; font-size:.72rem; min-width:16px; }
        .point-text { flex:1; color:#495057; }
        .point-text-input { flex:1; padding:4px 8px; border:1px solid var(--primary); border-radius:4px; font-size:.85rem; }
        .point-actions { display:flex; gap:4px; }
        .btn-edit-point,.btn-del-point,.btn-save-point,.btn-cancel-point { border:none; background:none; cursor:pointer; padding:2px 6px; font-size:.8rem; opacity:.6; transition:opacity .15s; border-radius:4px; }
        .btn-edit-point { color:#0d6efd; } .btn-save-point { color:#2e7d32; } .btn-cancel-point { color:#6c757d; } .btn-del-point { color:#dc3545; }
        .btn-edit-point:hover,.btn-del-point:hover,.btn-save-point:hover,.btn-cancel-point:hover { opacity:1; background:rgba(0,0,0,.05); }
        .add-point-row { display:flex; gap:8px; margin-top:8px; }
        .add-point-row input { flex:1; }
        .radio-pill-group { display:flex; gap:8px; flex-wrap:wrap; }
        .radio-pill { position:relative; }
        .radio-pill input[type="radio"] { position:absolute; opacity:0; width:0; height:0; }
        .radio-pill label { display:inline-flex; align-items:center; gap:5px; padding:5px 14px; border-radius:6px; border:1px solid #ced4da; background:#fff; font-size:.82rem; color:#6c757d; cursor:pointer; margin:0; height:38px; transition:border-color .15s,background .15s,color .15s; }
        .radio-pill label:hover { border-color:var(--primary); color:var(--primary); }
        .radio-pill input:checked + label { border-color:var(--primary); background:var(--primary-light); color:var(--primary); font-weight:600; }
        .radio-pill.br-pune   input:checked + label { border-color:#0891b2; background:#e0f7fa; color:#0891b2; }
        .radio-pill.br-kinwat input:checked + label { border-color:#d97706; background:#fef3c7; color:#b45309; }
        .modal-body { max-height:70vh; overflow-y:auto; }
        #editLinkGroup { overflow:hidden; transition:max-height .2s, opacity .2s; }
        #editLinkGroup.hidden { max-height:0 !important; opacity:0; pointer-events:none; }
        .empty-state { text-align:center; padding:40px 20px; color:#aaa; }
        .empty-state i { font-size:2.5rem; display:block; margin-bottom:10px; }
        .btn-edit-disabled { opacity:.45; cursor:not-allowed; pointer-events:none; }
        .reschedule-info-box { background:#fff8e1; border:1px solid #ffe082; border-radius:8px; padding:10px 14px; margin-bottom:16px; font-size:.83rem; color:#6d4c00; display:flex; align-items:flex-start; gap:8px; }
        .reschedule-datetime-row { display:flex; gap:12px; margin-bottom:14px; }
        .reschedule-datetime-row > div { flex:1; }
        .select2-container--default .select2-selection--multiple { border:1px solid #ced4da !important; border-radius:6px !important; min-height:38px !important; font-size:.875rem !important; }
        .select2-container--default .select2-selection--multiple .select2-selection__choice { background:var(--primary) !important; border:none !important; color:#fff !important; border-radius:4px !important; font-size:.78rem !important; }
        .select2-container { width:100% !important; }
        .select2-dropdown { border-radius:6px !important; }
        .dataTables_wrapper .dataTables_filter input { border:1px solid var(--border); border-radius:6px; padding:4px 10px; font-size:.82rem; outline:none; margin-left:6px; }
        .dataTables_wrapper .dataTables_filter input:focus { border-color:var(--primary); box-shadow:0 0 0 2px rgba(47,85,255,.12); }
        .dataTables_wrapper .dataTables_length select { border:1px solid var(--border); border-radius:6px; padding:3px 8px; font-size:.82rem; }
        .dataTables_wrapper .dataTables_length,.dataTables_wrapper .dataTables_filter { margin-bottom:10px; font-size:.82rem; color:#6c757d; }
        .dataTables_wrapper .dataTables_paginate .paginate_button { border-radius:6px !important; font-size:.78rem; padding:3px 9px !important; }
        .dataTables_wrapper .dataTables_paginate .paginate_button.current,.dataTables_wrapper .dataTables_paginate .paginate_button.current:hover { background:var(--primary) !important; border-color:var(--primary) !important; color:#fff !important; }
        .dataTables_wrapper .dataTables_paginate .paginate_button:hover { background:var(--primary-light) !important; border-color:var(--primary) !important; color:var(--primary) !important; }
        .dataTables_wrapper .dataTables_info { font-size:.78rem; color:#8898b8; padding-top:8px; }

        /* ── EDIT MODAL RECURRENCE & TIME SLOTS STYLING ── */
        .recurrence-toggle-wrap { display:flex; align-items:center; gap:14px; background:#f8fafc; border:1.5px solid #e2e8f0; border-radius:12px; padding:12px 18px; cursor:pointer; transition:all .2s ease; }
        .recurrence-toggle-wrap:hover { border-color:#cbd5e1; background:#f1f5f9; }
        .recurrence-toggle-wrap.active { background:#eef2ff; border-color:#a5b4fc; }
        .toggle-switch { position:relative; width:46px; height:24px; display:inline-block; margin:0; cursor:pointer; flex-shrink:0; vertical-align:middle; }
        .toggle-switch input { opacity:0; width:0; height:0; }
        .toggle-slider { position:absolute; inset:0; background:#cbd5e1; border-radius:24px; transition:.25s ease; }
        .toggle-slider:before { content:''; position:absolute; height:18px; width:18px; left:3px; bottom:3px; background:#fff; border-radius:50%; transition:.25s ease; box-shadow:0 1px 4px rgba(0,0,0,.2); }
        .toggle-switch input:checked + .toggle-slider { background:var(--primary); }
        .toggle-switch input:checked + .toggle-slider:before { transform:translateX(22px); }

        .recurrence-body { display:none; }
        .recurrence-body.open { display:block; animation:fadeInUp .2s ease forwards; }

        .freq-pill-group { display:flex; gap:10px; flex-wrap:wrap; margin-top:6px; }
        .freq-pill { position:relative; }
        .freq-pill input[type="radio"] { position:absolute; opacity:0; width:0; height:0; }
        .freq-pill label { display:inline-flex; align-items:center; gap:6px; padding:7px 16px; border-radius:8px; border:1.5px solid #d1d5db; background:#fff; font-size:.83rem; font-weight:600; color:#4b5563; cursor:pointer; margin:0; transition:all .15s ease; }
        .freq-pill label:hover { border-color:var(--primary); color:var(--primary); }
        .freq-pill input:checked + label { border-color:var(--primary); background:var(--primary-light); color:var(--primary); box-shadow:0 0 0 3px rgba(47,85,255,.12); }

        .day-check-group { display:flex; gap:6px; flex-wrap:wrap; margin-top:6px; }
        .day-check { position:relative; }
        .day-check input[type="checkbox"] { position:absolute; opacity:0; width:0; height:0; }
        .day-check label { display:inline-flex; align-items:center; justify-content:center; min-width:44px; height:36px; padding:0 10px; border-radius:8px; border:1.5px solid #d1d5db; background:#fff; font-size:.8rem; font-weight:600; color:#4b5563; cursor:pointer; margin:0; transition:all .15s ease; }
        .day-check label:hover { border-color:var(--primary); color:var(--primary); }
        .day-check input:checked + label { border-color:var(--primary); background:var(--primary); color:#fff; }

        .time-slot-list { display:flex; flex-direction:column; gap:8px; margin-top:8px; margin-bottom:12px; }
        .time-slot-item { display:inline-flex !important; flex-direction:row !important; align-items:center !important; gap:8px !important; background:#ffffff !important; border:1.5px solid #e2e8f0 !important; border-radius:10px !important; padding:8px 14px !important; width:fit-content !important; max-width:100% !important; box-shadow:0 1px 3px rgba(0,0,0,.04); }
        .slot-label { font-size:.8rem; font-weight:700; color:#475569; white-space:nowrap; min-width:48px; }
        .select-hour, .select-min, .select-ampm { width:auto !important; display:inline-block !important; padding:4px 8px !important; font-size:.83rem !important; font-weight:600 !important; height:34px !important; border-radius:6px !important; border:1px solid #cbd5e1 !important; background-color:#f8fafc !important; color:#1e293b !important; }
        .select-hour:focus, .select-min:focus, .select-ampm:focus { border-color:var(--primary) !important; background-color:#fff !important; outline:none; }
        .time-sep { font-weight:700; color:#64748b; font-size:1rem; }
        .btn-rm-slot { background:none; border:none; color:#ef4444; font-size:1.05rem; cursor:pointer; padding:2px 6px; display:inline-flex; align-items:center; opacity:.75; transition:all .15s; margin-left:4px; }
        .btn-rm-slot:hover { opacity:1; color:#dc2626; transform:scale(1.1); }
        .btn-add-slot { display:inline-flex; align-items:center; gap:6px; background:#f1f5f9; color:#334155; border:1.5px solid #cbd5e1; border-radius:8px; padding:7px 16px; font-size:.82rem; font-weight:600; cursor:pointer; transition:all .15s ease; margin-top:4px; }
        .btn-add-slot:hover { background:var(--primary); color:#ffffff; border-color:var(--primary); }
        @keyframes fadeInUp { from { opacity:0; transform:translateY(12px); } to { opacity:1; transform:translateY(0); } }
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
                                <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                                    <h4 class="card-title mb-0">
                                        <i class="fas fa-history mr-2"></i> Meeting History
                                        <?php if ($isIntern): ?>
                                        <span style="font-size:.75rem;font-weight:400;color:#8898b8;margin-left:8px;">(Meetings you're invited to)</span>
                                        <?php endif; ?>
                                    </h4>
                                    <div style="display:flex; gap:8px; align-items:center;" class="flex-wrap">
                                        <!-- View Toggle Buttons -->
                                        <div class="view-btn-group">
                                            <button type="button" class="view-btn active" id="btnViewGrid" onclick="switchView('grid')">
                                                <i class="fas fa-th-large"></i> Cards
                                            </button>
                                            <button type="button" class="view-btn" id="btnViewTable" onclick="switchView('table')">
                                                <i class="fas fa-list"></i> Table
                                            </button>
                                        </div>
                                        <?php if ($isHR || $isAllowedCreator): ?>
                                        <a href="meetingComparisonReport.php" class="btn btn-outline-info btn-sm" style="border-radius:6px; font-weight:600;"><i class="fas fa-layer-group mr-1"></i> Recurring Report</a>
                                        <a href="meetingDashboard.php" class="btn btn-outline-primary btn-sm" style="border-radius:6px; font-weight:600;"><i class="fas fa-chart-line mr-1"></i> Analytics</a>
                                        <?php endif; ?>
                                        <?php if ($isAllowedCreator): ?>
                                        <a href="createMeeting.php" class="btn btn-primary btn-sm" style="border-radius:6px; font-weight:600;"><i class="fas fa-plus mr-1"></i> New Meeting</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <p><strong>Logged in as:</strong> <span style="color:var(--primary);"><?= $userName ?></span></p>

                                      <?php if (empty($meetings)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-calendar-times"></i>
                                    No meetings found.
                                    <?php if ($isAllowedCreator): ?>
                                    <br><a href="createMeeting.php">Schedule your first meeting →</a>
                                    <?php endif; ?>
                                </div>
                                <?php else: ?>

                                <!-- ── FILTER & SEARCH BAR ── -->
                                <div class="card mb-3 border shadow-none" style="background:#f8fafc; border-radius:10px;">
                                    <div class="card-body p-3">
                                        <div class="row align-items-end g-2" id="filterBarRow">
                                             <div class="col-xl-3 col-lg-3 col-md-6 col-12 mb-2 mb-lg-0">
                                                 <label class="form-label text-muted mb-1" style="font-size:.73rem; font-weight:700;"><i class="fas fa-search me-1"></i> Search</label>
                                                 <div class="filter-search-box">
                                                     <i class="fas fa-search"></i>
                                                     <input type="text" id="filterSearchInput" class="form-control form-control-sm" placeholder="Search agenda, description, creator...">
                                                 </div>
                                             </div>
                                             <div class="col-xl-2 col-lg-2 col-md-3 col-6 mb-2 mb-lg-0">
                                                 <label class="form-label text-muted mb-1" style="font-size:.73rem; font-weight:700;"><i class="fas fa-filter me-1"></i> Status</label>
                                                 <select id="filterStatus" class="form-control form-control-sm" style="border-radius:6px; font-size:.82rem;">
                                                     <option value="">All Statuses</option>
                                                     <option value="upcoming">Upcoming</option>
                                                     <option value="today">Today</option>
                                                     <option value="completed">Completed</option>
                                                     <option value="missed">Missed</option>
                                                     <option value="cancelled">Cancelled</option>
                                                     <option value="recurring">Recurring</option>
                                                 </select>
                                             </div>
                                             <div class="col-xl-2 col-lg-2 col-md-3 col-6 mb-2 mb-lg-0">
                                                 <label class="form-label text-muted mb-1" style="font-size:.73rem; font-weight:700;"><i class="fas fa-building me-1"></i> Branch</label>
                                                 <select id="filterBranch" class="form-control form-control-sm" style="border-radius:6px; font-size:.82rem;">
                                                     <option value="">All Branches</option>
                                                     <option value="pune">Pune</option>
                                                     <option value="kinwat">Kinwat</option>
                                                     <option value="all">All</option>
                                                 </select>
                                             </div>
                                             <div class="col-xl-1 col-lg-1 col-md-3 col-6 mb-2 mb-lg-0">
                                                 <label class="form-label text-muted mb-1" style="font-size:.73rem; font-weight:700;"><i class="fas fa-video me-1"></i> Mode</label>
                                                 <select id="filterMode" class="form-control form-control-sm" style="border-radius:6px; font-size:.82rem;">
                                                     <option value="">All</option>
                                                     <option value="online">Online</option>
                                                     <option value="offline">Offline</option>
                                                 </select>
                                             </div>
                                             <div class="col-xl-1 col-lg-1 col-md-3 col-6 mb-2 mb-lg-0">
                                                 <label class="form-label text-muted mb-1" style="font-size:.73rem; font-weight:700;"><i class="fas fa-redo me-1"></i> Type</label>
                                                 <select id="filterType" class="form-control form-control-sm" style="border-radius:6px; font-size:.82rem;">
                                                     <option value="">All</option>
                                                     <option value="one-time">One-time</option>
                                                     <option value="recurring">Recurring</option>
                                                 </select>
                                             </div>
                                             <div class="col-xl-2 col-lg-2 col-md-8 col-8">
                                                 <label class="form-label text-muted mb-1" style="font-size:.73rem; font-weight:700;"><i class="fas fa-calendar-alt me-1"></i> Date Range</label>
                                                 <div class="d-flex align-items-center gap-1">
                                                     <input type="date" id="filterFromDate" class="form-control form-control-sm px-1" style="border-radius:6px; font-size:.72rem; min-width:0; flex:1;" title="From Date">
                                                     <span class="text-muted" style="font-size:.7rem;">-</span>
                                                     <input type="date" id="filterToDate" class="form-control form-control-sm px-1" style="border-radius:6px; font-size:.72rem; min-width:0; flex:1;" title="To Date">
                                                 </div>
                                             </div>
                                             <div class="col-xl-1 col-lg-1 col-md-4 col-4 d-flex align-items-end">
                                                 <button type="button" id="btnResetFilters" class="btn btn-sm btn-outline-secondary w-100" style="font-weight:600; font-size:.75rem; height:31px; border-radius:6px;" title="Reset all filters">
                                                     <i class="fas fa-undo me-1"></i> Reset
                                                 </button>
                                             </div>
                                        </div>
                                    </div>
                                </div>

                                <?php
                                $today = date('Y-m-d');
                                $nowDT = date('Y-m-d H:i:s');
                                ?>

                                <!-- ── CARDS GRID VIEW ── -->
                                <div class="row meetings-grid-wrapper" id="meetingsCardGrid">
                                    <?php foreach ($meetings as $i => $m):
                                        $mid     = $m['id'];
                                        $mDate   = $m['meeting_date'];
                                        $mTime   = $m['meeting_time'];
                                        $mDT     = $mDate . ' ' . $mTime;
                                        $mStatus = $m['status'] ?? null;
                                        $mLink   = $m['meeting_link'] ?? '';
                                        $isRecurring = !empty($m['recurrence_type']);
                                        $occCount = $isRecurring ? getOccurrenceCount($con, $mid) : 0;

                                        $rawPIds = array_filter(array_map('trim', explode(',', $m['participants'] ?? '')));
                                        $pIds = array_values(array_filter($rawPIds, function($id) use ($activeUserIds) {
                                            return isset($activeUserIds[$id]);
                                        }));
                                        $pCount = count($pIds);
                                        $points = getMomPoints($con, $mid);
                                        $hasAtt = hasAttendance($con, $mid);

                                        $isCreator      = ($m['creator_id'] == $userId || (!empty($userEmpId) && $m['creator_id'] == $userEmpId) || (!empty($userInternId) && $m['creator_id'] == $userInternId));
                                        $coHostIds      = array_filter(array_map('trim', explode(',', $m['co_hosts'] ?? '')));
                                        $isCoHost       = in_array((string)$userId, $coHostIds) || (!empty($userEmpId) && in_array((string)$userEmpId, $coHostIds)) || (!empty($userInternId) && in_array((string)$userInternId, $coHostIds));
                                        $isHostOrCoHost  = ($isCreator || $isCoHost || $isHR);

                                        $canComplete   = $isHostOrCoHost && $mStatus !== 'completed' && $mStatus !== 'cancelled';
                                        $isCompleted   = ($mStatus === 'completed');
                                        $isParticipant = in_array($userId, $pIds) || $isCoHost;
                                        $canSeeMom      = $isHostOrCoHost || $isHR || $isParticipant;
                                        $canMarkAtt     = $isHostOrCoHost;

                                        if ($isCompleted) {
                                            $sClass = 'badge-completed'; $sLabel = 'Completed'; $accentClass = 'accent-completed';
                                            $canEdit = false; $canReschedule = false; $canCancel = false; $canMarkAtt = false;
                                        } elseif ($mStatus === 'cancelled') {
                                            $sClass = 'badge-cancelled'; $sLabel = 'Cancelled'; $accentClass = 'accent-cancelled';
                                            $canEdit = false; $canReschedule = false; $canCancel = false; $canMarkAtt = false;
                                        } else {
                                            $canEdit = $isHostOrCoHost;
                                            $canCancel = $isHostOrCoHost;
                                            if ($isRecurring) {
                                                $sClass = 'badge-recurring'; $sLabel = 'Recurring'; $accentClass = 'accent-recurring';
                                                $canReschedule = false;
                                            } elseif ($mDate > $today) {
                                                $sClass = 'badge-upcoming'; $sLabel = 'Upcoming'; $accentClass = 'accent-upcoming';
                                                $canReschedule = false;
                                            } elseif ($mDate === $today) {
                                                $sClass = 'badge-today'; $sLabel = 'Today'; $accentClass = 'accent-today';
                                                $canReschedule = ($mDT < $nowDT && !$hasAtt && count($points) === 0 && $isHostOrCoHost);
                                            } else {
                                                if (!$hasAtt && count($points) === 0) {
                                                    $sClass = 'badge-rescheduled'; $sLabel = 'Missed'; $accentClass = 'accent-missed';
                                                    $canReschedule = $isHostOrCoHost;
                                                } else {
                                                    $sClass = 'badge-completed'; $sLabel = 'Completed'; $accentClass = 'accent-completed';
                                                    $canReschedule = false;
                                                    $canCancel = false;
                                                }
                                            }
                                        }

                                        $mode     = strtolower($m['mode']);
                                        $descSafe = htmlspecialchars($m['description'] ?? '');

                                        $timeSlotsArr = !empty($m['time_slots']) ? explode(',', $m['time_slots']) : [$mTime];
                                        $timeSlotsFormatted = [];
                                        foreach ($timeSlotsArr as $tsItem) {
                                            $tsItem = trim($tsItem);
                                            if (!empty($tsItem)) {
                                                $timeSlotsFormatted[] = date('h:i A', strtotime($tsItem));
                                            }
                                        }
                                        $mTimeFormatted = implode(', ', array_unique($timeSlotsFormatted));
                                    ?>
                                    <div class="col-12 col-md-6 col-lg-4 mb-3 meeting-card-item"
                                         id="meetingcard-<?= $mid ?>"
                                         data-agenda="<?= htmlspecialchars(strtolower($m['agenda'])) ?>"
                                         data-status="<?= htmlspecialchars(strtolower($sLabel)) ?>"
                                         data-branch="<?= htmlspecialchars(strtolower(trim($m['branch'] ?? ''))) ?>"
                                         data-mode="<?= htmlspecialchars(strtolower(trim($m['mode'] ?? ''))) ?>"
                                         data-type="<?= $isRecurring ? 'recurring' : 'one-time' ?>"
                                         data-date="<?= $mDate ?>">
                                        <div class="meeting-card <?= $accentClass ?>">
                                            <div class="meeting-card-header">
                                                <div>
                                                    <h5 class="meeting-card-title"><?= htmlspecialchars($m['agenda']) ?></h5>
                                                    <?php if ($isRecurring): ?>
                                                    <span class="badge-status badge-recurring mt-1">
                                                        <i class="fas fa-redo mr-1" style="font-size:.6rem;"></i> <?= ucfirst($m['recurrence_type']) ?>
                                                        <?php if ($occCount > 0): ?> • <?= $occCount ?> sessions<?php endif; ?>
                                                    </span>
                                                    <?php endif; ?>
                                                </div>
                                                <span class="badge-status <?= $sClass ?>" id="mcardstatus-<?= $mid ?>"><?= $sLabel ?></span>
                                            </div>

                                            <div class="meeting-card-body">
                                                <?php if (!empty($m['description'])): ?>
                                                <div class="agenda-desc text-muted mb-1" id="cdesc-<?= $mid ?>"><?= $descSafe ?></div>
                                                <?php endif; ?>

                                                <div class="card-meta-row">
                                                    <span class="card-meta-pill">
                                                        <i class="fas fa-calendar-alt text-primary"></i> <?= date('d M Y', strtotime($mDate)) ?>
                                                    </span>
                                                    <span class="card-meta-pill">
                                                        <i class="fas fa-clock text-info"></i> <?= htmlspecialchars($mTimeFormatted) ?>
                                                    </span>
                                                </div>

                                                <div class="card-meta-row mt-1">
                                                    <span class="card-meta-pill">
                                                        <i class="fas fa-building text-warning"></i> <?= htmlspecialchars($m['branch'] ?? 'All') ?>
                                                    </span>
                                                    <span class="badge-status badge-<?= $mode ?>" style="font-size:.72rem;">
                                                        <i class="fas <?= $mode==='online'?'fa-wifi':'fa-map-marker-alt' ?> mr-1"></i> <?= ucfirst($mode) ?>
                                                    </span>
                                                    <span class="card-meta-pill ml-auto">
                                                        <i class="fas fa-users text-secondary"></i> <?= $pCount ?> mem
                                                    </span>
                                                </div>

                                                <?php if (!empty($m['creator_name'])): ?>
                                                <div class="text-muted mt-2" style="font-size:.73rem; font-style:italic;">
                                                    <i class="fas fa-user-circle mr-1"></i> Host: <?= htmlspecialchars($m['creator_name']) ?>
                                                    <?php if ($isCoHost): ?>
                                                    <span class="badge badge-info ml-1" style="font-size:.65rem; font-style:normal;">👑 You are Co-Host</span>
                                                    <?php endif; ?>
                                                </div>
                                                <?php endif; ?>

                                                <?php if (!empty($mLink)): ?>
                                                <div class="mt-2 p-2 rounded" style="background:#f8fafc; border:1px solid #e2e8f0; display:flex; align-items:center; justify-content:space-between; gap:6px;">
                                                    <span class="text-truncate" style="font-size:.75rem; color:#475569; max-width:65%;" title="<?= htmlspecialchars($mLink) ?>">
                                                        <i class="fas fa-link text-primary mr-1"></i>
                                                        <a href="<?= htmlspecialchars($mLink) ?>" target="_blank" rel="noopener noreferrer" style="color:#0284c7; text-decoration:underline; font-weight:600;">
                                                            <?= htmlspecialchars($mLink) ?>
                                                        </a>
                                                    </span>
                                                    <a href="<?= htmlspecialchars($mLink) ?>" target="_blank" rel="noopener noreferrer" class="btn-join-link">
                                                        <i class="fas fa-video"></i> Join
                                                    </a>
                                                </div>
                                                <?php endif; ?>
                                            </div>

                                            <div class="meeting-card-footer">
                                                <div class="meeting-card-actions">
                                                    <?php if ($mStatus === 'cancelled'): ?>
                                                        <button class="btn btn-outline-danger btn-sm btn-action-sm btn-edit-disabled" disabled><i class="fas fa-ban"></i> Cancelled</button>
                                                    <?php elseif ($isCompleted): ?>
                                                        <button class="btn btn-outline-secondary btn-sm btn-action-sm btn-edit-disabled" disabled><i class="fas fa-lock"></i> Locked</button>
                                                    <?php else: ?>
                                                        <?php if ($canReschedule): ?>
                                                            <button class="btn btn-warning btn-sm btn-action-sm"
                                                                onclick='openReschedule(<?= htmlspecialchars(json_encode(["id"=>$mid,"agenda"=>$m["agenda"],"meeting_date"=>$m["meeting_date"],"meeting_time"=>$m["meeting_time"]]), ENT_QUOTES, "UTF-8") ?>)'>
                                                                <i class="fas fa-calendar-alt"></i> Reschedule
                                                            </button>
                                                        <?php elseif ($canEdit): ?>
                                                            <button class="btn btn-outline-secondary btn-sm btn-action-sm"
                                                                onclick='openEdit(<?= htmlspecialchars(json_encode(["id"=>$mid,"agenda"=>$m["agenda"],"description"=>$m["description"]??"","meeting_date"=>$m["meeting_date"],"meeting_time"=>$m["meeting_time"],"mode"=>$m["mode"],"meeting_link"=>$m["meeting_link"]??"","branch"=>$m["branch"]??"","participants"=>$m["participants"]??"","co_hosts"=>$m["co_hosts"]??"","recurrence_type"=>$m["recurrence_type"]??"","recurrence_days"=>$m["recurrence_days"]??"","recurrence_end"=>$m["recurrence_end"]??"","time_slots"=>$m["time_slots"]??""]), ENT_QUOTES, "UTF-8") ?>)'>
                                                                <i class="fas fa-edit"></i> Edit
                                                            </button>
                                                        <?php endif; ?>

                                                        <?php if ($canCancel ?? false): ?>
                                                            <button class="btn btn-outline-danger btn-sm btn-action-sm"
                                                                id="btn-cardcancel-<?= $mid ?>"
                                                                onclick='cancelMeeting(<?= $mid ?>, <?= htmlspecialchars(json_encode($m["agenda"]), ENT_QUOTES, "UTF-8") ?>)'>
                                                                <i class="fas fa-ban"></i> Cancel
                                                            </button>
                                                        <?php endif; ?>
                                                    <?php endif; ?>

                                                    <?php
                                                    $cardHostName = !empty($m['creator_name']) ? $m['creator_name'] : ($userNameMap[$m['creator_id']] ?? 'Unknown');
                                                    $cardCoHostNames = [];
                                                    if (!empty($m['co_hosts'])) {
                                                        $chIds = array_filter(array_map('trim', explode(',', $m['co_hosts'])));
                                                        foreach ($chIds as $chId) {
                                                            $cardCoHostNames[] = $userNameMap[$chId] ?? $chId;
                                                        }
                                                    }
                                                    $cardCoHostStr = !empty($cardCoHostNames) ? implode(', ', $cardCoHostNames) : 'None';

                                                    $cardPartNames = [];
                                                    foreach ($pIds as $pid) {
                                                        if (!empty($userNameMap[$pid])) {
                                                            $cardPartNames[] = $userNameMap[$pid];
                                                        }
                                                    }
                                                    $cardPartStr = !empty($cardPartNames) ? implode(', ', array_unique($cardPartNames)) : '';

                                                    $isHostOrCoHostOnly = ($isCreator || $isCoHost || $isHR);
                                                    $waCardData = [
                                                        'agenda'       => $m['agenda'] ?? '',
                                                        'description'  => $m['description'] ?? '',
                                                        'date'         => date('d M Y', strtotime($mDate)),
                                                        'time'         => $mTimeFormatted,
                                                        'mode'         => ucfirst(strtolower($m['mode'] ?? 'online')),
                                                        'branch'       => $m['branch'] ?? 'All',
                                                        'link'         => $mLink,
                                                        'host'         => $cardHostName,
                                                        'cohosts'      => $cardCoHostStr,
                                                        'participants' => $cardPartStr
                                                    ];
                                                    ?>
                                                    <?php if ($isHostOrCoHostOnly): ?>
                                                    <button class="btn btn-sm btn-action-sm" style="background:#25d366; border-color:#25d366; color:#fff; padding:4px 9px;"
                                                        title="Copy WhatsApp invite details"
                                                        onclick='copyWhatsAppInvite(<?= htmlspecialchars(json_encode($waCardData), ENT_QUOTES, "UTF-8") ?>)'>
                                                        <i class="fab fa-whatsapp" style="font-size:0.95rem;"></i>
                                                    </button>
                                                    <?php endif; ?>

                                                    <?php if ($canSeeMom): ?>
                                                    <button class="btn btn-primary btn-sm btn-action-sm"
                                                        onclick='openMom(<?= $mid ?>,<?= htmlspecialchars(json_encode($m["agenda"]), ENT_QUOTES, "UTF-8") ?>,<?= htmlspecialchars(json_encode($m["participants"] ?? ""), ENT_QUOTES, "UTF-8") ?>,<?= htmlspecialchars(json_encode($points), ENT_QUOTES, "UTF-8") ?>,<?= $canMarkAtt ? 'true' : 'false' ?>,<?= $isCompleted ? 'true' : 'false' ?>,<?= htmlspecialchars(json_encode($mLink), ENT_QUOTES, "UTF-8") ?>,<?= $canComplete ? 'true' : 'false' ?>,<?= $isRecurring ? 'true' : 'false' ?>,<?= htmlspecialchars(json_encode($m["meeting_date"]), ENT_QUOTES, "UTF-8") ?>,<?= htmlspecialchars(json_encode($m["meeting_time"]), ENT_QUOTES, "UTF-8") ?>,<?= htmlspecialchars(json_encode($mStatus ?? ""), ENT_QUOTES, "UTF-8") ?>,<?= htmlspecialchars(json_encode($cardHostName), ENT_QUOTES, "UTF-8") ?>,<?= htmlspecialchars(json_encode($cardCoHostStr), ENT_QUOTES, "UTF-8") ?>,<?= $isHostOrCoHostOnly ? 'true' : 'false' ?>,<?= htmlspecialchars(json_encode($waCardData), ENT_QUOTES, "UTF-8") ?>)'>
                                                        <i class="fas fa-clipboard-list"></i> MOM
                                                    </button>
                                                    <?php endif; ?>

                                                    <?php if ($isRecurring && ($isHR || $isAllowedCreator)): ?>
                                                    <a href="meetingComparisonReport.php?meeting_id=<?= $mid ?>" class="btn btn-info btn-sm btn-action-sm" title="Compare Session Logs">
                                                        <i class="fas fa-layer-group"></i> Compare
                                                    </a>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>

                                <!-- ── TABLE VIEW ── -->
                                <div class="table-scroll-wrapper" id="meetingsTableWrapper" style="display:none;">
                                <table class="table meetings-table mb-0" id="meetingsDataTable">
                                    <thead>
                                        <tr>
                                            <th class="col-num">#</th>
                                            <th class="col-agenda">Agenda</th>
                                            <th class="col-datetime">Date &amp; Time</th>
                                            <th class="col-branch">Branch</th>
                                            <th class="col-mode">Mode</th>
                                            <th class="col-link">Link</th>
                                            <th class="col-members">Members</th>
                                            <th class="col-status">Status</th>
                                            <th class="col-actions">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php
                                    foreach ($meetings as $i => $m):
                                        $mid     = $m['id'];
                                        $mDate   = $m['meeting_date'];
                                        $mTime   = $m['meeting_time'];
                                        $mDT     = $mDate . ' ' . $mTime;
                                        $mStatus = $m['status'] ?? null;
                                        $mLink   = $m['meeting_link'] ?? '';
                                        $isRecurring = !empty($m['recurrence_type']);
                                        $occCount = $isRecurring ? getOccurrenceCount($con, $mid) : 0;

                                        $rawPIds = array_filter(array_map('trim', explode(',', $m['participants'] ?? '')));
                                        $pIds = array_values(array_filter($rawPIds, function($id) use ($activeUserIds) {
                                            return isset($activeUserIds[$id]);
                                        }));
                                        $pCount = count($pIds);
                                        $points = getMomPoints($con, $mid);
                                        $hasAtt = hasAttendance($con, $mid);

                                        $isCreator      = ($m['creator_id'] == $userId || (!empty($userEmpId) && $m['creator_id'] == $userEmpId) || (!empty($userInternId) && $m['creator_id'] == $userInternId));
                                        $coHostIds      = array_filter(array_map('trim', explode(',', $m['co_hosts'] ?? '')));
                                        $isCoHost       = in_array((string)$userId, $coHostIds) || (!empty($userEmpId) && in_array((string)$userEmpId, $coHostIds)) || (!empty($userInternId) && in_array((string)$userInternId, $coHostIds));
                                        $isHostOrCoHost  = ($isCreator || $isCoHost || $isHR);

                                        $canComplete   = $isHostOrCoHost && $mStatus !== 'completed' && $mStatus !== 'cancelled';
                                        $isCompleted   = ($mStatus === 'completed');
                                        $isParticipant = in_array($userId, $pIds) || $isCoHost;
                                        $canSeeMom      = $isHostOrCoHost || $isHR || $isParticipant;
                                        $canMarkAtt     = $isHostOrCoHost;

                                        if ($isCompleted) {
                                            $sClass = 'badge-completed'; $sLabel = 'Completed';
                                            $canEdit = false; $canReschedule = false; $canCancel = false; $canMarkAtt = false;
                                        } elseif ($mStatus === 'cancelled') {
                                            $sClass = 'badge-cancelled'; $sLabel = 'Cancelled';
                                            $canEdit = false; $canReschedule = false; $canCancel = false; $canMarkAtt = false;
                                        } else {
                                            $canEdit = $isHostOrCoHost;
                                            $canCancel = $isHostOrCoHost;
                                            if ($isRecurring) {
                                                $sClass = 'badge-recurring'; $sLabel = 'Recurring';
                                                $canReschedule = false;
                                            } elseif ($mDate > $today) {
                                                $sClass = 'badge-upcoming'; $sLabel = 'Upcoming';
                                                $canReschedule = false;
                                            } elseif ($mDate === $today) {
                                                $sClass = 'badge-today'; $sLabel = 'Today';
                                                $canReschedule = ($mDT < $nowDT && !$hasAtt && count($points) === 0 && $isHostOrCoHost);
                                            } else {
                                                if (!$hasAtt && count($points) === 0) {
                                                    $sClass = 'badge-rescheduled'; $sLabel = 'Missed';
                                                    $canReschedule = $isHostOrCoHost;
                                                } else {
                                                    $sClass = 'badge-completed'; $sLabel = 'Completed';
                                                    $canReschedule = false;
                                                    $canCancel = false;
                                                }
                                            }
                                        }

                                        $mode     = strtolower($m['mode']);
                                        $descSafe = htmlspecialchars($m['description'] ?? '');

                                        $timeSlotsArr = !empty($m['time_slots']) ? explode(',', $m['time_slots']) : [$mTime];
                                        $timeSlotsFormatted = [];
                                        foreach ($timeSlotsArr as $tsItem) {
                                            $tsItem = trim($tsItem);
                                            if (!empty($tsItem)) {
                                                $timeSlotsFormatted[] = date('h:i A', strtotime($tsItem));
                                            }
                                        }
                                        $mTimeFormatted = implode(', ', array_unique($timeSlotsFormatted));
                                    ?>
                                    <tr id="meetingrow-<?= $mid ?>"
                                        data-status="<?= htmlspecialchars(strtolower($sLabel)) ?>"
                                        data-branch="<?= htmlspecialchars(strtolower(trim($m['branch'] ?? ''))) ?>"
                                        data-mode="<?= htmlspecialchars(strtolower(trim($m['mode'] ?? ''))) ?>"
                                        data-type="<?= $isRecurring ? 'recurring' : 'one-time' ?>"
                                        data-date="<?= $mDate ?>">
                                        <td class="col-num text-muted"><?= $i + 1 ?></td>
                                        <td class="agenda-cell col-agenda">
                                            <span class="agenda-text" title="<?= htmlspecialchars($m['agenda']) ?>"><?= htmlspecialchars($m['agenda']) ?></span>
                                            <?php if ($isRecurring): ?>
                                            <span style="font-size:.68rem; color:#7c3aed; background:#f3e8ff; border-radius:4px; padding:1px 7px; font-weight:700; display:inline-block; margin-top:2px;">
                                                <i class="fas fa-redo" style="font-size:.55rem;"></i>
                                                <?= ucfirst($m['recurrence_type']) ?>
                                                <?php if ($occCount > 0): ?>· <?= $occCount ?> sessions<?php endif; ?>
                                            </span>
                                            <?php endif; ?>
                                            <?php if (!empty($m['description'])): ?>
                                            <div class="agenda-desc" id="adesc-<?= $mid ?>"><?= $descSafe ?></div>
                                            <button class="btn-desc-toggle" id="atog-<?= $mid ?>" onclick="toggleDesc(<?= $mid ?>)">Read more</button>
                                            <?php endif; ?>
                                        </td>
                                        <td class="col-datetime">
                                            <div id="mdate-<?= $mid ?>" style="font-weight:600; color:#2d3a5a; white-space:nowrap;"><?= date('d M Y', strtotime($mDate)) ?></div>
                                            <div class="meta-text" id="mtime-<?= $mid ?>" style="white-space:nowrap;" title="<?= htmlspecialchars($mTimeFormatted) ?>"><?= htmlspecialchars($mTimeFormatted) ?></div>
                                        </td>
                                        <td class="col-branch"><span style="font-size:.82rem; white-space:nowrap;"><?= htmlspecialchars($m['branch'] ?? '—') ?></span></td>
                                        <td class="col-mode"><span class="badge-status badge-<?= $mode ?>"><?= ucfirst($mode) ?></span></td>
                                        <td class="col-link">
                                            <?php if (!empty($mLink)): ?>
                                            <div style="display:inline-flex; align-items:center; gap:6px;">
                                                <a href="<?= htmlspecialchars($mLink) ?>" target="_blank" rel="noopener noreferrer" class="text-truncate" style="max-width:120px; font-size:.75rem; color:#0284c7; text-decoration:underline; font-weight:600;" title="<?= htmlspecialchars($mLink) ?>">
                                                    <?= htmlspecialchars($mLink) ?>
                                                </a>
                                                <a href="<?= htmlspecialchars($mLink) ?>" target="_blank" rel="noopener noreferrer" class="btn-join-link"><i class="fas fa-video"></i> Join</a>
                                            </div>
                                            <?php else: ?><span class="no-link-text">—</span><?php endif; ?>
                                        </td>
                                        <td class="col-members"><span style="font-weight:600; color:#2d3a5a;"><?= $pCount ?></span><span class="meta-text"> mem</span></td>
                                        <td class="col-status"><span class="badge-status <?= $sClass ?>" id="mstatus-<?= $mid ?>"><?= $sLabel ?></span></td>
                                        <td class="col-actions">
                                            <div class="btn-actions">
                                                <?php if ($mStatus === 'cancelled'): ?>
                                                    <button class="btn btn-outline-danger btn-sm btn-action-sm btn-edit-disabled" disabled><i class="fas fa-ban"></i> Cancelled</button>
                                                <?php elseif ($isCompleted): ?>
                                                    <button class="btn btn-outline-secondary btn-sm btn-action-sm btn-edit-disabled" disabled><i class="fas fa-lock"></i> Locked</button>
                                                <?php else: ?>
                                                    <?php if ($canReschedule): ?>
                                                        <button class="btn btn-warning btn-sm btn-action-sm"
                                                            onclick='openReschedule(<?= htmlspecialchars(json_encode(["id"=>$mid,"agenda"=>$m["agenda"],"meeting_date"=>$m["meeting_date"],"meeting_time"=>$m["meeting_time"]]), ENT_QUOTES, "UTF-8") ?>)'>
                                                            <i class="fas fa-calendar-alt"></i> Reschedule
                                                        </button>
                                                    <?php elseif ($canEdit): ?>
                                                        <button class="btn btn-outline-secondary btn-sm btn-action-sm"
                                                            onclick='openEdit(<?= htmlspecialchars(json_encode(["id"=>$mid,"agenda"=>$m["agenda"],"description"=>$m["description"]??"","meeting_date"=>$m["meeting_date"],"meeting_time"=>$m["meeting_time"],"mode"=>$m["mode"],"meeting_link"=>$m["meeting_link"]??"","branch"=>$m["branch"]??"","participants"=>$m["participants"]??"","recurrence_type"=>$m["recurrence_type"]??"","recurrence_days"=>$m["recurrence_days"]??"","recurrence_end"=>$m["recurrence_end"]??"","time_slots"=>$m["time_slots"]??""]), ENT_QUOTES, "UTF-8") ?>)'>
                                                            <i class="fas fa-edit"></i> Edit
                                                        </button>
                                                    <?php else: ?>
                                                        <button class="btn btn-outline-secondary btn-sm btn-action-sm btn-edit-disabled" disabled><i class="fas fa-lock"></i> Locked</button>
                                                    <?php endif; ?>
                                                    <?php if ($canCancel ?? false): ?>
                                                        <button class="btn btn-outline-danger btn-sm btn-action-sm"
                                                            id="btn-cancel-<?= $mid ?>"
                                                            onclick='cancelMeeting(<?= $mid ?>, <?= htmlspecialchars(json_encode($m["agenda"]), ENT_QUOTES, "UTF-8") ?>)'>
                                                            <i class="fas fa-ban"></i> Cancel
                                                        </button>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                                <?php
                                                $tblHostName = !empty($m['creator_name']) ? $m['creator_name'] : ($userNameMap[$m['creator_id']] ?? 'Unknown');
                                                $tblCoHostNames = [];
                                                if (!empty($m['co_hosts'])) {
                                                    $chIds = array_filter(array_map('trim', explode(',', $m['co_hosts'])));
                                                    foreach ($chIds as $chId) {
                                                        $tblCoHostNames[] = $userNameMap[$chId] ?? $chId;
                                                    }
                                                }
                                                $tblCoHostStr = !empty($tblCoHostNames) ? implode(', ', $tblCoHostNames) : 'None';

                                                $tblPartNames = [];
                                                foreach ($pIds as $pid) {
                                                    if (!empty($userNameMap[$pid])) {
                                                        $tblPartNames[] = $userNameMap[$pid];
                                                    }
                                                }
                                                $tblPartStr = !empty($tblPartNames) ? implode(', ', array_unique($tblPartNames)) : '';

                                                $isHostOrCoHostOnly = ($isCreator || $isCoHost || $isHR);
                                                $waTblData = [
                                                    'agenda'       => $m['agenda'] ?? '',
                                                    'description'  => $m['description'] ?? '',
                                                    'date'         => date('d M Y', strtotime($mDate)),
                                                    'time'         => $mTimeFormatted,
                                                    'mode'         => ucfirst(strtolower($m['mode'] ?? 'online')),
                                                    'branch'       => $m['branch'] ?? 'All',
                                                    'link'         => $mLink,
                                                    'host'         => $tblHostName,
                                                    'cohosts'      => $tblCoHostStr,
                                                    'participants' => $tblPartStr
                                                ];
                                                ?>
                                                <?php if ($isHostOrCoHostOnly): ?>
                                                <button class="btn btn-sm btn-action-sm" style="background:#25d366; border-color:#25d366; color:#fff; padding:4px 9px;"
                                                    title="Copy WhatsApp invite details"
                                                    onclick='copyWhatsAppInvite(<?= htmlspecialchars(json_encode($waTblData), ENT_QUOTES, "UTF-8") ?>)'>
                                                    <i class="fab fa-whatsapp" style="font-size:0.95rem;"></i>
                                                </button>
                                                <?php endif; ?>

                                                <?php if ($canSeeMom): ?>
                                                <button class="btn btn-primary btn-sm btn-action-sm"
                                                    onclick='openMom(<?= $mid ?>,<?= htmlspecialchars(json_encode($m["agenda"]), ENT_QUOTES, "UTF-8") ?>,<?= htmlspecialchars(json_encode($m["participants"] ?? ""), ENT_QUOTES, "UTF-8") ?>,<?= htmlspecialchars(json_encode($points), ENT_QUOTES, "UTF-8") ?>,<?= $canMarkAtt ? 'true' : 'false' ?>,<?= $isCompleted ? 'true' : 'false' ?>,<?= htmlspecialchars(json_encode($mLink), ENT_QUOTES, "UTF-8") ?>,<?= $canComplete ? 'true' : 'false' ?>,<?= $isRecurring ? 'true' : 'false' ?>,<?= htmlspecialchars(json_encode($m["meeting_date"]), ENT_QUOTES, "UTF-8") ?>,<?= htmlspecialchars(json_encode($m["meeting_time"]), ENT_QUOTES, "UTF-8") ?>,<?= htmlspecialchars(json_encode($mStatus ?? ""), ENT_QUOTES, "UTF-8") ?>,<?= htmlspecialchars(json_encode($tblHostName), ENT_QUOTES, "UTF-8") ?>,<?= htmlspecialchars(json_encode($tblCoHostStr), ENT_QUOTES, "UTF-8") ?>,<?= $isHostOrCoHostOnly ? 'true' : 'false' ?>,<?= htmlspecialchars(json_encode($waTblData), ENT_QUOTES, "UTF-8") ?>)'>
                                                    <i class="fas fa-clipboard-list"></i> MOM
                                                </button>
                                                <?php endif; ?>
                                                <?php if ($isRecurring && ($isHR || $isAllowedCreator)): ?>
                                                <a href="meetingComparisonReport.php?meeting_id=<?= $mid ?>" class="btn btn-info btn-sm btn-action-sm" title="Compare Session Logs & Attendance Matrix">
                                                    <i class="fas fa-layer-group"></i> Compare
                                                </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- EDIT MODAL -->
<div class="modal fade" id="editModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-xl" role="document"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title"><i class="fas fa-edit mr-2"></i> Edit Meeting</h5><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button></div>
        <div class="modal-body">
            <input type="hidden" id="edit_meeting_id">
            <div class="row">
                <div class="col-md-6"><div class="form-group"><label>Agenda <span class="required">*</span></label><input type="text" class="form-control" id="edit_agenda"></div></div>
                <div class="col-md-6"><div class="form-group"><label>Description</label><input type="text" class="form-control" id="edit_description"></div></div>
            </div>
            <div class="row">
                <div class="col-md-3"><div class="form-group"><label>Date <span class="required">*</span></label><input type="date" class="form-control" id="edit_date" min="<?= date('Y-m-d') ?>"></div></div>
                <div class="col-md-3"><div class="form-group"><label>Time <span class="required">*</span></label><input type="time" class="form-control" id="edit_time"></div></div>
                <div class="col-md-3"><div class="form-group"><label>Branch</label>
                    <div class="radio-pill-group">
                        <div class="radio-pill br-pune"><input type="radio" name="edit_branch" id="edit_pune" value="Pune"><label for="edit_pune"><i class="fas fa-city"></i> Pune</label></div>
                        <div class="radio-pill br-kinwat"><input type="radio" name="edit_branch" id="edit_kinwat" value="Kinwat"><label for="edit_kinwat"><i class="fas fa-landmark"></i> Kinwat</label></div>
                        <div class="radio-pill"><input type="radio" name="edit_branch" id="edit_all" value="All"><label for="edit_all"><i class="fas fa-globe"></i> All</label></div>
                    </div>
                </div></div>
                <div class="col-md-3"><div class="form-group"><label>Mode</label>
                    <div class="radio-pill-group">
                        <div class="radio-pill"><input type="radio" name="edit_mode" id="edit_online" value="online"><label for="edit_online"><i class="fas fa-wifi"></i> Online</label></div>
                        <div class="radio-pill"><input type="radio" name="edit_mode" id="edit_offline" value="offline"><label for="edit_offline"><i class="fas fa-map-marker-alt"></i> Offline</label></div>
                    </div>
                </div></div>
            </div>
            <div class="row" id="editLinkGroup">
                <div class="col-md-8"><div class="form-group"><label>Meeting Link</label>
                    <div class="input-group">
                        <div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-link" style="font-size:.8rem;"></i></span></div>
                        <input type="url" class="form-control" id="edit_link" placeholder="https://meet.google.com/…">
                    </div>
                </div></div>
            </div>

            <!-- Edit Recurrence Section -->
            <div class="section-title"><i class="fas fa-redo mr-1"></i> Recurrence Settings <span style="font-size:.75rem;color:#8898b8;font-weight:400;font-style:italic;">optional</span></div>
            <div class="recurrence-toggle-wrap mb-3" id="editRecurToggleWrap" onclick="toggleEditRecurrence()">
                <label class="toggle-switch" onclick="event.stopPropagation()">
                    <input type="checkbox" id="editRecurToggle" onchange="toggleEditRecurrence()">
                    <span class="toggle-slider"></span>
                </label>
                <div>
                    <div style="font-weight:600;font-size:.88rem;color:#2d3a5a;">Make this a recurring meeting</div>
                    <div style="font-size:.75rem;color:#8898b8;">Separate attendance &amp; MOM will be tracked for each occurrence</div>
                </div>
            </div>
            <div class="recurrence-body" id="editRecurBody">
                <div style="background:#f8f9ff;border:1.5px solid #e8eaf0;border-radius:12px;padding:18px 20px;margin-bottom:16px;">
                    <div style="margin-bottom:14px;">
                        <label style="font-size:.82rem;font-weight:700;color:#495057;margin-bottom:8px;display:block;"><i class="fas fa-redo mr-1" style="color:var(--primary);"></i> Frequency</label>
                        <div class="freq-pill-group">
                            <div class="freq-pill"><input type="radio" name="edit_freq_ui" id="editFreqDaily" value="daily" checked onchange="onEditFreqChange()"><label for="editFreqDaily"><i class="fas fa-calendar-day"></i> Daily</label></div>
                            <div class="freq-pill"><input type="radio" name="edit_freq_ui" id="editFreqWeekly" value="weekly" onchange="onEditFreqChange()"><label for="editFreqWeekly"><i class="fas fa-calendar-week"></i> Weekly</label></div>
                            <div class="freq-pill"><input type="radio" name="edit_freq_ui" id="editFreq15Days" value="15days" onchange="onEditFreqChange()"><label for="editFreq15Days"><i class="fas fa-history"></i> Every 15 Days</label></div>
                            <div class="freq-pill"><input type="radio" name="edit_freq_ui" id="editFreqMonthly" value="monthly" onchange="onEditFreqChange()"><label for="editFreqMonthly"><i class="fas fa-calendar-alt"></i> Monthly</label></div>
                        </div>
                    </div>
                    <div id="editDaysOfWeekRow" style="display:none;margin-bottom:14px;">
                        <label style="font-size:.82rem;font-weight:700;color:#495057;margin-bottom:8px;display:block;"><i class="fas fa-calendar-alt mr-1" style="color:var(--primary);"></i><span id="editDaysLabel">On these days</span></label>
                        <div class="day-check-group">
                            <?php
                            $days=['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
                            $dayVals=['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
                            foreach($days as $i=>$d): ?>
                            <div class="day-check">
                                <input type="checkbox" id="edit_day_<?=$dayVals[$i]?>" value="<?=$dayVals[$i]?>" class="edit-day-chk">
                                <label for="edit_day_<?=$dayVals[$i]?>"><?=$d?></label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div style="margin-bottom:14px;">
                        <label style="font-size:.82rem;font-weight:700;color:#495057;margin-bottom:8px;display:block;"><i class="fas fa-clock mr-1" style="color:var(--primary);"></i> Additional Time Slots</label>
                        <div class="time-slot-list" id="editExtraSlotsList"></div>
                        <button type="button" class="btn-add-slot" onclick="addEditTimeSlot()"><i class="fas fa-plus-circle"></i> Add another time slot</button>
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group mb-0">
                                <label style="font-size:.82rem;font-weight:700;color:#495057;"><i class="fas fa-calendar-times mr-1" style="color:var(--primary);"></i> Repeat until</label>
                                <input type="date" class="form-control" id="editRecurEndDate">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex align-items-center justify-content-between flex-wrap mb-2" style="gap:8px;">
                <div class="section-title mb-0" style="margin-top:0;"><i class="fas fa-users mr-1"></i> Participants</div>
                <div class="dropdown" id="edit_batch_dropdown_wrap" style="display:none;">
                    <button type="button" class="btn btn-sm btn-outline-primary dropdown-toggle font-weight-600" id="editBatchDropdownBtn" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" style="border-radius:20px; padding:4px 14px; font-size:.8rem; background:#f0f4ff; border-color:#2f55ff; color:#2f55ff;">
                        <i class="fas fa-layer-group mr-1"></i> Add by Batch
                    </button>
                    <div class="dropdown-menu dropdown-menu-right shadow-sm" id="editBatchDropdownMenu" style="max-height:280px; overflow-y:auto; border-radius:10px; padding:6px 0; min-width:240px;">
                        <div class="dropdown-header text-uppercase font-weight-bold" style="font-size:.68rem; letter-spacing:.05em; color:#8898b8;">Select Batch to Add</div>
                        <a class="dropdown-item d-flex align-items-center justify-content-between py-2 px-3" href="#" onclick="addBatchToEditParticipants('__ALL__'); return false;" style="font-size:.83rem; font-weight:600; color:#2f55ff;">
                            <span><i class="fas fa-users mr-2"></i> All Batches (Everyone)</span>
                        </a>
                        <div class="dropdown-divider my-1"></div>
                        <div id="edit_batch_dropdown_items"></div>
                    </div>
                </div>
            </div>
            <div id="edit_batch_chips" class="mb-2 d-flex flex-wrap align-items-center" style="gap:6px; display:none;"></div>
            <div class="form-group">
                <div id="edit_participants_loading" style="color:#8898b8;font-size:.82rem;padding:8px 0;"><i class="fas fa-spinner fa-spin mr-1"></i> Loading participants list…</div>
                <select id="edit_participants_select" class="form-control" multiple="multiple" style="display:none;"></select>
                <small class="form-text text-muted">Search and select interns/users. Use <strong>Add by Batch</strong> to select full groups at once.</small>
            </div>
            <div class="section-title mt-3"><i class="fas fa-user-shield mr-1"></i> Co-Hosts</div>
            <div class="form-group mb-0">
                <select id="edit_cohosts_select" class="form-control" multiple="multiple" style="display:none;"></select>
                <small class="form-text text-muted">Select co-hosts for this meeting who receive full host permissions.</small>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-primary" onclick="saveEdit()"><i class="fas fa-save mr-1"></i> Save Changes</button>
        </div>
    </div></div>
</div>

<!-- RESCHEDULE MODAL -->
<div class="modal fade" id="rescheduleModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-md" role="document"><div class="modal-content">
        <div class="modal-header" style="background:#fff8e1; border-bottom:1px solid #ffe082;">
            <h5 class="modal-title" style="color:#b45309; font-weight:700;"><i class="fas fa-calendar-alt mr-2"></i> Reschedule Meeting</h5>
            <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="reschedule_meeting_id">
            <div class="reschedule-info-box"><i class="fas fa-exclamation-triangle"></i>
                <div><div style="margin-top:6px; color:#888; font-size:.78rem;">Original: <span id="reschedule_original_dt" style="font-weight:600; color:#6d4c00;"></span></div></div>
            </div>
            <div style="margin-bottom:14px;">
                <label style="font-size:.78rem; font-weight:600; color:#6c757d; margin-bottom:4px; display:block;">Meeting Agenda</label>
                <div id="reschedule_agenda_display" style="font-size:.9rem; font-weight:600; color:#2d3a5a; background:#f8f9fa; border-radius:6px; padding:8px 12px; border:1px solid var(--border);"></div>
            </div>
            <div class="reschedule-datetime-row">
                <div><label style="font-size:.82rem; font-weight:600; color:#555;">New Date <span class="required">*</span></label><input type="date" class="form-control" id="reschedule_new_date" min="<?= date('Y-m-d') ?>"></div>
                <div><label style="font-size:.82rem; font-weight:600; color:#555;">New Time <span class="required">*</span></label><input type="time" class="form-control" id="reschedule_new_time"></div>
            </div>
            <div class="form-group">
                <label style="font-size:.82rem; font-weight:600; color:#555;">Reason / Note <span style="color:#aaa; font-weight:400;">(optional)</span></label>
                <textarea class="form-control" id="reschedule_note" rows="2" placeholder="e.g. Key participants unavailable…" style="font-size:.85rem; resize:vertical;"></textarea>
            </div>
            <div id="reschedule_error" style="display:none;" class="alert alert-danger py-2 px-3 mt-2"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-warning" onclick="saveReschedule()" id="reschedule_save_btn"><i class="fas fa-calendar-check mr-1"></i> Confirm Reschedule</button>
        </div>
    </div></div>
</div>

<!-- MOM MODAL -->
<div class="modal fade" id="momModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-xl" role="document"><div class="modal-content">
        <div class="modal-header" style="background:var(--primary-light); border-bottom:1px solid var(--border);">
            <h5 class="modal-title" style="color:var(--primary); font-weight:700;">
                <i class="fas fa-clipboard-list mr-2"></i> MOM — <span id="mom_agenda_title"></span>
                <span id="mom_recurring_badge" style="display:none; margin-left:10px; font-size:.72rem; background:#f3e8ff; color:#7c3aed; border-radius:20px; padding:2px 10px; font-weight:700; border:1px solid #d8b4fe;">
                    <i class="fas fa-redo" style="font-size:.6rem;"></i> Recurring
                </span>
            </h5>
            <div style="margin-left:auto; display:flex; align-items:center; gap:8px;">
                <button type="button" class="btn btn-sm" id="mom_whatsapp_btn" style="display:none; background:#25d366; border-color:#25d366; color:#fff; border-radius:50%; width:30px; height:30px; padding:0; align-items:center; justify-content:center;" title="Copy WhatsApp invite details">
                    <i class="fab fa-whatsapp" style="font-size:0.95rem;"></i>
                </button>
                <button type="button" class="btn btn-sm btn-outline-danger" onclick="downloadMomPDF()" style="border-radius:20px; font-weight:600; font-size:0.78rem;" title="Download MOM PDF to share with team">
                    <i class="fas fa-file-pdf mr-1"></i> Download PDF
                </button>
                <button type="button" class="close" data-dismiss="modal" style="margin-left:0;"><span>&times;</span></button>
            </div>
        </div>
        <div class="modal-body">

            <!-- Join link bar -->
            <div id="mom_link_bar" style="display:none; margin-bottom:14px;">
                <a id="mom_join_link" href="#" target="_blank" rel="noopener noreferrer"
                   style="display:inline-flex;align-items:center;gap:7px;background:#e8f5e9;color:#2e7d32;border:1.5px solid #a5d6a7;border-radius:8px;padding:7px 16px;font-size:.85rem;font-weight:600;text-decoration:none;">
                    <i class="fas fa-video"></i> Join Meeting
                </a>
            </div>

            <!-- Host & Co-Host Info Bar -->
            <div class="mom-host-info-bar" style="display:flex; flex-wrap:wrap; align-items:center; gap:18px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:10px 16px; margin-bottom:14px; font-size:0.84rem;">
                <div><strong style="color:#1e293b;"><i class="fas fa-user-tie text-primary mr-1"></i> Host:</strong> <span id="mom_host_name" style="color:#334155; font-weight:600;">—</span></div>
                <div><strong style="color:#1e293b;"><i class="fas fa-user-shield text-info mr-1"></i> Co-Host(s):</strong> <span id="mom_cohosts_name" style="color:#334155; font-weight:600;">—</span></div>
            </div>

            <!-- ── SESSION PICKER (recurring only) ── -->
            <div id="mom_session_picker" style="display:none;">
                <div class="session-picker-wrap">
                    <div class="session-picker-header">
                        <span class="session-picker-label"><i class="fas fa-calendar-alt"></i> Select Session</span>
                        <div class="session-picker-stats">
                            <span class="session-stat-chip chip-total"><i class="fas fa-calendar"></i> <span id="sp_total">0</span> total</span>
                            <span class="session-stat-chip chip-done"><i class="fas fa-check-circle"></i> <span id="sp_done">0</span> done</span>
                            <span class="session-stat-chip chip-pending"><i class="fas fa-clock"></i> <span id="sp_pending">0</span> pending</span>
                        </div>
                    </div>

                    <!-- Month navigation -->
                    <div class="session-month-nav">
                        <button type="button" id="sp_prev_month" onclick="spNavigateMonth(-1)" title="Previous month"><i class="fas fa-chevron-left"></i></button>
                        <span class="session-month-title" id="sp_month_title">—</span>
                        <button type="button" id="sp_next_month" onclick="spNavigateMonth(1)" title="Next month"><i class="fas fa-chevron-right"></i></button>
                        <div class="session-month-jump">
                            <i class="fas fa-search" style="font-size:.68rem;color:#aab;"></i>
                            <select id="sp_month_jump" onchange="spJumpToMonth(this.value)"></select>
                        </div>
                    </div>

                    <!-- Day grid -->
                    <div class="session-day-grid" id="sp_day_grid">
                        <!-- injected by JS -->
                    </div>

                    <!-- Selected session info bar -->
                    <div class="session-selected-bar" id="sp_selected_bar">
                        <span class="session-selected-date" id="sp_selected_date">
                            <i class="fas fa-calendar-check"></i> —
                        </span>
                        <span class="session-selected-status" id="sp_selected_status"></span>
                        <div id="sp_selected_time_container" style="margin-left:auto; display:flex; align-items:center; gap:4px; flex-wrap:wrap;"></div>
                    </div>
                </div>
            </div>

            <!-- Completed banner -->
            <div id="mom_completed_banner" style="display:none;" class="mom-completed-banner">
                <i class="fas fa-lock mr-1"></i> This session is completed — MOM is read-only.
            </div>

            <!-- Attendance -->
            <div class="section-title">
                <i class="fas fa-user-check mr-1"></i> Attendance
                <span id="att_summary_inline" style="font-size:.75rem; font-weight:400; color:#888; margin-left:8px;"></span>
            </div>
            <div class="att-summary-bar" id="att_summary_bar" style="display:none;">
                <span class="att-summary-chip"><span class="att-summary-dot" style="background:#e0e0e0;"></span>Total: <strong id="att_total">0</strong></span>
                <span class="att-summary-chip"><span class="att-summary-dot" style="background:#2e7d32;"></span>Present: <strong id="att_present_count">0</strong></span>
                <span class="att-summary-chip"><span class="att-summary-dot" style="background:#c62828;"></span>Absent: <strong id="att_absent_count">0</strong></span>
                <span class="att-summary-chip"><span class="att-summary-dot" style="background:#b45309;"></span>Intimated: <strong id="att_intimated_count">0</strong></span>
                <span class="att-summary-chip"><span class="att-summary-dot" style="background:#0284c7;"></span>On Leave/Tour: <strong id="att_leavetour_count">0</strong></span>
                <span class="att-summary-chip"><span class="att-summary-dot" style="background:#999;"></span>Unmarked: <strong id="att_unmarked_count">0</strong></span>
            </div>
            <div id="mom_att_body"><p class="text-muted" style="font-size:.85rem;">Loading…</p></div>

            <!-- Discussion points -->
            <div class="section-title mt-4">
                <i class="fas fa-comments mr-1"></i> Minutes of Meeting (Discussion Points)
                <a id="mom_allocate_task_btn" href="task_allocation.php" style="display:none; margin-left:12px; font-size:.78rem; font-weight:600; text-decoration:none;" class="btn btn-sm btn-allocate btn-action-sm">
                    <i class="fas fa-tasks mr-1"></i> Allocate Task
                </a>
            </div>
            <div id="mom_points_body"></div>
            <div class="add-point-row" id="addPointRow">
                <input type="text" class="form-control form-control-sm" id="mom_new_point" placeholder="Type a discussion point and press Add…">
                <button class="btn btn-primary btn-sm" onclick="momAddPoint()"><i class="fas fa-plus mr-1"></i> Add</button>
            </div>
            <small class="form-text text-muted mt-1" id="addPointHint">Points are saved instantly per session.</small>

        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline-danger" onclick="downloadMomPDF()" style="font-weight:600; font-size:0.85rem;">
                <i class="fas fa-file-pdf mr-1"></i> Download MOM PDF
            </button>
            <button type="button" class="btn btn-light" data-dismiss="modal">Close</button>
            <button type="button" class="btn btn-complete" id="mom_complete_btn" style="display:none;" onclick="completeMeetingOrOccurrence()">
                <i class="fas fa-check-circle mr-1"></i> Complete This Session
            </button>
        </div>
    </div></div>
</div>

<script src="../vendors/js/vendor.bundle.base.js"></script>
<script src="../vendors/select2/select2.min.js"></script>
<script src="../js/off-canvas.js"></script>
<script src="../js/hoverable-collapse.js"></script>
<script src="../js/template.js"></script>
<script src="../js/settings.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.6.0/jspdf.plugin.autotable.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>

<script>
var CURRENT_USER_ID = <?= json_encode($userId) ?>;
var IS_HR           = <?= $isHR ? 'true' : 'false' ?>;
var IS_INTERN       = <?= $isIntern ? 'true' : 'false' ?>;
var allUsersCache   = null;
var allBatchesCache = null;
var batchMembersCache = null;

function switchView(mode) {
    if (mode === 'grid') {
        $('#meetingsCardGrid').show();
        $('#meetingsTableWrapper').hide();
        $('#btnViewGrid').addClass('active');
        $('#btnViewTable').removeClass('active');
        localStorage.setItem('meeting_history_view_mode', 'grid');
        filterCards();
    } else {
        $('#meetingsCardGrid').hide();
        $('#meetingsTableWrapper').show();
        $('#btnViewTable').addClass('active');
        $('#btnViewGrid').removeClass('active');
        localStorage.setItem('meeting_history_view_mode', 'table');
        if ($.fn.DataTable.isDataTable('#meetingsDataTable')) {
            $('#meetingsDataTable').DataTable().columns.adjust().draw();
        }
    }
}

function filterCards() {
    var searchVal    = ($('#filterSearchInput').val() || '').toLowerCase().trim();
    var statusFilter = ($('#filterStatus').val() || '').toLowerCase().trim();
    var branchFilter = ($('#filterBranch').val() || '').toLowerCase().trim();
    var modeFilter   = ($('#filterMode').val() || '').toLowerCase().trim();
    var typeFilter   = ($('#filterType').val() || '').toLowerCase().trim();
    var fromDate     = $('#filterFromDate').val();
    var toDate       = $('#filterToDate').val();

    var visibleCount = 0;

    $('.meeting-card-item').each(function () {
        var $card = $(this);
        var agenda = ($card.attr('data-agenda') || '');
        var status = ($card.attr('data-status') || '');
        var branch = ($card.attr('data-branch') || '');
        var mode   = ($card.attr('data-mode') || '');
        var type   = ($card.attr('data-type') || '');
        var date   = ($card.attr('data-date') || '');
        var cardText = $card.text().toLowerCase();

        var matchSearch = !searchVal || cardText.indexOf(searchVal) !== -1 || agenda.indexOf(searchVal) !== -1;
        var matchStatus = !statusFilter || status === statusFilter;
        var matchBranch = !branchFilter || branch === branchFilter;
        var matchMode   = !modeFilter || mode === modeFilter;
        var matchType   = !typeFilter || type === typeFilter;
        var matchFrom   = !fromDate || date >= fromDate;
        var matchTo     = !toDate || date <= toDate;

        if (matchSearch && matchStatus && matchBranch && matchMode && matchType && matchFrom && matchTo) {
            $card.show();
            visibleCount++;
        } else {
            $card.hide();
        }
    });

    var $noResults = $('#noCardResults');
    if (visibleCount === 0) {
        if ($noResults.length === 0) {
            $('#meetingsCardGrid').append('<div id="noCardResults" class="col-12 text-center py-5 text-muted" style="font-size:.9rem;"><i class="fas fa-search-minus fa-2x mb-2 d-block"></i>No matching meetings found.</div>');
        } else {
            $noResults.show();
        }
    } else {
        if ($noResults.length > 0) $noResults.hide();
    }
}

// Custom DataTables filter plugin
$.fn.dataTable.ext.search.push(function (settings, data, dataIndex) {
    if (settings.nTable.id !== 'meetingsDataTable') return true;

    var row = settings.aoData[dataIndex].nTr;
    if (!row) return true;

    var searchVal    = ($('#filterSearchInput').val() || '').toLowerCase().trim();
    var statusFilter = $('#filterStatus').val();
    var branchFilter = $('#filterBranch').val();
    var modeFilter   = $('#filterMode').val();
    var typeFilter   = $('#filterType').val();
    var fromDate     = $('#filterFromDate').val();
    var toDate       = $('#filterToDate').val();

    var rowText   = $(row).text().toLowerCase();
    var rowStatus = (row.getAttribute('data-status') || '').toLowerCase();
    var rowBranch = (row.getAttribute('data-branch') || '').toLowerCase();
    var rowMode   = (row.getAttribute('data-mode') || '').toLowerCase();
    var rowType   = (row.getAttribute('data-type') || '').toLowerCase();
    var rowDate   = row.getAttribute('data-date') || '';

    if (searchVal && rowText.indexOf(searchVal) === -1) return false;
    if (statusFilter && rowStatus !== statusFilter) return false;
    if (branchFilter && rowBranch !== branchFilter) return false;
    if (modeFilter && rowMode !== modeFilter) return false;
    if (typeFilter && rowType !== typeFilter) return false;

    if (fromDate && rowDate < fromDate) return false;
    if (toDate && rowDate > toDate) return false;

    return true;
});

$(document).ready(function () {
    var table = $('#meetingsDataTable').DataTable({
        scrollX:false, autoWidth:false, pageLength:10,
        lengthMenu:[5,10,25,50,100], order:[],
        columnDefs:[{orderable:false,targets:[0,8]}],
        dom:'<"row mb-2 align-items-center"<"col-sm-6"l><"col-sm-6 d-flex justify-content-end"f>>t<"row mt-2 align-items-center"<"col-sm-5"i><"col-sm-7 d-flex justify-content-end"p>>',
        language:{search:'',searchPlaceholder:'🔍  Search meetings…',lengthMenu:'Show _MENU_ meetings',info:'Showing _START_–_END_ of _TOTAL_ meetings',infoEmpty:'No meetings found',paginate:{previous:'‹',next:'›'}},
        drawCallback:function(){var api=this.api();var start=api.page.info().start;api.column(0,{search:'applied',order:'applied'}).nodes().each(function(cell,i){cell.innerHTML=start+i+1;});}
    });

    $('#filterSearchInput, #filterStatus, #filterBranch, #filterMode, #filterType, #filterFromDate, #filterToDate').on('change keyup input', function () {
        table.draw();
        filterCards();
    });

    $('#btnResetFilters').on('click', function () {
        $('#filterSearchInput').val('');
        $('#filterStatus').val('');
        $('#filterBranch').val('');
        $('#filterMode').val('');
        $('#filterType').val('');
        $('#filterFromDate').val('');
        $('#filterToDate').val('');
        table.draw();
        filterCards();
    });

    var savedMode = localStorage.getItem('meeting_history_view_mode') || (window.innerWidth < 768 ? 'grid' : 'grid');
    switchView(savedMode);
});

function cancelMeeting(mid, agenda) {
    Swal.fire({
        title: 'Cancel Meeting?',
        text: 'Are you sure you want to cancel "' + agenda + '"? Invited participants will receive a notification.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: '<i class="fas fa-ban mr-1"></i> Yes, Cancel Meeting',
        cancelButtonText: 'No, Keep It'
    }).then(function (result) {
        if (result.isConfirmed) {
            fetch('meetingHistory.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=cancel_meeting&meeting_id=' + encodeURIComponent(mid)
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) {
                    Swal.fire({ icon: 'error', title: 'Error', text: data.error || 'Could not cancel meeting.' });
                    return;
                }
                var statusEl = document.getElementById('mstatus-' + mid);
                if (statusEl) {
                    statusEl.className = 'badge-status badge-cancelled';
                    statusEl.textContent = 'Cancelled';
                }
                var cardStatusEl = document.getElementById('mcardstatus-' + mid);
                if (cardStatusEl) {
                    cardStatusEl.className = 'badge-status badge-cancelled';
                    cardStatusEl.textContent = 'Cancelled';
                }
                var cardObj = document.querySelector('#meetingcard-' + mid + ' .meeting-card');
                if (cardObj) {
                    cardObj.className = 'meeting-card accent-cancelled';
                }
                var row = document.getElementById('meetingrow-' + mid);
                if (row) {
                    row.setAttribute('data-status', 'cancelled');
                }
                var cardItem = document.getElementById('meetingcard-' + mid);
                if (cardItem) {
                    cardItem.setAttribute('data-status', 'cancelled');
                }

                $('#btn-cancel-' + mid).remove();
                $('#btn-cardcancel-' + mid).remove();

                showToast('Meeting cancelled successfully!', 'success');
                setTimeout(function () { location.reload(); }, 1200);
            })
            .catch(function () {
                Swal.fire({ icon: 'error', title: 'Network Error', text: 'Could not connect to server.' });
            });
        }
    });
}



document.addEventListener('DOMContentLoaded',function(){
    document.querySelectorAll('.agenda-desc').forEach(function(el){
        var mid=el.id.replace('adesc-','');var tog=document.getElementById('atog-'+mid);
        if(!tog)return;if(el.scrollHeight<=el.clientHeight+4)tog.style.display='none';
    });
});

function toggleDesc(mid){var el=document.getElementById('adesc-'+mid);var tog=document.getElementById('atog-'+mid);if(!el||!tog)return;var expanded=el.classList.toggle('expanded');tog.textContent=expanded?'Show less':'Read more';}

/* ─── MOM global state ─── */
var currentMomMeetingId = null;
var currentOccurrenceId = 0;
var momCanMarkAtt       = false;
var momIsCompleted      = false;
var momIsRecurring      = false;
var momParticipantsStr  = '';
var momCanComplete      = false;
var momMeetingDate      = null;
var momMeetingTime      = null;
var momHostName         = '—';
var momCoHostNames      = 'None';
var spSelectedOcc       = null;

/* ─── Session Picker state ─── */
var spOccurrences    = [];
var spOccByDate      = {};
var spCurrentYear    = 0;
var spCurrentMonth   = 0;
var spMonthsWithData = [];

var MONTHS_LONG = ['January','February','March','April','May','June','July','August','September','October','November','December'];
var DAYS_SHORT  = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
var TODAY_STR   = new Date().toISOString().split('T')[0];

function formatTime12(timeStr) {
    if (!timeStr) return '';
    var parts = timeStr.trim().split(':');
    var h = parseInt(parts[0], 10) || 0;
    var m = parts[1] || '00';
    var ampm = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    return h + ':' + m + ' ' + ampm;
}

function hasMeetingStarted(dateStr, timeStr) {
    if (!dateStr || !timeStr) return true;
    var now = new Date();
    var tParts = timeStr.trim().split(':');
    var h = parseInt(tParts[0], 10) || 0;
    var m = parseInt(tParts[1], 10) || 0;
    var s = parseInt(tParts[2], 10) || 0;

    var dParts = dateStr.trim().split('-');
    var yr = parseInt(dParts[0], 10);
    var mo = parseInt(dParts[1], 10) - 1;
    var dy = parseInt(dParts[2], 10);

    var meetingDT = new Date(yr, mo, dy, h, m, s);
    return now >= meetingDT;
}

/* ─── openMom ─── */
function openMom(meetingId, agenda, participantsStr, points, canMarkAtt, isCompleted, meetingLink, canComplete, isRecurring, meetingDate, meetingTime, meetingStatus, hostName, coHostNames, isHostOrCoHostOnly, waData) {
    currentMomMeetingId = meetingId;
    currentOccurrenceId = 0;
    momMeetingDate      = meetingDate;
    momMeetingTime      = meetingTime;
    momHostName         = hostName || 'Unknown';
    momCoHostNames      = coHostNames || 'None';
    spSelectedOcc       = null;

    var waBtn = document.getElementById('mom_whatsapp_btn');
    if (waBtn) {
        if (isHostOrCoHostOnly && waData) {
            waBtn.style.display = 'inline-flex';
            waBtn.onclick = function() { copyWhatsAppInvite(waData); };
        } else {
            waBtn.style.display = 'none';
        }
    }
    
    var isCancelled     = (meetingStatus === 'cancelled');
    momCanMarkAtt       = canMarkAtt && !isCancelled;
    momIsCompleted      = isCompleted;
    momIsRecurring      = isRecurring;
    momParticipantsStr  = participantsStr;
    momCanComplete      = canComplete && !isCancelled;

    document.getElementById('mom_agenda_title').textContent = agenda;
    document.getElementById('mom_host_name').textContent = momHostName;
    document.getElementById('mom_cohosts_name').textContent = momCoHostNames;
    document.getElementById('att_summary_bar').style.display = 'none';
    document.getElementById('att_summary_inline').textContent = '';
    document.getElementById('mom_completed_banner').style.display = 'none';
    document.getElementById('mom_points_body').innerHTML = '';
    document.getElementById('mom_att_body').innerHTML = '<p class="text-muted" style="font-size:.85rem;">Loading…</p>';
    document.getElementById('mom_complete_btn').style.display = 'none';

    document.getElementById('mom_recurring_badge').style.display = isRecurring ? 'inline-flex' : 'none';

    var linkBar  = document.getElementById('mom_link_bar');
    var joinLink = document.getElementById('mom_join_link');
    if (meetingLink && meetingLink.trim()) { joinLink.href = meetingLink; linkBar.style.display = 'block'; }
    else { linkBar.style.display = 'none'; }

    var allocateBtn = document.getElementById('mom_allocate_task_btn');
    if (allocateBtn) {
        allocateBtn.style.display = (momCanMarkAtt && !IS_INTERN) ? 'inline-flex' : 'none';
        allocateBtn.href = 'task_allocation.php?meeting_id=' + encodeURIComponent(meetingId);
    }

    $('#momModal').modal('show');

    if (isRecurring) {
        document.getElementById('mom_session_picker').style.display = 'block';
        document.getElementById('sp_day_grid').innerHTML = '<div style="font-size:.82rem;color:#8898b8;padding:16px 0;text-align:center;"><i class="fas fa-spinner fa-spin mr-1"></i> Loading sessions…</div>';
        document.getElementById('sp_selected_bar').classList.remove('visible');
        document.getElementById('addPointRow').style.display = 'none';
        document.getElementById('addPointHint').style.display = 'none';

        document.getElementById('mom_att_body').innerHTML =
            '<p class="text-muted" style="font-size:.85rem;"><i class="fas fa-hand-point-up mr-1"></i> Select a session above to view attendance.</p>';
        document.getElementById('mom_points_body').innerHTML =
            '<p class="text-muted" style="font-size:.85rem;"><i class="fas fa-hand-point-up mr-1"></i> Select a session above to view discussion points.</p>';

        fetch('meetingHistory.php', {
            method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:'action=fetch_occurrences&meeting_id='+encodeURIComponent(meetingId)
        })
        .then(function(r){ return r.json(); })
        .then(function(data){
            if (!data.success || !data.occurrences || !data.occurrences.length) {
                document.getElementById('sp_day_grid').innerHTML = '<div class="session-no-sessions"><i class="fas fa-calendar-times" style="font-size:1.8rem;display:block;margin-bottom:8px;"></i>No sessions generated yet.</div>';
                return;
            }
            spInitPicker(data.occurrences, momCanMarkAtt, momCanComplete);
        });
    } else {
        var started = hasMeetingStarted(meetingDate, meetingTime);
        var effectiveCanMarkAtt = momCanMarkAtt && started;
        var effectiveCanComplete = momCanComplete && started;

        document.getElementById('mom_session_picker').style.display = 'none';
        if (isCompleted) document.getElementById('mom_completed_banner').style.display = 'flex';
        document.getElementById('mom_complete_btn').style.display = (effectiveCanComplete && !isCompleted) ? 'inline-block' : 'none';

        var addRow  = document.getElementById('addPointRow');
        var addHint = document.getElementById('addPointHint');
        if (isCompleted || isCancelled || !effectiveCanMarkAtt) { addRow.style.display='none'; addHint.style.display='none'; }
        else { addRow.style.display='flex'; addHint.style.display='block'; }

        fetchMomPoints(meetingId, 0, isCompleted, effectiveCanMarkAtt);
        loadAttendance(meetingId, 0, participantsStr, effectiveCanMarkAtt, isCompleted, started, meetingDate, meetingTime, isCancelled);
    }
}

/* ─── Session Picker init ─── */
function spInitPicker(occurrences, canMarkAtt, canComplete) {
    spOccurrences = occurrences;
    spOccByDate   = {};

    var totalDone = 0;
    var monthsSet = {};

    occurrences.forEach(function(occ) {
        if (!spOccByDate[occ.occurrence_date]) spOccByDate[occ.occurrence_date] = [];
        spOccByDate[occ.occurrence_date].push(occ);
        if (occ.status === 'completed') totalDone++;
        var d   = new Date(occ.occurrence_date + 'T00:00:00');
        var key = d.getFullYear() + '-' + d.getMonth();
        if (!monthsSet[key]) monthsSet[key] = {year: d.getFullYear(), month: d.getMonth()};
    });

    document.getElementById('sp_total').textContent   = occurrences.length;
    document.getElementById('sp_done').textContent    = totalDone;
    document.getElementById('sp_pending').textContent = occurrences.length - totalDone;

    spMonthsWithData = Object.values(monthsSet).sort(function(a,b){
        return (a.year * 12 + a.month) - (b.year * 12 + b.month);
    });

    var jumpSel = document.getElementById('sp_month_jump');
    jumpSel.innerHTML = '';
    spMonthsWithData.forEach(function(m, idx) {
        var opt = document.createElement('option');
        opt.value = idx;
        opt.textContent = MONTHS_LONG[m.month] + ' ' + m.year;
        jumpSel.appendChild(opt);
    });

    // Navigate to today's month or most recent past month with sessions
    var targetMonthIdx = 0;
    var todayD = new Date(TODAY_STR + 'T00:00:00');
    for (var i = 0; i < spMonthsWithData.length; i++) {
        var ym = spMonthsWithData[i];
        if (ym.year < todayD.getFullYear() || (ym.year === todayD.getFullYear() && ym.month <= todayD.getMonth())) {
            targetMonthIdx = i;
        }
    }

    spCurrentYear  = spMonthsWithData[targetMonthIdx].year;
    spCurrentMonth = spMonthsWithData[targetMonthIdx].month;
    jumpSel.value  = targetMonthIdx;

    spRenderGrid();

    // Auto-select: most recent session that has already started / is due
    var bestOcc = null;
    occurrences.forEach(function(occ) {
        if (hasMeetingStarted(occ.occurrence_date, occ.occurrence_time)) {
            bestOcc = occ;
        }
    });
    if (!bestOcc) bestOcc = occurrences[0];
    if (bestOcc) spSelectOccurrence(bestOcc, canMarkAtt, canComplete);
}

function spNavigateMonth(dir) {
    var curIdx = -1;
    for (var i = 0; i < spMonthsWithData.length; i++) {
        if (spMonthsWithData[i].year === spCurrentYear && spMonthsWithData[i].month === spCurrentMonth) {
            curIdx = i; break;
        }
    }
    var newIdx = curIdx + dir;
    if (newIdx < 0 || newIdx >= spMonthsWithData.length) return;
    spCurrentYear  = spMonthsWithData[newIdx].year;
    spCurrentMonth = spMonthsWithData[newIdx].month;
    document.getElementById('sp_month_jump').value = newIdx;
    spRenderGrid();
}

function spJumpToMonth(idx) {
    idx = parseInt(idx);
    if (isNaN(idx) || idx < 0 || idx >= spMonthsWithData.length) return;
    spCurrentYear  = spMonthsWithData[idx].year;
    spCurrentMonth = spMonthsWithData[idx].month;
    spRenderGrid();
}

function spRenderGrid() {
    var grid    = document.getElementById('sp_day_grid');
    var title   = document.getElementById('sp_month_title');
    var btnPrev = document.getElementById('sp_prev_month');
    var btnNext = document.getElementById('sp_next_month');

    title.textContent = MONTHS_LONG[spCurrentMonth] + ' ' + spCurrentYear;

    var curIdx = -1;
    for (var i = 0; i < spMonthsWithData.length; i++) {
        if (spMonthsWithData[i].year === spCurrentYear && spMonthsWithData[i].month === spCurrentMonth) {
            curIdx = i; break;
        }
    }
    btnPrev.disabled = (curIdx <= 0);
    btnNext.disabled = (curIdx >= spMonthsWithData.length - 1);

    grid.innerHTML = '';

    ['Su','Mo','Tu','We','Th','Fr','Sa'].forEach(function(d) {
        var h = document.createElement('div');
        h.className = 'session-day-header';
        h.textContent = d;
        grid.appendChild(h);
    });

    var firstDay    = new Date(spCurrentYear, spCurrentMonth, 1).getDay();
    var daysInMonth = new Date(spCurrentYear, spCurrentMonth + 1, 0).getDate();

    for (var e = 0; e < firstDay; e++) {
        var empty = document.createElement('div');
        empty.className = 'session-day-cell';
        grid.appendChild(empty);
    }

    for (var d = 1; d <= daysInMonth; d++) {
        var dateStr = spCurrentYear + '-'
            + String(spCurrentMonth + 1).padStart(2, '0') + '-'
            + String(d).padStart(2, '0');

        var cell       = document.createElement('div');
        var occsForDay = spOccByDate[dateStr] || [];

        if (occsForDay.length > 0) {
            var activeOccInDay = occsForDay.find(function(o){ return o.id == currentOccurrenceId; }) || occsForDay[0];
            var allCompleted   = occsForDay.every(function(o){ return o.status === 'completed'; });
            var isActive       = (currentOccurrenceId && occsForDay.some(function(o){ return o.id == currentOccurrenceId; }));
            var isToday        = (dateStr === TODAY_STR);

            cell.className = 'session-day-cell has-session'
                + (allCompleted ? ' is-completed' : '')
                + (isActive     ? ' is-active'    : '')
                + (isToday      ? ' is-today-marker' : '');

            cell.setAttribute('data-occ-id', activeOccInDay.id);
            cell.setAttribute('data-date', dateStr);

            var slotBadgeHtml = occsForDay.length > 1
                ? '<span style="font-size:.56rem;font-weight:800;background:rgba(47,85,255,.15);color:var(--primary);border-radius:10px;padding:0 4px;margin-top:1px;">' + occsForDay.length + ' slots</span>'
                : '<span class="session-day-dot"></span>';

            cell.innerHTML = '<span class="session-day-num">' + d + '</span>' + slotBadgeHtml;
            cell.title = dateStr + ' (' + occsForDay.length + ' session' + (occsForDay.length>1?'s':'') + ')';

            (function(targetOcc) {
                cell.onclick = function() {
                    spSelectOccurrence(targetOcc, momCanMarkAtt, momCanComplete);
                };
            })(activeOccInDay);
        } else {
            cell.className = 'session-day-cell';
            cell.innerHTML = '<span class="session-day-num" style="opacity:.3;">' + d + '</span>';
        }

        grid.appendChild(cell);
    }
}

/* ─── spSelectOccurrence ───
   FIX: When switching sessions, reset the attendance body immediately
   before fetching so stale data from the previous session never lingers. */
function spSelectOccurrence(occ, canMarkAtt, canComplete) {
    spSelectedOcc       = occ;
    currentOccurrenceId = occ.id;
    momIsCompleted      = (occ.status === 'completed');
    var isOccCancelled  = (occ.status === 'cancelled');

    var sessionStarted          = hasMeetingStarted(occ.occurrence_date, occ.occurrence_time);
    var effectiveCanMarkAtt    = canMarkAtt && sessionStarted && !isOccCancelled;
    var effectiveCanComplete   = canComplete && sessionStarted && !isOccCancelled;

    // Re-render grid to update active highlight
    spRenderGrid();

    // Update selected info bar
    var bar = document.getElementById('sp_selected_bar');
    bar.classList.add('visible');

    var d             = new Date(occ.occurrence_date + 'T00:00:00');
    var formattedDate = DAYS_SHORT[d.getDay()] + ', '
        + d.getDate() + ' ' + MONTHS_LONG[d.getMonth()] + ' ' + d.getFullYear();

    document.getElementById('sp_selected_date').innerHTML =
        '<i class="fas fa-calendar-check"></i> ' + formattedDate;

    var statusEl = document.getElementById('sp_selected_status');
    if (occ.status === 'completed') {
        statusEl.textContent = '✓ Completed';
        statusEl.style.cssText = 'background:#e8f5e9;color:#2e7d32;padding:2px 10px;border-radius:20px;font-size:.72rem;font-weight:600;';
    } else {
        statusEl.textContent = occ.occurrence_date > TODAY_STR ? 'Upcoming' : 'Pending';
        statusEl.style.cssText = 'background:#fff3e0;color:#b45309;padding:2px 10px;border-radius:20px;font-size:.72rem;font-weight:600;';
    }

    // ── Render ALL Time Slot Pills for this date ──
    var occsForDate = spOccByDate[occ.occurrence_date] || [occ];
    var timeContainer = document.getElementById('sp_selected_time_container');
    if (timeContainer) {
        timeContainer.innerHTML = '';

        if (occsForDate.length > 1) {
            var labelSpan = document.createElement('span');
            labelSpan.style.cssText = 'font-size:.72rem;font-weight:700;color:#64748b;margin-right:2px;';
            labelSpan.textContent = 'Slots:';
            timeContainer.appendChild(labelSpan);
        }

        occsForDate.forEach(function(oItem) {
            var timeFormatted = formatTime12(oItem.occurrence_time);

            var pill = document.createElement('button');
            pill.type = 'button';
            var isCurrent = (oItem.id == occ.id);
            var isDone    = (oItem.status === 'completed');

            pill.className = 'sp-time-slot-pill' + (isCurrent ? ' active' : '') + (isDone ? ' done' : '');
            pill.style.cssText = 'display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:16px;font-size:.74rem;font-weight:600;cursor:pointer;transition:all .15s;'
                + 'border:1.5px solid ' + (isCurrent ? 'var(--primary)' : '#cbd5e1') + ';'
                + 'background:' + (isCurrent ? 'var(--primary)' : '#ffffff') + ';'
                + 'color:' + (isCurrent ? '#ffffff' : '#334155') + ';margin-left:3px;';

            pill.innerHTML = '<i class="fas fa-clock" style="font-size:.65rem;"></i> ' + timeFormatted
                + (isDone ? ' <i class="fas fa-check-circle" style="font-size:.65rem;color:' + (isCurrent ? '#a7f3d0' : '#10b981') + ';"></i>' : '');

            (function(oTarget) {
                pill.onclick = function(e) {
                    e.stopPropagation();
                    spSelectOccurrence(oTarget, canMarkAtt, canComplete);
                };
            })(oItem);

            timeContainer.appendChild(pill);
        });
    }

    // Completed banner
    document.getElementById('mom_completed_banner').style.display = momIsCompleted ? 'flex' : 'none';

    // Complete button
    var completeBtn = document.getElementById('mom_complete_btn');
    completeBtn.style.display = (effectiveCanComplete && !momIsCompleted) ? 'inline-block' : 'none';
    completeBtn.innerHTML = '<i class="fas fa-check-circle mr-1"></i> Complete This Session';
    completeBtn.disabled = false;

    // Add point row visibility
    var addRow  = document.getElementById('addPointRow');
    var addHint = document.getElementById('addPointHint');
    if (momIsCompleted || isOccCancelled || !effectiveCanMarkAtt) {
        addRow.style.display  = 'none';
        addHint.style.display = 'none';
    } else {
        addRow.style.display  = 'flex';
        addHint.style.display = 'block';
    }
    document.getElementById('mom_new_point').value = '';

    // Clear stale content immediately before async fetch
    document.getElementById('mom_att_body').innerHTML =
        '<p class="text-muted" style="font-size:.85rem;"><i class="fas fa-spinner fa-spin mr-1"></i> Loading attendance…</p>';
    document.getElementById('att_summary_bar').style.display = 'none';
    document.getElementById('att_summary_inline').textContent = '';
    document.getElementById('mom_points_body').innerHTML =
        '<p class="text-muted" style="font-size:.85rem;"><i class="fas fa-spinner fa-spin mr-1"></i> Loading points…</p>';

    // Fetch fresh data for the selected occurrence
    loadAttendance(currentMomMeetingId, occ.id, momParticipantsStr, effectiveCanMarkAtt, momIsCompleted, sessionStarted, occ.occurrence_date, occ.occurrence_time, isOccCancelled);
    fetchMomPoints(currentMomMeetingId, occ.id, momIsCompleted, effectiveCanMarkAtt);
}

function fetchMomPoints(meetingId, occId, isCompleted, canMarkAtt) {
    fetch('meetingHistory.php', {
        method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'action=fetch_mom_points&meeting_id='+encodeURIComponent(meetingId)+'&occurrence_id='+encodeURIComponent(occId)
    })
    .then(function(r){ return r.json(); })
    .then(function(data){ renderPoints(meetingId, data.points || [], isCompleted); })
    .catch(function(){ renderPoints(meetingId, [], isCompleted); });
}

/* ─── loadAttendance ───
   FIX: Always pass the correct occId. For recurring sessions this is occ.id (>=1).
   For non-recurring this is 0. The PHP side handles the legacy fallback. */
function loadAttendance(meetingId, occId, participantsStr, canMarkAtt, isCompleted, sessionStarted, dateStr, timeStr, isCancelled) {
    document.getElementById('att_summary_bar').style.display = 'none';
    document.getElementById('mom_att_body').innerHTML =
        '<p class="text-muted" style="font-size:.85rem;"><i class="fas fa-spinner fa-spin mr-1"></i> Loading attendance…</p>';

    var body = 'action=fetch_attendance'
             + '&meeting_id='    + encodeURIComponent(meetingId)
             + '&occurrence_id=' + encodeURIComponent(occId)
             + '&participants='  + encodeURIComponent(participantsStr);

    fetch('meetingHistory.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: body
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        if (!data.success) {
            document.getElementById('mom_att_body').innerHTML =
                '<p class="text-danger" style="font-size:.85rem;">Failed to load attendance.</p>';
            return;
        }
        renderAttendance(meetingId, occId, data.participants, data.grouped, canMarkAtt, isCompleted, sessionStarted, dateStr, timeStr, isCancelled);
    })
    .catch(function(){
        document.getElementById('mom_att_body').innerHTML =
            '<p class="text-danger" style="font-size:.85rem;">Network error loading attendance.</p>';
    });
}

function completeMeetingOrOccurrence() {
    var isRecurring = momIsRecurring;
    var titleText   = isRecurring ? 'Complete Session?' : 'Complete Meeting?';
    var confirmText = isRecurring
        ? 'Mark this session as completed? Attendance and MOM will be locked.'
        : 'Mark this meeting as completed? This will lock editing and make MOM read-only.';

    Swal.fire({
        title: titleText,
        text: confirmText,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#2f55ff',
        cancelButtonColor: '#6c757d',
        confirmButtonText: '<i class="fas fa-check-circle mr-1"></i> Yes, Complete It',
        cancelButtonText: 'Cancel'
    }).then(function(result) {
        if (result.isConfirmed) {
            var btn = document.getElementById('mom_complete_btn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Completing…';

            var action = (isRecurring && currentOccurrenceId > 0) ? 'complete_occurrence' : 'complete_meeting';
            var body   = 'action='+action+'&meeting_id='+encodeURIComponent(currentMomMeetingId);
            if (isRecurring && currentOccurrenceId > 0) body += '&occurrence_id='+encodeURIComponent(currentOccurrenceId);

            fetch('meetingHistory.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:body})
            .then(function(r){ return r.json(); })
            .then(function(data){
                if (!data.success) {
                    Swal.fire({icon:'error', title:'Error', text:(data.error || 'Could not complete.'), confirmButtonColor:'#2f55ff'});
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-check-circle mr-1"></i> Complete This Session';
                    return;
                }
                momIsCompleted = true;
                document.getElementById('mom_completed_banner').style.display = 'flex';
                btn.style.display = 'none';
                document.getElementById('addPointRow').style.display  = 'none';
                document.getElementById('addPointHint').style.display = 'none';

                // Update local occurrence cache and re-render grid
                for (var date in spOccByDate) {
                    spOccByDate[date].forEach(function(occ) {
                        if (occ.id == currentOccurrenceId) occ.status = 'completed';
                    });
                }
                spRenderGrid();

                var statusEl = document.getElementById('sp_selected_status');
                if (statusEl) {
                    statusEl.textContent = '✓ Completed';
                    statusEl.style.cssText = 'background:#e8f5e9;color:#2e7d32;padding:2px 10px;border-radius:20px;font-size:.72rem;font-weight:600;';
                }

                var doneEl = document.getElementById('sp_done');
                var pendEl = document.getElementById('sp_pending');
                if (doneEl) doneEl.textContent = parseInt(doneEl.textContent) + 1;
                if (pendEl && parseInt(pendEl.textContent) > 0) pendEl.textContent = parseInt(pendEl.textContent) - 1;

                if (!isRecurring) {
                    var statusBadge = document.getElementById('mstatus-'+currentMomMeetingId);
                    if (statusBadge) { statusBadge.className='badge-status badge-completed'; statusBadge.textContent='Completed'; }
                }

                document.getElementById('mom_points_body').querySelectorAll('.point-actions').forEach(function(pa){ pa.remove(); });
                document.getElementById('mom_att_body').querySelectorAll('.att-btn-group').forEach(function(g){
                    g.innerHTML='<span style="font-size:.72rem;color:#aaa;">Locked</span>';
                });

                Swal.fire({
                    icon: 'success',
                    title: 'Session Completed!',
                    text: 'This session has been marked as completed and locked.',
                    timer: 2000,
                    showConfirmButton: false
                });
            })
            .catch(function(){
                Swal.fire({icon:'error', title:'Network Error', text:'Could not connect to server.', confirmButtonColor:'#2f55ff'});
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-check-circle mr-1"></i> Complete This Session';
            });
        }
    });
}

/* ─── renderAttendance ───
   FIX: Element IDs now use a combined key meetingId+'-'+occId+'-'+userId
   so each session has its own unique DOM elements. Switching sessions
   re-renders the full attendance HTML so there's no cross-session bleed. */
function renderAttendance(meetingId, occId, participants, grouped, canMarkAtt, isCompleted, sessionStarted, dateStr, timeStr, isCancelled) {
    var attBody = document.getElementById('mom_att_body');
    if (!participants || participants.length === 0) {
        attBody.innerHTML = '<p class="text-muted" style="font-size:.85rem;">No participants found for this meeting.</p>';
        return;
    }
    var total           = participants.length;
    var present         = participants.filter(function(p){ return p.attendance==='present'; }).length;
    var absent          = participants.filter(function(p){ return p.attendance==='absent'; }).length;
    var absentIntimated = participants.filter(function(p){ return p.attendance==='absent_intimated'; }).length;
    var unmarked        = total - present - absent - absentIntimated;

    document.getElementById('att_total').textContent          = total;
    document.getElementById('att_present_count').textContent  = present;
    document.getElementById('att_absent_count').textContent   = absent;
    document.getElementById('att_intimated_count').textContent = absentIntimated;
    document.getElementById('att_unmarked_count').textContent = unmarked;
    document.getElementById('att_summary_bar').style.display  = 'flex';
    document.getElementById('att_summary_inline').textContent = '('+present+' present, '+absent+' absent, '+absentIntimated+' intimated, '+unmarked+' unmarked)';

    var html = '';
    if (isCancelled) {
        html += '<div class="att-readonly-notice text-danger" style="background:#fee2e2;border:1.5px solid #fca5a5;color:#b91c1c;padding:10px 14px;border-radius:8px;margin-bottom:12px;font-weight:600;font-size:.83rem;"><i class="fas fa-ban mr-1"></i> This meeting has been cancelled. MOM filling and attendance marking are disabled.</div>';
    } else if (sessionStarted === false) {
        var formattedTime = timeStr ? formatTime12(timeStr) : '';
        html += '<div class="att-readonly-notice" style="background:#eef2ff;border:1.5px solid #c7d2fe;color:#3730a3;padding:10px 14px;border-radius:8px;margin-bottom:12px;font-weight:600;font-size:.83rem;"><i class="fas fa-clock mr-1"></i> Meeting has not started yet. MOM filling and attendance marking will be enabled after ' + escHtml(formattedTime) + '.</div>';
    } else if (!canMarkAtt || isCompleted) {
        html += '<div class="att-readonly-notice"><i class="fas fa-info-circle"></i> '
            + (isCompleted
                ? 'This session is completed. Attendance is read-only.'
                : 'You can view attendance but cannot mark it.')
            + '</div>';
    }

    for (var batchName in grouped) {
        var members      = grouped[batchName];
        var batchUserIds = members.map(function(m){ return m.userId; });

        html += '<div class="batch-group-header">'
            + '<span class="batch-group-header__title"><i class="fas fa-layer-group"></i> '
            + escHtml(batchName)
            + ' <span style="font-weight:400;color:#555;font-size:.73rem;">('
            + members.length+' member'+(members.length!==1?'s':'')+')</span></span>';

        if (canMarkAtt && !isCompleted) {
            var batchJsonEsc = escHtml(JSON.stringify(batchUserIds));
            html += '<span class="batch-group-header__actions">'
                + '<button class="btn-mark-all btn-mark-all-present" '
                + 'onclick="markBatchAll('+meetingId+','+occId+','+batchJsonEsc+',\'present\')">'
                + '<i class="fas fa-check mr-1"></i> All Present</button>'
                + '<button class="btn-mark-all btn-mark-all-absent" '
                + 'onclick="markBatchAll('+meetingId+','+occId+','+batchJsonEsc+',\'absent\')">'
                + '<i class="fas fa-times mr-1"></i> All Absent</button>'
                + '<button class="btn-mark-all btn-mark-all-intimated" '
                + 'onclick="markBatchAll('+meetingId+','+occId+','+batchJsonEsc+',\'absent_intimated\')">'
                + '<i class="fas fa-user-clock mr-1"></i> All Intimated</button></span>';
        }
        html += '</div>';

        html += '<table class="att-table"><thead><tr>'
            + '<th>#</th><th>Name</th><th>ID</th><th>Status</th><th>Marked By</th>';
        if (canMarkAtt && !isCompleted) html += '<th>Mark</th>';
        html += '</tr></thead><tbody>';

        members.forEach(function(p, idx) {
            var att      = p.attendance || '';
            var label    = '—';
            if (att === 'absent_intimated') label = 'Absent (Intimated)';
            else if (att === 'on_leave')    label = '🏖️ On Leave';
            else if (att === 'on_tour')     label = '✈️ On Tour';
            else if (att === 'roster_off')  label = '🔒 Rostering Off';
            else if (att)                   label = att.charAt(0).toUpperCase() + att.slice(1);

            var cls      = att ? 'att-'+att : 'att-none';
            var isAuto   = (att === 'on_leave' || att === 'on_tour' || att === 'roster_off');
            var empId    = p.emp_id || p.internId || '—';
            // Unique element ID per session: meetingId + occId + userId
            var elemKey  = meetingId + '-' + occId + '-' + escHtml(p.userId);
            var markedBy = p.markedByName
                ? '<span class="marked-by-text">' + (isAuto ? '<i class="fas fa-magic mr-1" style="font-size:.65rem;color:#0284c7;"></i>' : '<i class="fas fa-user-check mr-1" style="font-size:.65rem;"></i>')
                  + escHtml(p.markedByName)+'</span>'
                : '<span class="text-muted" style="font-size:.72rem;">—</span>';

            html += '<tr>'
                + '<td class="text-muted">'+(idx+1)+'</td>'
                + '<td><strong>'+escHtml(p.fullName)+'</strong></td>'
                + '<td><code style="font-size:.73rem;">'+escHtml(empId)+'</code></td>'
                + '<td><span class="att-badge '+cls+'" id="mbadge-'+elemKey+'">'+label+'</span></td>'
                + '<td id="mmarked-'+elemKey+'">'+markedBy+'</td>';

            if (canMarkAtt && !isCompleted) {
                if (isAuto) {
                    html += '<td><span class="badge bg-light text-secondary border px-2 py-1" style="font-size:.72rem;font-weight:600;"><i class="fas fa-shield-alt text-info mr-1"></i> Auto Status</span></td>';
                } else {
                    var apCls = att==='present'          ? 'active-present'   : '';
                    var aaCls = att==='absent'           ? 'active-absent'    : '';
                    var aiCls = att==='absent_intimated' ? 'active-intimated'  : '';
                    html += '<td><div class="btn-group att-btn-group" id="mbtns-'+elemKey+'">'
                        + '<button class="btn btn-outline-success btn-sm '+apCls+'" '
                        + 'onclick="momMarkAtt('+meetingId+','+occId+',\''+escHtml(p.userId)+'\',\'present\',this)">'
                        + '<i class="fas fa-check mr-1"></i> Present</button>'
                        + '<button class="btn btn-outline-danger btn-sm '+aaCls+'" '
                        + 'onclick="momMarkAtt('+meetingId+','+occId+',\''+escHtml(p.userId)+'\',\'absent\',this)">'
                        + '<i class="fas fa-times mr-1"></i> Absent</button>'
                        + '<button class="btn btn-outline-warning btn-sm '+aiCls+'" '
                        + 'onclick="momMarkAtt('+meetingId+','+occId+',\''+escHtml(p.userId)+'\',\'absent_intimated\',this)">'
                        + '<i class="fas fa-user-clock mr-1"></i> Intimated</button>'
                        + '</div></td>';
                }
            }
            html += '</tr>';
        });
        html += '</tbody></table>';
    }
    attBody.innerHTML = html;
}

/* ─── momMarkAtt ───
   Uses meetingId+occId+userId as the element key (matches renderAttendance). */
function momMarkAtt(meetingId, occId, userId, status, btn) {
    if (momIsCompleted) return;
    var body = 'action=mark_attendance'
             + '&meeting_id='      + encodeURIComponent(meetingId)
             + '&occurrence_id='   + encodeURIComponent(occId)
             + '&target_user_id='  + encodeURIComponent(userId)
             + '&status='          + encodeURIComponent(status);

    fetch('meetingHistory.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: body
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        if (!data.success) { alert(data.error || 'Could not mark attendance.'); return; }

        var elemKey = meetingId + '-' + occId + '-' + userId;
        var badge   = document.getElementById('mbadge-' + elemKey);
        if (badge) {
            badge.textContent = status === 'absent_intimated' ? 'Absent (Intimated)' : (status.charAt(0).toUpperCase() + status.slice(1));
            badge.className   = 'att-badge att-' + status;
        }
        var markedCell = document.getElementById('mmarked-' + elemKey);
        var grp = document.getElementById('mbtns-' + elemKey);
        if (grp) {
            grp.querySelectorAll('.btn').forEach(function(b){
                b.classList.remove('active-present', 'active-absent', 'active-intimated');
            });
        }
        var actCls = status === 'absent_intimated' ? 'active-intimated' : ('active-' + status);
        btn.classList.add(actCls);
        updateSummaryBar(meetingId, occId);
    });
}

/* ─── markBatchAll ─── */
function markBatchAll(meetingId, occId, userIds, status) {
    if (momIsCompleted) return;
    var body = 'action=mark_batch_attendance'
             + '&meeting_id='    + encodeURIComponent(meetingId)
             + '&occurrence_id=' + encodeURIComponent(occId)
             + '&status='        + encodeURIComponent(status);
    userIds.forEach(function(uid){ body += '&user_ids[]=' + encodeURIComponent(uid); });

    fetch('meetingHistory.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: body
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        if (!data.success) { alert(data.error || 'Could not mark attendance.'); return; }
        userIds.forEach(function(uid) {
            var elemKey = meetingId + '-' + occId + '-' + uid;
            var badge   = document.getElementById('mbadge-' + elemKey);
            if (badge) {
                badge.textContent = status === 'absent_intimated' ? 'Absent (Intimated)' : (status.charAt(0).toUpperCase() + status.slice(1));
                badge.className   = 'att-badge att-' + status;
            }
            var grp = document.getElementById('mbtns-' + elemKey);
            if (grp) {
                grp.querySelectorAll('.btn').forEach(function(b){
                    b.classList.remove('active-present','active-absent','active-intimated');
                });
                var btnSelector = '.btn-outline-' + (status==='present'?'success':(status==='absent'?'danger':'warning'));
                var tBtn = grp.querySelector(btnSelector);
                if (tBtn) {
                    var actCls = status === 'absent_intimated' ? 'active-intimated' : ('active-' + status);
                    tBtn.classList.add(actCls);
                }
            }
        });
        updateSummaryBar(meetingId, occId);
    });
}

/* ─── updateSummaryBar ───
   FIX: Use meetingId+occId prefix so it counts only the current session's badges. */
function updateSummaryBar(meetingId, occId) {
    var prefix = 'mbadge-' + meetingId + '-' + occId + '-';
    var badges  = document.querySelectorAll('[id^="' + prefix + '"]');
    var total=badges.length, present=0, absent=0, absentIntimated=0, leaveTour=0;
    badges.forEach(function(b){
        if (b.classList.contains('att-present')) present++;
        else if (b.classList.contains('att-absent')) absent++;
        else if (b.classList.contains('att-absent_intimated')) absentIntimated++;
        else if (b.classList.contains('att-on_leave') || b.classList.contains('att-on_tour') || b.classList.contains('att-roster_off')) leaveTour++;
    });
    var unmarked = total - present - absent - absentIntimated - leaveTour;
    document.getElementById('att_total').textContent           = total;
    document.getElementById('att_present_count').textContent   = present;
    document.getElementById('att_absent_count').textContent    = absent;
    document.getElementById('att_intimated_count').textContent = absentIntimated;
    var ltEl = document.getElementById('att_leavetour_count');
    if (ltEl) ltEl.textContent = leaveTour;
    document.getElementById('att_unmarked_count').textContent  = unmarked;
    document.getElementById('att_summary_inline').textContent  =
        '(' + present + ' present, ' + absent + ' absent, ' + absentIntimated + ' intimated, ' + leaveTour + ' on leave/tour, ' + unmarked + ' unmarked)';
}

function renderPoints(meetingId, points, isCompleted) {
    var container = document.getElementById('mom_points_body');
    if (!points || points.length === 0) {
        container.innerHTML='<p class="text-muted" id="mom-no-pts" style="font-size:.85rem;"><i class="fas fa-info-circle mr-1"></i>No discussion points added yet.</p>';
        return;
    }
    var html='';
    points.forEach(function(pt,i){
        html+='<div class="point-item" data-index="'+i+'">'
            +'<span class="point-num">'+(i+1)+'.</span>'
            +'<span class="point-text" id="point-text-'+i+'">'+escHtml(pt)+'</span>'
            +'<input type="text" class="point-text-input" id="point-input-'+i+'" value="'+escHtml(pt)+'" style="display:none;">';
        if (!isCompleted && momCanMarkAtt) {
            html+='<div class="point-actions" id="point-actions-'+i+'">'
                +'<button class="btn-edit-point" onclick="editPoint('+i+')" title="Edit"><i class="fas fa-edit"></i></button>'
                +'<button class="btn-del-point" onclick="momDeletePoint('+meetingId+','+i+',this)" title="Delete"><i class="fas fa-times"></i></button>'
                +'</div>'
                +'<div class="point-actions" id="point-save-actions-'+i+'" style="display:none;">'
                +'<button class="btn-save-point" onclick="savePoint('+i+')" title="Save"><i class="fas fa-check"></i></button>'
                +'<button class="btn-cancel-point" onclick="cancelEditPoint('+i+')" title="Cancel"><i class="fas fa-times"></i></button>'
                +'</div>';
        }
        html+='</div>';
    });
    container.innerHTML=html;
}

function editPoint(index){
    var s=document.getElementById('point-text-'+index);
    var inp=document.getElementById('point-input-'+index);
    var na=document.getElementById('point-actions-'+index);
    var sa=document.getElementById('point-save-actions-'+index);
    if(s)s.style.display='none';
    if(inp){inp.style.display='block';inp.focus();inp.select();}
    if(na)na.style.display='none';
    if(sa)sa.style.display='flex';
}

function cancelEditPoint(index){
    var s=document.getElementById('point-text-'+index);
    var inp=document.getElementById('point-input-'+index);
    var na=document.getElementById('point-actions-'+index);
    var sa=document.getElementById('point-save-actions-'+index);
    if(s)s.style.display='block';
    if(inp)inp.style.display='none';
    if(na)na.style.display='flex';
    if(sa)sa.style.display='none';
}

function savePoint(index){
    if(momIsCompleted||!momCanMarkAtt)return;
    var input=document.getElementById('point-input-'+index);
    var newText=input?input.value.trim():'';
    if(!newText){alert('Point text cannot be empty.');return;}
    var body='action=edit_point&meeting_id='+currentMomMeetingId
        +'&occurrence_id='+currentOccurrenceId
        +'&point_index='+index
        +'&new_text='+encodeURIComponent(newText);
    fetch('meetingHistory.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body})
    .then(function(r){return r.json();})
    .then(function(data){
        if(!data.success){alert(data.error||'Could not update point.');return;}
        var s=document.getElementById('point-text-'+index);
        if(s){s.textContent=newText;s.style.display='block';}
        if(input)input.style.display='none';
        var na=document.getElementById('point-actions-'+index);
        var sa=document.getElementById('point-save-actions-'+index);
        if(na)na.style.display='flex';
        if(sa)sa.style.display='none';
    });
}

function momAddPoint(){
    if(momIsCompleted||!momCanMarkAtt)return;
    var input=document.getElementById('mom_new_point');
    var val=input.value.trim();
    if(!val||!currentMomMeetingId){input.focus();return;}
    var body='action=add_point&meeting_id='+currentMomMeetingId
        +'&occurrence_id='+currentOccurrenceId
        +'&point='+encodeURIComponent(val);
    fetch('meetingHistory.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body})
    .then(function(r){return r.json();})
    .then(function(data){
        if(!data.success)return;
        var container=document.getElementById('mom_points_body');
        var noMsg=document.getElementById('mom-no-pts'); if(noMsg)noMsg.remove();
        var idx=container.querySelectorAll('.point-item').length;
        var div=document.createElement('div');
        div.className='point-item';
        div.setAttribute('data-index',idx);
        div.innerHTML='<span class="point-num">'+(idx+1)+'.</span>'
            +'<span class="point-text" id="point-text-'+idx+'">'+escHtml(val)+'</span>'
            +'<input type="text" class="point-text-input" id="point-input-'+idx+'" value="'+escHtml(val)+'" style="display:none;">'
            +'<div class="point-actions" id="point-actions-'+idx+'">'
            +'<button class="btn-edit-point" onclick="editPoint('+idx+')" title="Edit"><i class="fas fa-edit"></i></button>'
            +'<button class="btn-del-point" onclick="momDeletePoint('+currentMomMeetingId+','+idx+',this)" title="Delete"><i class="fas fa-times"></i></button>'
            +'</div>'
            +'<div class="point-actions" id="point-save-actions-'+idx+'" style="display:none;">'
            +'<button class="btn-save-point" onclick="savePoint('+idx+')" title="Save"><i class="fas fa-check"></i></button>'
            +'<button class="btn-cancel-point" onclick="cancelEditPoint('+idx+')" title="Cancel"><i class="fas fa-times"></i></button>'
            +'</div>';
        container.appendChild(div);
        input.value='';
    });
}

function momDeletePoint(meetingId,index,btn){
    if(momIsCompleted||!momCanMarkAtt)return;
    if(!confirm('Remove this point?'))return;
    var body='action=delete_point&meeting_id='+meetingId
        +'&occurrence_id='+currentOccurrenceId
        +'&point_index='+index;
    fetch('meetingHistory.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body})
    .then(function(r){return r.json();})
    .then(function(data){
        if(!data.success)return;
        btn.closest('.point-item').remove();
        var container=document.getElementById('mom_points_body');
        container.querySelectorAll('.point-item').forEach(function(el,i){
            el.setAttribute('data-index',i);
            el.querySelector('.point-num').textContent=(i+1)+'.';
            var ts=el.querySelector('.point-text'); if(ts)ts.id='point-text-'+i;
            var inp=el.querySelector('.point-text-input'); if(inp)inp.id='point-input-'+i;
            var na=el.querySelector('[id^="point-actions-"]');
            if(na){
                na.id='point-actions-'+i;
                var eb=na.querySelector('.btn-edit-point'); if(eb)eb.setAttribute('onclick','editPoint('+i+')');
                var db=na.querySelector('.btn-del-point'); if(db)db.setAttribute('onclick','momDeletePoint('+meetingId+','+i+',this)');
            }
            var sa=el.querySelector('[id^="point-save-actions-"]');
            if(sa){
                sa.id='point-save-actions-'+i;
                var sb=sa.querySelector('.btn-save-point'); if(sb)sb.setAttribute('onclick','savePoint('+i+')');
                var cb=sa.querySelector('.btn-cancel-point'); if(cb)cb.setAttribute('onclick','cancelEditPoint('+i+')');
            }
        });
        if(!container.querySelectorAll('.point-item').length)
            container.innerHTML='<p class="text-muted" id="mom-no-pts" style="font-size:.85rem;"><i class="fas fa-info-circle mr-1"></i>No discussion points added yet.</p>';
    });
}

/* ─── Edit / Reschedule ─── */
var editParticipantsSelect2Initialized = false;

function openEdit(data) {
    if (!data) return;
    document.getElementById('edit_meeting_id').value  = data.id || '';
    document.getElementById('edit_agenda').value      = data.agenda || '';
    document.getElementById('edit_description').value = data.description || '';
    document.getElementById('edit_date').value        = data.meeting_date || '';
    document.getElementById('edit_time').value        = data.meeting_time || '';
    document.getElementById('edit_link').value        = data.meeting_link || '';
    document.querySelectorAll('input[name="edit_branch"]').forEach(function(r){ r.checked=false; });
    var br=document.querySelector('input[name="edit_branch"][value="'+(data.branch||'')+'"]'); if(br)br.checked=true;
    document.querySelectorAll('input[name="edit_mode"]').forEach(function(r){ r.checked=false; });
    var modeVal = (data.mode || '').toLowerCase();
    var mo=document.querySelector('input[name="edit_mode"][value="'+modeVal+'"]'); if(mo)mo.checked=true;
    toggleEditLink();

    // Populate Recurrence fields if present
    var hasRecur = !!data.recurrence_type;
    var editRecurChk = document.getElementById('editRecurToggle');
    if (editRecurChk) {
        editRecurChk.checked = hasRecur;
        toggleEditRecurrence();
        if (hasRecur) {
            var freqRadio = document.querySelector('input[name="edit_freq_ui"][value="'+data.recurrence_type+'"]');
            if (freqRadio) freqRadio.checked = true;
            onEditFreqChange();

            // Set active days
            var activeDays = (data.recurrence_days || '').split(',').map(function(d){ return d.trim().toLowerCase(); });
            document.querySelectorAll('.edit-day-chk').forEach(function(chk){
                chk.checked = activeDays.indexOf(chk.value.toLowerCase()) !== -1;
            });

            // Set end date
            var endEl = document.getElementById('editRecurEndDate');
            if (endEl) endEl.value = data.recurrence_end || '';

            // Set extra time slots
            var slotsList = document.getElementById('editExtraSlotsList');
            if (slotsList) {
                slotsList.innerHTML = '';
                var slots = (data.time_slots || '').split(',').map(function(s){ return s.trim(); }).filter(Boolean);
                // Ignore primary time if listed first
                if (slots.length > 1 && slots[0] === data.meeting_time) slots.shift();
                slots.forEach(function(slotTime){
                    addEditTimeSlot(slotTime);
                });
            }
        }
    }

    var currentParticipants = data.participants
        ? data.participants.split(',').map(function(p){return p.trim();}).filter(Boolean)
        : [];
    var currentCoHosts = data.co_hosts
        ? data.co_hosts.split(',').map(function(c){return c.trim();}).filter(Boolean)
        : [];
    loadEditParticipants(currentParticipants, currentCoHosts);
    $('#editModal').modal('show');
}

var editSlotCount = 0;
function toggleEditRecurrence() {
    var chk = document.getElementById('editRecurToggle');
    var body = document.getElementById('editRecurBody');
    var wrap = document.getElementById('editRecurToggleWrap');
    if (!chk || !body || !wrap) return;
    var open = chk.checked;
    body.classList.toggle('open', open);
    wrap.classList.toggle('active', open);
}

function onEditFreqChange() {
    var freqEl = document.querySelector('input[name="edit_freq_ui"]:checked');
    if (!freqEl) return;
    var freq = freqEl.value;
    var dRow = document.getElementById('editDaysOfWeekRow');
    if (freq === 'weekly') {
        dRow.style.display = 'block';
        document.getElementById('editDaysLabel').textContent = 'On this day of the week';
    } else {
        dRow.style.display = 'none';
    }
}

function addEditTimeSlot(val) {
    editSlotCount++;
    var id = 'editslot_' + editSlotCount;
    var list = document.getElementById('editExtraSlotsList');
    var ho = '<option value="">hh</option>';
    for (var h=1; h<=12; h++) ho += '<option value="'+h+'">'+String(h).padStart(2,'0')+'</option>';
    var mo = '<option value="">mm</option>';
    for (var m=0; m<60; m+=5) mo += '<option value="'+m+'">'+String(m).padStart(2,'0')+'</option>';
    var item = document.createElement('div');
    item.className = 'time-slot-item'; item.id = id;
    item.innerHTML = '<span class="slot-label">Slot '+editSlotCount+'</span>'
        +'<select class="form-control select-hour slot-h">'+ho+'</select>'
        +'<span class="time-sep" style="font-size:.9rem;">:</span>'
        +'<select class="form-control select-min slot-m">'+mo+'</select>'
        +'<select class="form-control select-ampm slot-ap"><option value="AM">AM</option><option value="PM">PM</option></select>'
        +'<button type="button" class="btn-rm-slot" onclick="document.getElementById(\''+id+'\').remove()" title="Remove"><i class="fas fa-times-circle"></i></button>';
    list.appendChild(item);

    if (val) {
        var parts = val.split(':');
        if (parts.length >= 2) {
            var h = parseInt(parts[0]);
            var m = parseInt(parts[1]);
            var ap = h >= 12 ? 'PM' : 'AM';
            var h12 = h % 12; if (h12 === 0) h12 = 12;
            item.querySelector('.slot-h').value = h12;
            item.querySelector('.slot-m').value = m;
            item.querySelector('.slot-ap').value = ap;
        }
    }
}

var editCohostsSelect2Initialized = false;

function loadEditParticipants(currentParticipants, currentCoHosts){
    var loadingEl=document.getElementById('edit_participants_loading');
    var selectEl=document.getElementById('edit_participants_select');
    var coSelectEl=document.getElementById('edit_cohosts_select');
    loadingEl.style.display='block'; selectEl.style.display='none'; if(coSelectEl) coSelectEl.style.display='none';
    if(allUsersCache){
        populateEditParticipants(allUsersCache,currentParticipants,currentCoHosts);
        renderEditBatchControls(allBatchesCache, batchMembersCache);
        return;
    }
    fetch('meetingHistory.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=get_all_users'})
    .then(function(r){return r.json();})
    .then(function(data){
        if(data.success){
            allUsersCache=data.users;
            allBatchesCache=data.batches||[];
            batchMembersCache=data.batchMembers||{};
            populateEditParticipants(data.users,currentParticipants,currentCoHosts);
            renderEditBatchControls(data.batches, data.batchMembers);
        }
        else { loadingEl.textContent='Failed to load users.'; }
    })
    .catch(function(){ loadingEl.textContent='Network error loading users.'; });
}

function renderEditBatchControls(batches, batchMembers) {
    var wrapEl = document.getElementById('edit_batch_dropdown_wrap');
    var itemsEl = document.getElementById('edit_batch_dropdown_items');
    var chipsEl = document.getElementById('edit_batch_chips');

    if (!wrapEl || !itemsEl || !chipsEl) return;

    itemsEl.innerHTML = '';
    chipsEl.innerHTML = '';

    if (!batches || batches.length === 0) {
        itemsEl.innerHTML = '<div class="dropdown-item disabled font-italic text-muted py-2 px-3" style="font-size:.8rem;">No active batches found</div>';
        wrapEl.style.display = 'block';
        return;
    }

    var totalUsers = allUsersCache ? allUsersCache.length : 0;
    chipsEl.innerHTML += '<span class="badge badge-light border font-weight-600 px-2.5 py-1.5" style="cursor:pointer; font-size:.76rem; border-radius:15px; background:#f0f4ff; border-color:#b8c8ff!important; color:#2f55ff;" onclick="addBatchToEditParticipants(\'__ALL__\')"><i class="fas fa-plus-circle mr-1"></i> All Batches (' + totalUsers + ')</span>';

    batches.forEach(function(b) {
        var nameKey = (b.batchName || '').toLowerCase().trim();
        var codeKey = (b.batchCode || '').toLowerCase().trim();
        var members = (batchMembers[nameKey] || batchMembers[codeKey] || []);
        var mCount = members.length;

        var itemA = document.createElement('a');
        itemA.className = 'dropdown-item d-flex align-items-center justify-content-between py-2 px-3';
        itemA.href = '#';
        itemA.style.fontSize = '.83rem';
        itemA.onclick = function(e) {
            e.preventDefault();
            addBatchToEditParticipants(b.batchName || b.batchCode);
        };

        var titleSpan = document.createElement('span');
        var bLabel = b.batchName || b.batchCode;
        titleSpan.innerHTML = '<i class="fas fa-layer-group mr-2 text-muted" style="font-size:.75rem;"></i>' + bLabel;
        if (b.batchCode && b.batchName && b.batchCode !== b.batchName) {
            titleSpan.innerHTML += ' <small class="text-muted">(' + b.batchCode + ')</small>';
        }

        var badgeSpan = document.createElement('span');
        badgeSpan.className = 'badge badge-pill badge-light border text-muted font-weight-bold ml-2';
        badgeSpan.style.fontSize = '.7rem';
        badgeSpan.textContent = mCount + ' members';

        itemA.appendChild(titleSpan);
        itemA.appendChild(badgeSpan);
        itemsEl.appendChild(itemA);

        if (mCount > 0) {
            var chipSpan = document.createElement('span');
            chipSpan.className = 'badge badge-light border font-weight-600 px-2 py-1';
            chipSpan.style.cssText = 'cursor:pointer; font-size:.76rem; border-radius:15px; background:#fff; border-color:#dce2f0!important; color:#334155; transition:all .15s ease;';
            chipSpan.onmouseover = function(){ this.style.borderColor = '#2f55ff'; this.style.background = '#f0f4ff'; this.style.color = '#2f55ff'; };
            chipSpan.onmouseout = function(){ this.style.borderColor = '#dce2f0'; this.style.background = '#fff'; this.style.color = '#334155'; };
            chipSpan.onclick = function() {
                addBatchToEditParticipants(b.batchName || b.batchCode);
            };
            chipSpan.innerHTML = '<i class="fas fa-plus-circle text-primary mr-1"></i>' + bLabel + ' <span class="badge badge-secondary ml-1" style="font-size:.65rem; padding:2px 5px;">' + mCount + '</span>';
            chipsEl.appendChild(chipSpan);
        }
    });

    wrapEl.style.display = 'block';
    chipsEl.style.display = 'flex';
}

function addBatchToEditParticipants(batchKey) {
    var memberIds = [];

    if (batchKey === '__ALL__') {
        if (allUsersCache && allUsersCache.length > 0) {
            memberIds = allUsersCache.map(function(u){ return String(u.userId); });
        }
    } else {
        var keyLower = String(batchKey).toLowerCase().trim();
        if (batchMembersCache) {
            memberIds = batchMembersCache[keyLower] || [];
        }
        if ((!memberIds || memberIds.length === 0) && allBatchesCache) {
            var foundB = allBatchesCache.find(function(b){
                return (b.batchName && b.batchName.toLowerCase().trim() === keyLower) ||
                       (b.batchCode && b.batchCode.toLowerCase().trim() === keyLower);
            });
            if (foundB) {
                var nk = (foundB.batchName || '').toLowerCase().trim();
                var ck = (foundB.batchCode || '').toLowerCase().trim();
                memberIds = batchMembersCache[nk] || batchMembersCache[ck] || [];
            }
        }
    }

    if (!memberIds || memberIds.length === 0) {
        if (typeof Swal !== 'undefined') {
            Swal.fire({icon: 'info', title: 'No Members', text: 'No active members found for this batch.', timer: 2000, showConfirmButton: false});
        } else {
            alert('No active members found in this batch.');
        }
        return;
    }

    var $select = $('#edit_participants_select');
    var currentValues = $select.val() || [];
    var currentSet = {};
    currentValues.forEach(function(v){ currentSet[String(v)] = true; });

    var addedCount = 0;
    memberIds.forEach(function(id) {
        var idStr = String(id);
        if (!currentSet[idStr]) {
            currentSet[idStr] = true;
            currentValues.push(idStr);
            addedCount++;
        }
    });

    $select.val(currentValues).trigger('change');

    if (typeof Swal !== 'undefined') {
        Swal.fire({
            icon: 'success',
            title: addedCount > 0 ? addedCount + ' Participant(s) Added' : 'Already Selected',
            text: addedCount > 0 ? 'Batch members added to participants.' : 'All members of this batch are already selected.',
            timer: 1600,
            showConfirmButton: false,
            toast: true,
            position: 'top-end'
        });
    }
}

function populateEditParticipants(users,currentParticipants,currentCoHosts){
    var loadingEl=document.getElementById('edit_participants_loading');
    var selectEl=document.getElementById('edit_participants_select');
    var coSelectEl=document.getElementById('edit_cohosts_select');

    if(editParticipantsSelect2Initialized){ try{$('#edit_participants_select').select2('destroy');}catch(e){} editParticipantsSelect2Initialized=false; }
    if(editCohostsSelect2Initialized){ try{$('#edit_cohosts_select').select2('destroy');}catch(e){} editCohostsSelect2Initialized=false; }

    selectEl.innerHTML='';
    if(coSelectEl) coSelectEl.innerHTML='';

    users.forEach(function(u){
        var opt1=document.createElement('option');
        opt1.value=u.userId;
        opt1.textContent=u.fullName+(u.id?' ('+u.id+')':'');
        if(currentParticipants.indexOf(String(u.userId))!==-1)opt1.selected=true;
        selectEl.appendChild(opt1);

        if(coSelectEl){
            var opt2=document.createElement('option');
            opt2.value=u.userId;
            opt2.textContent=u.fullName+(u.id?' ('+u.id+')':'');
            if(currentCoHosts && currentCoHosts.indexOf(String(u.userId))!==-1)opt2.selected=true;
            coSelectEl.appendChild(opt2);
        }
    });

    loadingEl.style.display='none'; selectEl.style.display='block';
    if(coSelectEl) coSelectEl.style.display='block';

    $('#edit_participants_select').select2({
        placeholder:'Search and select participants…',
        width:'100%',closeOnSelect:false,allowClear:true,
        dropdownParent:$('#editModal')
    });
    if(coSelectEl){
        $('#edit_cohosts_select').select2({
            placeholder:'Search and select co-hosts…',
            width:'100%',closeOnSelect:false,allowClear:true,
            dropdownParent:$('#editModal')
        });
        editCohostsSelect2Initialized=true;
    }
    editParticipantsSelect2Initialized=true;
}

function toggleEditLink(){
    var online=document.getElementById('edit_online');
    var grp=document.getElementById('editLinkGroup');
    if(online&&online.checked){grp.style.maxHeight='100px';grp.style.opacity='1';grp.classList.remove('hidden');}
    else{grp.classList.add('hidden');}
}

document.addEventListener('DOMContentLoaded',function(){
    document.querySelectorAll('input[name="edit_mode"]').forEach(function(r){
        r.addEventListener('change',toggleEditLink);
    });
});

function saveEdit(){
    var mid=document.getElementById('edit_meeting_id').value;
    var meetingDate=document.getElementById('edit_date').value;
    var meetingTime=document.getElementById('edit_time').value;
    
    if(!meetingDate||!meetingTime){ alert('Please select date and time.'); return; }

    var modeEl=document.querySelector('input[name="edit_mode"]:checked');
    var brEl=document.querySelector('input[name="edit_branch"]:checked');
    var selectedParticipants=$('#edit_participants_select').val()||[];
    var participantsParam=selectedParticipants.map(function(p){return 'participants[]='+encodeURIComponent(p);}).join('&');

    var selectedCoHosts=$('#edit_cohosts_select').val()||[];
    var coHostsParam=selectedCoHosts.map(function(c){return 'co_hosts[]='+encodeURIComponent(c);}).join('&');

    // Collect recurrence settings
    var recurType = '';
    var recurDays = '';
    var recurEnd  = '';
    var timeSlots = '';

    var editRecurChk = document.getElementById('editRecurToggle');
    if (editRecurChk && editRecurChk.checked) {
        var freqEl = document.querySelector('input[name="edit_freq_ui"]:checked');
        recurType = freqEl ? freqEl.value : 'daily';
        
        var selectedDays = Array.from(document.querySelectorAll('.edit-day-chk:checked')).map(function(c){ return c.value; });
        recurDays = selectedDays.join(',');
        
        recurEnd = document.getElementById('editRecurEndDate').value;

        var slots = [meetingTime];
        document.querySelectorAll('#editExtraSlotsList .time-slot-item').forEach(function(item){
            var h  = parseInt(item.querySelector('.slot-h').value);
            var m  = parseInt(item.querySelector('.slot-m').value);
            var ap = item.querySelector('.slot-ap').value;
            if (!isNaN(h) && !isNaN(m)) {
                var h24 = h;
                if (ap==='AM'){ if(h===12)h24=0; } else { if(h!==12)h24=h+12; }
                slots.push(String(h24).padStart(2,'0')+':'+String(m).padStart(2,'0'));
            }
        });
        timeSlots = slots.join(',');
    }

    var params='action=edit_meeting&meeting_id='+encodeURIComponent(mid)
        +'&agenda='+encodeURIComponent(document.getElementById('edit_agenda').value)
        +'&description='+encodeURIComponent(document.getElementById('edit_description').value)
        +'&meeting_date='+encodeURIComponent(document.getElementById('edit_date').value)
        +'&meeting_time='+encodeURIComponent(document.getElementById('edit_time').value)
        +'&mode='+encodeURIComponent(modeEl?modeEl.value:'online')
        +'&meeting_link='+encodeURIComponent(document.getElementById('edit_link').value)
        +'&branch='+encodeURIComponent(brEl?brEl.value:'')
        +'&recurrence_type='+encodeURIComponent(recurType)
        +'&recurrence_days='+encodeURIComponent(recurDays)
        +'&recurrence_end='+encodeURIComponent(recurEnd)
        +'&time_slots='+encodeURIComponent(timeSlots)
        +(participantsParam?'&'+participantsParam:'')
        +(coHostsParam?'&'+coHostsParam:'');

    fetch('meetingHistory.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:params})
    .then(function(r){return r.json();})
    .then(function(data){
        if(data.success){
            $('#editModal').modal('hide');
            showToast('Meeting updated successfully!','success');
            setTimeout(function(){location.reload();},1200);
        } else { alert(data.error||'Could not save changes.'); }
    });
}

function openReschedule(data){
    document.getElementById('reschedule_meeting_id').value=data.id;
    document.getElementById('reschedule_agenda_display').textContent=data.agenda;
    document.getElementById('reschedule_new_date').value='';
    document.getElementById('reschedule_new_time').value='';
    document.getElementById('reschedule_note').value='';
    document.getElementById('reschedule_error').style.display='none';
    var origDate=new Date(data.meeting_date+'T'+data.meeting_time);
    var opts={day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit',hour12:true};
    document.getElementById('reschedule_original_dt').textContent=
        isNaN(origDate)?(data.meeting_date+' '+data.meeting_time):origDate.toLocaleString('en-IN',opts);
    document.getElementById('reschedule_new_date').min=new Date().toISOString().split('T')[0];
    $('#rescheduleModal').modal('show');
}

function saveReschedule(){
    var mid=document.getElementById('reschedule_meeting_id').value;
    var newDate=document.getElementById('reschedule_new_date').value;
    var newTime=document.getElementById('reschedule_new_time').value;
    var note=document.getElementById('reschedule_note').value.trim();
    var errDiv=document.getElementById('reschedule_error');
    var btn=document.getElementById('reschedule_save_btn');
    errDiv.style.display='none';
    if(!newDate||!newTime){errDiv.textContent='Please select both a new date and time.';errDiv.style.display='block';return;}
    if(newTime < "09:00" || newTime > "18:00") {errDiv.textContent='Please select a time within office hours (09:00 AM - 06:00 PM).';errDiv.style.display='block';return;}

    var todayStr = new Date().getFullYear() + '-' + 
                   String(new Date().getMonth() + 1).padStart(2, '0') + '-' + 
                   String(new Date().getDate()).padStart(2, '0');
    if (newDate < todayStr) {
        errDiv.textContent='Rescheduled date cannot be in the past.';
        errDiv.style.display='block';
        return;
    }
    if (newDate === todayStr) {
        var now = new Date();
        var currentHours = String(now.getHours()).padStart(2, '0');
        var currentMins = String(now.getMinutes()).padStart(2, '0');
        var currentTimeStr = currentHours + ':' + currentMins;
        if (newTime < currentTimeStr) {
            errDiv.textContent='Rescheduled time cannot be in the past for today.';
            errDiv.style.display='block';
            return;
        }
    }
    btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin mr-1"></i> Saving…';
    fetch('meetingHistory.php',{
        method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'action=reschedule_meeting&meeting_id='+encodeURIComponent(mid)
            +'&new_date='+encodeURIComponent(newDate)
            +'&new_time='+encodeURIComponent(newTime)
            +'&reschedule_note='+encodeURIComponent(note)
    })
    .then(function(r){return r.json();})
    .then(function(data){
        btn.disabled=false;
        btn.innerHTML='<i class="fas fa-calendar-check mr-1"></i> Confirm Reschedule';
        if(!data.success){errDiv.textContent=data.error||'Could not reschedule.';errDiv.style.display='block';return;}
        var dateCell=document.getElementById('mdate-'+mid);
        var timeCell=document.getElementById('mtime-'+mid);
        var statusEl=document.getElementById('mstatus-'+mid);
        if(dateCell){var d=new Date(newDate+'T00:00:00');dateCell.textContent=d.toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'});}
        if(timeCell){var t=new Date('1970-01-01T'+newTime);timeCell.textContent=isNaN(t)?newTime:t.toLocaleTimeString('en-IN',{hour:'2-digit',minute:'2-digit',hour12:true});}
        if(statusEl){statusEl.className='badge-status badge-upcoming';statusEl.textContent='Upcoming';}
        $('#rescheduleModal').modal('hide');
        showToast('Meeting rescheduled successfully!','success');
    })
    .catch(function(){
        btn.disabled=false;
        btn.innerHTML='<i class="fas fa-calendar-check mr-1"></i> Confirm Reschedule';
        errDiv.textContent='Network error. Please try again.';
        errDiv.style.display='block';
    });
}

function showToast(msg,type){
    var toast=document.createElement('div');
    toast.style.cssText='position:fixed;bottom:24px;right:24px;z-index:9999;background:'
        +(type==='success'?'#2e7d32':'#c62828')
        +';color:#fff;padding:10px 20px;border-radius:8px;font-size:.85rem;font-weight:600;'
        +'box-shadow:0 4px 16px rgba(0,0,0,.18);animation:fadeInUp .25s ease';
    toast.textContent=msg;
    document.body.appendChild(toast);
    setTimeout(function(){toast.remove();},3200);
}

function escHtml(str){
    return String(str)
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;');
}

function downloadMomPDF() {
    try {
        var jsPDFConstructor = null;
        if (window.jspdf && window.jspdf.jsPDF) {
            jsPDFConstructor = window.jspdf.jsPDF;
        } else if (window.jsPDF) {
            jsPDFConstructor = window.jsPDF;
        }

        if (!jsPDFConstructor) {
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    title: 'PDF Library Loading',
                    text: 'The PDF generation engine is initializing. Please wait a few seconds and try again.',
                    icon: 'info'
                });
            } else {
                alert('PDF library is loading. Please try again in a moment.');
            }
            return;
        }

        const doc = new jsPDFConstructor('p', 'mm', 'a4');

        var agendaTitle = (document.getElementById('mom_agenda_title').textContent || 'Meeting MOM').trim();
        var isRecurring = momIsRecurring;
        var occ = isRecurring ? spSelectedOcc : null;

        var dateStr = occ ? occ.occurrence_date : (momMeetingDate || '');
        var timeStr = occ ? occ.occurrence_time : (momMeetingTime || '');

        var formattedDate = 'N/A';
        if (dateStr) {
            var dParts = dateStr.split('-');
            if (dParts.length === 3) {
                var dObj = new Date(parseInt(dParts[0]), parseInt(dParts[1]) - 1, parseInt(dParts[2]));
                formattedDate = dObj.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
            } else {
                formattedDate = dateStr;
            }
        }

        var formattedTime = timeStr ? formatTime12(timeStr) : 'N/A';

        var statusText = 'Pending';
        if (occ) {
            statusText = (occ.status === 'completed') ? 'Completed' : 'Pending';
        } else {
            statusText = momIsCompleted ? 'Completed' : 'Pending';
        }

        // Discussion Points
        var points = [];
        var ptElements = document.querySelectorAll('#mom_points_body .point-text');
        ptElements.forEach(function(el) {
            var txt = el.textContent.trim();
            if (txt) points.push(txt);
        });

        // Attendance Data
        var attRows = [];
        var totalAtt = 0, presentAtt = 0, absentAtt = 0, intimatedAtt = 0;

        var attTableRows = document.querySelectorAll('#mom_att_body table.att-table tbody tr');
        attTableRows.forEach(function(tr) {
            var tds = tr.querySelectorAll('td');
            if (tds.length >= 4) {
                var nameText  = tds[1].textContent.trim();
                var badge     = tds[3].querySelector('.att-badge');
                var statusVal = badge ? badge.textContent.trim() : tds[3].textContent.trim();

                if (nameText) {
                    totalAtt++;
                    var sLower = statusVal.toLowerCase();
                    if (sLower.includes('present')) presentAtt++;
                    else if (sLower.includes('absent') && !sLower.includes('intimated')) absentAtt++;
                    else if (sLower.includes('intimated')) intimatedAtt++;

                    // Clean statusVal to strip emojis / garbled UTF-8 chars for PDF export
                    var cleanStatus = 'Present';
                    if (sLower.includes('intimated')) {
                        cleanStatus = 'Absent (Intimated)';
                    } else if (sLower.includes('leave')) {
                        cleanStatus = 'On Leave';
                    } else if (sLower.includes('tour')) {
                        cleanStatus = 'On Tour';
                    } else if (sLower.includes('roster')) {
                        cleanStatus = 'Rostering Off';
                    } else if (sLower.includes('absent')) {
                        cleanStatus = 'Absent';
                    } else if (sLower.includes('present')) {
                        cleanStatus = 'Present';
                    } else {
                        cleanStatus = statusVal.replace(/[\u{1F300}-\u{1F9FF}]|[\u{2600}-\u{26FF}]|[\u{2700}-\u{27BF}]|[\u{1F600}-\u{1F64F}]|[\u{1F680}-\u{1F6FF}]|[\u{1F1E0}-\u{1F1FF}]/gu, '').trim();
                    }

                    attRows.push([totalAtt, nameText, cleanStatus]);
                }
            }
        });

        const primaryColor = [75, 73, 172];   // SETTribe #4b49ac
        const darkSlate    = [30, 41, 59];    // #1e293b
        const grayBg       = [248, 250, 252];  // #f8fafc

        // Header Banner
        doc.setFillColor(...primaryColor);
        doc.rect(0, 0, 210, 24, 'F');

        doc.setTextColor(255, 255, 255);
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(15);
        doc.text('SETTribe IT Solutions', 14, 11.5);

        doc.setFontSize(9.5);
        doc.setFont('helvetica', 'normal');
        doc.text('MINUTES OF MEETING (MOM)', 14, 18);

        doc.setFontSize(9);
        doc.text('Date: ' + formattedDate, 196, 15, { align: 'right' });

        let currentY = 32;

        // Meeting Info Box
        doc.setFillColor(...grayBg);
        doc.setDrawColor(226, 232, 240);
        doc.roundedRect(14, currentY, 182, 40, 3, 3, 'FD');

        doc.setFont('helvetica', 'bold');
        doc.setFontSize(11);
        doc.setTextColor(...darkSlate);

        var agendaLines = doc.splitTextToSize('Agenda: ' + agendaTitle, 174);
        doc.text(agendaLines, 18, currentY + 8);

        doc.setFontSize(9);
        doc.setFont('helvetica', 'normal');
        doc.setTextColor(100, 116, 139);
        doc.text('Host: ' + (momHostName || 'N/A'), 18, currentY + 17);
        doc.text('Co-Host(s): ' + (momCoHostNames || 'None'), 105, currentY + 17);
        doc.text('Date & Time: ' + formattedDate + ' at ' + formattedTime, 18, currentY + 25);
        doc.text('Session Status: ' + statusText, 18, currentY + 32);
        doc.text('Type: ' + (isRecurring ? 'Recurring Session' : 'One-time Meeting'), 105, currentY + 25);

        currentY += 48;

        // Discussion Points Section
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(11);
        doc.setTextColor(...primaryColor);
        doc.text('1. Minutes of Meeting (Discussion Points & Action Items)', 14, currentY);

        currentY += 6;

        if (points.length === 0) {
            doc.setFont('helvetica', 'italic');
            doc.setFontSize(9);
            doc.setTextColor(148, 163, 184);
            doc.text('No discussion points recorded for this session.', 18, currentY);
            currentY += 10;
        } else {
            doc.setFont('helvetica', 'normal');
            doc.setFontSize(9.5);
            doc.setTextColor(51, 65, 85);

            points.forEach(function(pt, i) {
                var bullet = (i + 1) + '. ';
                var lines = doc.splitTextToSize(bullet + pt, 174);
                if (currentY + (lines.length * 5) > 270) {
                    doc.addPage();
                    currentY = 20;
                }
                doc.text(lines, 18, currentY);
                currentY += (lines.length * 5) + 2;
            });
            currentY += 4;
        }

        // Attendance Section
        if (currentY > 220) {
            doc.addPage();
            currentY = 20;
        }

        doc.setFont('helvetica', 'bold');
        doc.setFontSize(11);
        doc.setTextColor(...primaryColor);
        doc.text('2. Attendance Record', 14, currentY);

        doc.setFontSize(9);
        doc.setFont('helvetica', 'normal');
        doc.setTextColor(100, 116, 139);
        doc.text('Total: ' + totalAtt + '  |  Present: ' + presentAtt + '  |  Absent: ' + absentAtt + '  |  Intimated: ' + intimatedAtt, 196, currentY, { align: 'right' });

        currentY += 4;

        if (attRows.length > 0 && typeof doc.autoTable === 'function') {
            doc.autoTable({
                startY: currentY,
                head: [['#', 'Participant Name', 'Status']],
                body: attRows,
                theme: 'grid',
                headStyles: { fillColor: primaryColor, textColor: 255, fontSize: 9, fontStyle: 'bold' },
                bodyStyles: { fontSize: 8.5, textColor: [51, 65, 85] },
                columnStyles: {
                    0: { cellWidth: 16, halign: 'center' },
                    1: { cellWidth: 122 },
                    2: { cellWidth: 44, halign: 'center' }
                },
                didParseCell: function(data) {
                    if (data.section === 'body' && data.column.index === 2) {
                        var txt = data.cell.raw ? data.cell.raw.toString().toLowerCase() : '';
                        if (txt.includes('present')) {
                            data.cell.styles.textColor = [46, 125, 50];
                            data.cell.styles.fontStyle = 'bold';
                        } else if (txt.includes('absent') && !txt.includes('intimated')) {
                            data.cell.styles.textColor = [198, 40, 40];
                            data.cell.styles.fontStyle = 'bold';
                        } else if (txt.includes('intimated')) {
                            data.cell.styles.textColor = [180, 83, 9];
                            data.cell.styles.fontStyle = 'bold';
                        } else if (txt.includes('leave')) {
                            data.cell.styles.textColor = [3, 105, 161];
                            data.cell.styles.fontStyle = 'bold';
                        } else if (txt.includes('tour')) {
                            data.cell.styles.textColor = [126, 34, 206];
                            data.cell.styles.fontStyle = 'bold';
                        } else if (txt.includes('roster')) {
                            data.cell.styles.textColor = [71, 85, 105];
                            data.cell.styles.fontStyle = 'bold';
                        }
                    }
                },
                margin: { left: 14, right: 14 }
            });
            currentY = doc.lastAutoTable.finalY + 12;
        } else {
            currentY += 6;
            doc.setFont('helvetica', 'italic');
            doc.setFontSize(9);
            doc.setTextColor(148, 163, 184);
            doc.text('No attendance record found.', 18, currentY);
            currentY += 10;
        }

        // Page Numbers & Footer
        const pageCount = doc.internal.getNumberOfPages();
        for (let i = 1; i <= pageCount; i++) {
            doc.setPage(i);
            doc.setFont('helvetica', 'normal');
            doc.setFontSize(8);
            doc.setTextColor(148, 163, 184);
            doc.text('Generated on ' + new Date().toLocaleString('en-GB') + ' · SETTribe AMS', 14, 287);
            doc.text('Page ' + i + ' of ' + pageCount, 196, 287, { align: 'right' });
        }

        var safeName = agendaTitle.replace(/[^a-z0-9]/gi, '_').toLowerCase();
        doc.save('MOM_' + safeName + '_' + (dateStr || 'session') + '.pdf');
    } catch (err) {
        console.error('MOM PDF Generation Error:', err);
        alert('Could not generate PDF: ' + err.message);
    }
}

function copyWhatsAppInvite(data) {
    if (!data) return;

    var agenda       = data.agenda || 'Meeting';
    var dateVal      = data.date || '';
    var timeVal      = data.time || '';
    var modeVal      = data.mode || 'Online';
    var branchVal    = data.branch || '';
    var hostVal      = data.host || '';
    var cohostsVal   = data.cohosts || '';
    var descVal      = data.description || '';
    var linkVal      = data.link || '';
    var participantsVal = data.participants || '';

    var msg = "📌 *Meeting Invitation: " + agenda + "*\n\n";
    if (dateVal) msg += "📅 *Date:* " + dateVal + "\n";
    if (timeVal) msg += "⏰ *Time:* " + timeVal + "\n";
    if (modeVal) msg += "💻 *Mode:* " + modeVal + "\n";
    if (branchVal && branchVal !== 'All' && branchVal !== '—') msg += "🏢 *Branch:* " + branchVal + "\n";
    if (hostVal && hostVal !== 'Unknown') msg += "👤 *Host:* " + hostVal + "\n";
    if (cohostsVal && cohostsVal !== 'None' && cohostsVal !== '—' && cohostsVal !== '') msg += "👑 *Co-Host(s):* " + cohostsVal + "\n";

    if (descVal && descVal.trim()) {
        msg += "\n📝 *Agenda & Details:*\n" + descVal.trim() + "\n";
    }

    if (linkVal && linkVal.trim()) {
        msg += "\n🔗 *Meeting Link:*\n" + linkVal.trim() + "\n";
    }

    if (participantsVal && participantsVal.trim()) {
        msg += "\n👥 *Participants:*\n" + participantsVal.trim() + "\n";
    }

    msg += "\n------------------------------\n";
    msg += "Please join on time!";

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(msg).then(function() {
            showToast('✅ WhatsApp meeting invitation copied to clipboard!', 'success');
        }).catch(function() {
            fallbackCopyText(msg);
        });
    } else {
        fallbackCopyText(msg);
    }
}

function fallbackCopyText(text) {
    var textArea = document.createElement("textarea");
    textArea.value = text;
    textArea.style.position = "fixed";
    textArea.style.top = "0";
    textArea.style.left = "0";
    textArea.style.opacity = "0";
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();
    try {
        var successful = document.execCommand('copy');
        if (successful) {
            showToast('✅ WhatsApp meeting invitation copied to clipboard!', 'success');
        } else {
            showToast('Failed to copy to clipboard', 'error');
        }
    } catch (err) {
        showToast('Failed to copy to clipboard', 'error');
    }
    document.body.removeChild(textArea);
}
</script>
</body>
</html>