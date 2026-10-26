<?php 
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * emp_dsr.php  —  OPTIMIZED Daily Status Report
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * WHAT CHANGED (performance):
 *
 *  1. The per-date loop that ran 4 queries × N dates (up to 1,460 queries
 *     for a 1-year employee) is replaced with 2 bulk GROUP BY queries.
 *     PHP assembles the result from maps — zero per-date DB calls.
 *
 *  2. The "task" detail fetch uses a prepared statement.
 *
 *  3. $work_hr variable was leaking between loop iterations (showing
 *     yesterday's hours for days with no attendance). Fixed by defaulting
 *     to '00:00:00' per-date.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 */

session_start();
require_once('../include/db_config.php');

// ═══════════════════════════════════════════════════
//  Task detail view (unchanged logic, prepared stmt)
// ═══════════════════════════════════════════════════
if (isset($_GET['task'])) {
    $date   = $_POST['date'] ?? '';
    $userId = $_SESSION['userId'] ?? '';

    $stmt = $con->prepare(
        "SELECT task, status FROM taskEntry WHERE userId = ? AND dateTime LIKE CONCAT(?, '%')"
    );
    $stmt->bind_param('ss', $userId, $date);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($r = $result->fetch_assoc()) {
?>
<table cellpadding="5" cellspacing="0" border="0" style="width:100%;">
    <tr class="expanded-row">
        <td class="row-bg">
            <?php echo htmlspecialchars($r['task']); ?>
        </td>
        <td>
            <?php echo htmlspecialchars($r['status']); ?>
        </td>
    </tr>
</table>
<?php
    }
    $stmt->close();
}


// ═══════════════════════════════════════════════════
//  DataTable view — OPTIMIZED: 2 bulk queries replace N×4 loop
// ═══════════════════════════════════════════════════
if (isset($_GET['dataTable'])) {

    $userId = $_SESSION['userId'] ?? '';

    // ── Get the employee's first task date ──
    $startStmt = $con->prepare(
        "SELECT MIN(DATE(startTime)) AS startDate FROM taskEntry WHERE userId = ?"
    );
    $startStmt->bind_param('s', $userId);
    $startStmt->execute();
    $startRow  = $startStmt->get_result()->fetch_assoc();
    $startStmt->close();

    $startDate = $startRow['startDate'] ?? date('Y-m-d');
    $endDate   = date('Y-m-d');

    // ══════════════════════════════════════════════════
    //  BULK QUERY 1: All task counts grouped by date
    //  Replaces 3 separate queries per date in the old loop
    // ══════════════════════════════════════════════════
    $taskStmt = $con->prepare("
        SELECT date,
               COUNT(DISTINCT taskId)                                    AS total,
               COUNT(DISTINCT CASE WHEN status != 'end' THEN taskId END) AS pending,
               COUNT(DISTINCT CASE WHEN status  = 'end' THEN taskId END) AS completed
        FROM taskEntry
        WHERE userId = ?
          AND date BETWEEN ? AND ?
        GROUP BY date
    ");
    $taskStmt->bind_param('sss', $userId, $startDate, $endDate);
    $taskStmt->execute();
    $taskResult = $taskStmt->get_result();

    $taskMap = [];
    while ($row = $taskResult->fetch_assoc()) {
        $taskMap[$row['date']] = $row;
    }
    $taskStmt->close();

    // ══════════════════════════════════════════════════
    //  BULK QUERY 2: Working hours grouped by date
    //  Replaces 1 complex query (with subqueries) per date
    // ══════════════════════════════════════════════════
    $attStmt = $con->prepare("
        SELECT date,
               SEC_TO_TIME(SUM(TIME_TO_SEC(manHours))) AS totalWorkingTime,
               actualManHour
        FROM attendance
        WHERE userId = ?
          AND date BETWEEN ? AND ?
        GROUP BY date, actualManHour
    ");
    $attStmt->bind_param('sss', $userId, $startDate, $endDate);
    $attStmt->execute();
    $attResult = $attStmt->get_result();

    $attMap = [];
    while ($row = $attResult->fetch_assoc()) {
        $attMap[$row['date']] = $row;
    }
    $attStmt->close();

    // ══════════════════════════════════════════════════
    //  Assemble results — pure PHP, zero additional queries
    // ══════════════════════════════════════════════════
    $data = [];

    $interval = new DateInterval('P1D');
    $realEnd  = new DateTime($endDate);
    $realEnd->add($interval);
    $period   = new DatePeriod(new DateTime($startDate), $interval, $realEnd);

    foreach ($period as $dateObj) {
        $d = $dateObj->format('Y-m-d');

        $taskRow = $taskMap[$d] ?? null;
        $total     = $taskRow ? (int)$taskRow['total']     : 0;
        $pending   = $taskRow ? (int)$taskRow['pending']   : 0;
        $completed = $taskRow ? (int)$taskRow['completed'] : 0;

        // Only show work hours for past dates (not today)
        $work_hr = '00:00:00';
        if ($d !== date('Y-m-d') && isset($attMap[$d])) {
            $work_hr = $attMap[$d]['totalWorkingTime'] ?? '00:00:00';
        }

        $data[] = [
            'Date'      => $d,
            'Total'     => $total,
            'Pending'   => $pending,
            'Completed' => $completed,
            'Work_hr'   => $work_hr,
        ];
    }

    echo json_encode(['data' => $data]);
}
?>
<?php if (isset($con)) { $con->close(); } ?>
