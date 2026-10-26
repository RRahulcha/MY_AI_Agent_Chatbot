<?php
/**
 * auto-update-wfh-status.php
 * Automatically manages daily punch-in mode (signup.type) based on WFH date ranges.
 *
 * Rules:
 *  1. If employee has an Approved WFH for today -> type = 'Online'
 *  2. Otherwise -> type = mode (reverts to permanent assigned mode)
 */

include_once(__DIR__ . '/../include/db_config.php');

$currentDate = date('Y-m-d');

// Detect column names in wfhRequests table
$columnsResult = mysqli_query($con, "SHOW COLUMNS FROM wfhRequests");
$existingColumns = [];
if ($columnsResult) {
    while ($colRow = mysqli_fetch_assoc($columnsResult)) {
        $existingColumns[] = strtolower($colRow['Field']);
    }
}
$hasFromDate = in_array('fromdate', $existingColumns);
$hasToDate   = in_array('todate', $existingColumns);
$hasWfhDate  = in_array('wfhdate', $existingColumns);

$fromDateExpr = $hasFromDate ? ($hasWfhDate ? "COALESCE(fromDate, wfhDate)" : "fromDate") : "wfhDate";
$toDateExpr   = $hasToDate   ? ($hasWfhDate ? "COALESCE(toDate, wfhDate)"   : "toDate")   : "wfhDate";

// ── 1. Get all user IDs with an ACTIVE approved WFH for TODAY ─────────────────
$activeWfhUsers = [];
$activeQ = mysqli_query($con, "
    SELECT DISTINCT userid
    FROM wfhRequests
    WHERE status = 'Approved'
      AND ('$currentDate' BETWEEN $fromDateExpr AND $toDateExpr)
");
if ($activeQ) {
    while ($r = mysqli_fetch_assoc($activeQ)) {
        if (!empty($r['userid'])) {
            $activeWfhUsers[] = mysqli_real_escape_string($con, $r['userid']);
        }
    }
}

// ── 2. Set type = 'Online' for active WFH users today ─────────────────────────
if (!empty($activeWfhUsers)) {
    $activeUserList = "'" . implode("','", $activeWfhUsers) . "'";
    mysqli_query($con, "
        UPDATE signup
        SET type = 'Online'
        WHERE userId IN ($activeUserList)
    ");
}

// ── 3. Reset type = mode for users who do NOT have an active WFH today ────────
$notInClause = !empty($activeWfhUsers) ? "WHERE userId NOT IN (" . "'" . implode("','", $activeWfhUsers) . "'" . ")" : "";
mysqli_query($con, "
    UPDATE signup
    SET type = mode
    $notInClause
");

?>