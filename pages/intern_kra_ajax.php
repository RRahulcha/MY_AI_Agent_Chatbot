<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

include('../include/db_config.php');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ---------------------------------------------------------------
// Action: get_intern_details
// ---------------------------------------------------------------
if ($action === 'get_intern_details') {
    $internId = mysqli_real_escape_string($con, trim($_GET['intern_id'] ?? ''));

    if (empty($internId)) {
        echo json_encode(['success' => false, 'message' => 'Intern ID missing']);
        exit;
    }

    $query = mysqli_query($con,
        "SELECT fullName, batch_number, mode, office, designation
         FROM signup
         WHERE userId = '$internId'
           AND designation IN ('juniorIntern', 'seniorIntern')
           AND status = 'active'
         LIMIT 1"
    );

    if (!$query || mysqli_num_rows($query) === 0) {
        echo json_encode(['success' => false, 'message' => 'Intern not found']);
        exit;
    }

    $intern = mysqli_fetch_assoc($query);
    echo json_encode([
        'success'     => true,
        'fullName'    => $intern['fullName'],
        'batch'       => $intern['batch_number']  ?? '',
        'mode'        => $intern['mode']   ?? '',
        'office'      => $intern['office'] ?? '',
        'designation' => $intern['designation']
    ]);
    exit;
}

// ---------------------------------------------------------------
// Action: get_kra_details (for edit prefill)
// ---------------------------------------------------------------
if ($action === 'get_kra_details') {
    $kraId = (int)($_GET['kra_id'] ?? 0);

    if ($kraId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid KRA ID']);
        exit;
    }

    $query = mysqli_query($con,
        "SELECT * FROM internKraAssignments WHERE kraId = '$kraId' AND status = 'active' LIMIT 1"
    );

    if (!$query || mysqli_num_rows($query) === 0) {
        echo json_encode(['success' => false, 'message' => 'KRA record not found']);
        exit;
    }

    $kra = mysqli_fetch_assoc($query);
    echo json_encode(['success' => true, 'data' => $kra]);
    exit;
}

// ---------------------------------------------------------------
// Action: mark_notification_read
// ---------------------------------------------------------------
if ($action === 'mark_notification_read') {
    $notifId = (int)($_POST['notif_id'] ?? 0);
    $userId  = mysqli_real_escape_string($con, $_SESSION['userId']);

    if ($notifId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid notification ID']);
        exit;
    }

    $update = mysqli_query($con,
        "UPDATE notifications SET status = 'read'
         WHERE id = '$notifId' AND toUserId = '$userId'"
    );

    echo json_encode(['success' => (bool)$update]);
    exit;
}

// ---------------------------------------------------------------
// Action: get_unread_count (for bell icon badge)
// ---------------------------------------------------------------
if ($action === 'get_unread_count') {
    $userId = mysqli_real_escape_string($con, $_SESSION['userId']);
    $result = mysqli_query($con,
        "SELECT COUNT(*) AS cnt FROM notifications
         WHERE toUserId = '$userId' AND status = 'unread'"
    );
    $row = mysqli_fetch_assoc($result);
    echo json_encode(['success' => true, 'count' => (int)($row['cnt'] ?? 0)]);
    exit;
}

// ---------------------------------------------------------------
// Action: get_unfilled_weeks
// Returns all ISO weeks since intern joining that have no KRA submission
// ---------------------------------------------------------------
if ($action === 'get_unfilled_weeks') {
    $internId = mysqli_real_escape_string($con, trim($_GET['intern_id'] ?? ''));

    if (empty($internId)) {
        echo json_encode(['success' => false, 'message' => 'Intern ID missing']);
        exit;
    }

    // Get intern joining date — prefer start_date, fallback to createdDate
    $internQ = mysqli_query($con,
        "SELECT start_date, createdDate FROM signup WHERE userId = '$internId' LIMIT 1"
    );
    if (!$internQ || mysqli_num_rows($internQ) === 0) {
        echo json_encode(['success' => false, 'message' => 'Intern not found']);
        exit;
    }
    $internRow  = mysqli_fetch_assoc($internQ);
    $joiningRaw = (!empty($internRow['start_date'])) ? $internRow['start_date'] : $internRow['createdDate'];
    $joiningRaw = $joiningRaw ?? date('Y-m-d');

    // Get all already-submitted (weekNumber, weekYear) pairs for this intern
    $filledQ = mysqli_query($con,
        "SELECT weekNumber, weekYear FROM internKraAssignments
         WHERE internId = '$internId' AND status = 'active'"
    );
    $filledWeeks = [];
    if ($filledQ) {
        while ($fw = mysqli_fetch_assoc($filledQ)) {
            $filledWeeks[$fw['weekYear'] . '_' . $fw['weekNumber']] = true;
        }
    }

    // Build week list from joining week → current week
    $joinDt   = new DateTime($joiningRaw);
    $joinDt->setTime(0, 0, 0);
    $nowDt    = new DateTime(); // today
    $nowDt->setTime(0, 0, 0);

    // Snap join date to start of its ISO week (Monday)
    $joinWeekStart = clone $joinDt;
    $joinWeekStart->modify('Monday this week');

    $unfilled = [];
    $cursor   = clone $joinWeekStart;

    // Iterate week by week until we reach the current week's Monday
    $nowMonday = clone $nowDt;
    $nowMonday->modify('Monday this week');

    while ($cursor <= $nowMonday) {
        $isoWeek = (int)$cursor->format('W');
        $isoYear = (int)$cursor->format('o'); // ISO year (may differ from calendar year in Jan/Dec)
        $key     = $isoYear . '_' . $isoWeek;

        if (!isset($filledWeeks[$key])) {
            // A week is only available to be filled on or after Friday of that week (not earlier)
            $weekFriday = (clone $cursor)->modify('+4 days');
            if ($nowDt >= $weekFriday) {
                // Calculate week-of-month for the Monday of this ISO week (1st Monday = Week 1, etc.)
                $weekOfMonth = (int)ceil((int)$cursor->format('j') / 7);
                $label = $cursor->format('M Y') . ' — Week ' . $weekOfMonth;

                $unfilled[] = [
                    'week_number' => $isoWeek,
                    'week_year'   => $isoYear,
                    'label'       => $label,
                    'value'       => $isoYear . '_' . $isoWeek,
                ];
            }
        }

        $cursor->modify('+7 days');
    }

    // Return most-recent weeks first (easier UX)
    $unfilled = array_reverse($unfilled);

    echo json_encode(['success' => true, 'weeks' => $unfilled]);
    exit;
}

// ---------------------------------------------------------------
// Fallback
// ---------------------------------------------------------------
echo json_encode(['success' => false, 'message' => 'Unknown action']);
exit;