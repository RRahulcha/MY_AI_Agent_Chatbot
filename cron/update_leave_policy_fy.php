<?php
// Migration Script: Set 1 August - 31 March Leave Policy Balances minus approved leaves taken from 1 August onwards.
$dbPath = __DIR__ . '/../include/db_config.php';
if (file_exists($dbPath)) {
    require_once $dbPath;
} else {
    require_once 'include/db_config.php';
}

if (!$con) {
    die("Database connection failed.\n");
}

echo "Updating employee leave balances for Financial Year Policy (1 August - 31 March)...\n";

$fyStartDate = '2026-08-01';

// Base entitlements
$baseCL = 4;
$baseSL = 4;
$basePL = 5;

// Fetch all non-admin employees
$empQ = mysqli_query($con, "SELECT userId, fullName FROM signup WHERE designation NOT IN ('admin', 'developer', 'md')");

$updatedCount = 0;

while ($emp = mysqli_fetch_assoc($empQ)) {
    $uId = mysqli_real_escape_string($con, $emp['userId']);

    // 1. Calculate approved CL taken from 1 Aug onwards
    $clUsedQ = mysqli_query($con, "
        SELECT from_date, to_date 
        FROM leaves 
        WHERE userId = '$uId' 
          AND status = 'Approved' 
          AND (leave_type = 'CL' OR leave_name LIKE '%Casual%')
          AND to_date >= '$fyStartDate'
    ");
    $usedCL = 0;
    if ($clUsedQ) {
        while ($lRow = mysqli_fetch_assoc($clUsedQ)) {
            $s = strtotime(max($lRow['from_date'], $fyStartDate));
            $e = strtotime($lRow['to_date']);
            for ($d = $s; $d <= $e; $d += 86400) {
                if (date('N', $d) != 7) { // Exclude Sundays
                    $usedCL++;
                }
            }
        }
    }

    // 2. Calculate approved SL taken from 1 Aug onwards
    $slUsedQ = mysqli_query($con, "
        SELECT from_date, to_date 
        FROM leaves 
        WHERE userId = '$uId' 
          AND status = 'Approved' 
          AND (leave_type = 'SL' OR leave_name LIKE '%Sick%' OR leave_name LIKE '%Medical%')
          AND to_date >= '$fyStartDate'
    ");
    $usedSL = 0;
    if ($slUsedQ) {
        while ($lRow = mysqli_fetch_assoc($slUsedQ)) {
            $s = strtotime(max($lRow['from_date'], $fyStartDate));
            $e = strtotime($lRow['to_date']);
            for ($d = $s; $d <= $e; $d += 86400) {
                if (date('N', $d) != 7) {
                    $usedSL++;
                }
            }
        }
    }

    // 3. Calculate approved PL/EL taken from 1 Aug onwards
    $plUsedQ = mysqli_query($con, "
        SELECT from_date, to_date 
        FROM leaves 
        WHERE userId = '$uId' 
          AND status = 'Approved' 
          AND (leave_type IN ('PL', 'EL') OR leave_name LIKE '%Privilege%' OR leave_name LIKE '%Personal%' OR leave_name LIKE '%Earned%')
          AND to_date >= '$fyStartDate'
    ");
    $usedPL = 0;
    if ($plUsedQ) {
        while ($lRow = mysqli_fetch_assoc($plUsedQ)) {
            $s = strtotime(max($lRow['from_date'], $fyStartDate));
            $e = strtotime($lRow['to_date']);
            for ($d = $s; $d <= $e; $d += 86400) {
                if (date('N', $d) != 7) {
                    $usedPL++;
                }
            }
        }
    }

    $remCL = max(0, $baseCL - $usedCL);
    $remSL = max(0, $baseSL - $usedSL);
    $remPL = max(0, $basePL - $usedPL);

    $upQ = "UPDATE signup SET CL = '$remCL', SL = '$remSL', PL = '$remPL' WHERE userId = '$uId'";
    if (mysqli_query($con, $upQ)) {
        $updatedCount++;
    }
}

echo "SUCCESS: Updated $updatedCount employee records with 1 Aug starting entitlement minus leaves taken since 1 August.\n";
