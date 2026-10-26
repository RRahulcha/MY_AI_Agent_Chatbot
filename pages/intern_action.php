<?php
session_start();
require_once('../include/db_config.php');

header('Content-Type: application/json');

// Require valid user login and check authorization from DB & Session
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'msg' => 'Unauthorized: Please log in first.'
    ]);
    exit;
}

$currentUserId = mysqli_real_escape_string($con, $_SESSION['userId']);
$userQuery = mysqli_query($con, "SELECT userRole, designation FROM signup WHERE userId='$currentUserId' LIMIT 1");
$userRow = $userQuery ? mysqli_fetch_assoc($userQuery) : null;

$role  = strtolower(trim((string)($userRow['userRole'] ?? $_SESSION['userRole'] ?? '')));
$desig = strtolower(trim((string)($userRow['designation'] ?? $_SESSION['designation'] ?? '')));

$allowedRoles = ['admin', 'hr', 'superadmin', 'resourceallocator', 'resource_allocator', 'resourcehead', 'resource_head', 'seniorintern', 'senior_intern', 'receptionist', 'md', 'developer'];

if (!in_array($role, $allowedRoles) && !in_array($desig, $allowedRoles)) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'msg' => 'Forbidden'
    ]);
    exit;
}

$action  = isset($_POST['action']) ? trim($_POST['action']) : '';
$userId  = isset($_POST['userId']) ? trim($_POST['userId']) : '';
$userIds = isset($_POST['userIds']) && is_array($_POST['userIds']) ? $_POST['userIds'] : [];

if ($action === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'Missing action']);
    exit;
}

if ($action !== 'bulk_deactivate' && $userId === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'Missing userId']);
    exit;
}

// Helper: get columns for a table
function table_columns(mysqli $con, $table)
{
    $cols = [];
    $res = mysqli_query($con, "SHOW COLUMNS FROM `$table`");
    while ($row = mysqli_fetch_assoc($res)) {
        $cols[$row['Field']] = $row;
    }
    return $cols;
}

// Helper: build an INSERT with only common columns, overriding status if present
function insert_row_into(mysqli $con, $toTable, $fromTable, array $row, $statusOverride = null)
{
    $srcCols = table_columns($con, $fromTable);
    $dstCols = table_columns($con, $toTable);

    // Columns in both tables
    $cols = array_values(array_intersect(array_keys($srcCols), array_keys($dstCols)));

    // Drop auto_increment in destination (e.g., srNo)
    foreach ($dstCols as $field => $meta) {
        if (stripos($meta['Extra'], 'auto_increment') !== false) {
            $cols = array_values(array_diff($cols, [$field]));
        }
    }

    // If destination has status, ensure we include it and override value
    if (isset($dstCols['status'])) {
        if (!in_array('status', $cols, true)) {
            $cols[] = 'status';
        }
        $row['status'] = ($statusOverride !== null) ? $statusOverride : ($row['status'] ?? null);
    }

    // >>> ADD THIS <<<
    foreach ([
        'authority',
        'level',
        'delete_date',
        'totalWorkingHours',
        'expectedPunchIn',
        'expectedPunchOut'
    ] as $special) {
        if (isset($dstCols[$special]) && !in_array($special, $cols, true)) {
            $cols[] = $special;
        }
    }


    if (empty($cols)) {
        throw new Exception("No common columns between $fromTable and $toTable.");
    }

    // Escape & build values
    $escapedVals = [];
    foreach ($cols as $c) {
        $val = isset($row[$c]) ? $row[$c] : null;
        $escapedVals[] = is_null($val)
            ? "NULL"
            : "'" . mysqli_real_escape_string($con, (string) $val) . "'";
    }

    $colList = '`' . implode('`,`', $cols) . '`';
    $valList = implode(',', $escapedVals);
    $sql = "INSERT INTO `$toTable` ($colList) VALUES ($valList)";

    if (!mysqli_query($con, $sql)) {
        throw new Exception("Insert failed: " . mysqli_error($con));
    }
    return true;
}

mysqli_begin_transaction($con);

try {
    $safeUserId = mysqli_real_escape_string($con, $userId);

    if ($action === 'deactivate') {
        // Move from signup -> delete_data, set status=Inactive
        $src = mysqli_query(
            $con,
            "SELECT * FROM `signup` WHERE `userId`='{$safeUserId}' LIMIT 1"
        );

        if (!$src || mysqli_num_rows($src) === 0) {
            throw new Exception("No active intern found with that userId.");
        }
        $row = mysqli_fetch_assoc($src);

        $row['authority'] = $_SESSION['userRole'] ?? $_SESSION['designation'] ?? ($role !== '' ? $role : 'admin');
        // NEW — fallback if level is missing
        $row['level'] = $row['level'] ?? 1;   // 1 = Intern
        // NEW — required by delete_data table
        $row['delete_date'] = date('Y-m-d H:i:s');

        $row['totalWorkingHours'] = $row['totalWorkingHours'] ?? '00:00:00';
        $row['expectedPunchIn'] = $row['expectedPunchIn'] ?? '00:00:00';
        $row['expectedPunchOut'] = $row['expectedPunchOut'] ?? '00:00:00';

        insert_row_into($con, 'delete_data', 'signup', $row, 'Inactive');

        // Delete the source row
        mysqli_query($con, "DELETE FROM `signup` WHERE `userId`='{$safeUserId}' LIMIT 1");

        mysqli_commit($con);
        echo json_encode(['ok' => true, 'moved' => 'signup->delete_data', 'status' => 'Inactive']);
        exit;

    } elseif ($action === 'activate') {
        // Move from delete_data -> signup, set status=Active
        $src = mysqli_query(
            $con,
            "SELECT * FROM `delete_data` WHERE `userId`='{$safeUserId}' LIMIT 1"
        );

        if (!$src || mysqli_num_rows($src) === 0) {
            throw new Exception("No inactive intern found with that userId.");
        }
        $row = mysqli_fetch_assoc($src);

        // Ensure numeric level when moving back to signup
        $row['level'] = is_numeric($row['level']) ? $row['level'] : 1;


        insert_row_into($con, 'signup', 'delete_data', $row, 'Active');

        // Delete the source row
        mysqli_query($con, "DELETE FROM `delete_data` WHERE `userId`='{$safeUserId}' LIMIT 1");

        mysqli_commit($con);
        echo json_encode(['ok' => true, 'moved' => 'delete_data->signup', 'status' => 'Active']);
    } elseif ($action === 'bulk_deactivate') {
        if (empty($userIds)) {
            throw new Exception("No intern user IDs provided for bulk deactivation.");
        }

        $count = 0;
        foreach ($userIds as $uId) {
            $safeId = mysqli_real_escape_string($con, trim((string)$uId));
            if ($safeId === '') continue;

            $src = mysqli_query($con, "SELECT * FROM `signup` WHERE `userId`='{$safeId}' LIMIT 1");
            if ($src && mysqli_num_rows($src) > 0) {
                $row = mysqli_fetch_assoc($src);
                $row['authority'] = $_SESSION['userRole'] ?? $_SESSION['designation'] ?? ($role !== '' ? $role : 'admin');
                $row['level'] = $row['level'] ?? 1;
                $row['delete_date'] = date('Y-m-d H:i:s');
                $row['totalWorkingHours'] = $row['totalWorkingHours'] ?? '00:00:00';
                $row['expectedPunchIn'] = $row['expectedPunchIn'] ?? '00:00:00';
                $row['expectedPunchOut'] = $row['expectedPunchOut'] ?? '00:00:00';

                insert_row_into($con, 'delete_data', 'signup', $row, 'Inactive');
                mysqli_query($con, "DELETE FROM `signup` WHERE `userId`='{$safeId}' LIMIT 1");
                $count++;
            }
        }

        mysqli_commit($con);
        echo json_encode(['ok' => true, 'count' => $count, 'msg' => "Successfully deactivated {$count} intern(s)."]);
        exit;

    } else {
        throw new Exception("Invalid action");
    }

} catch (Exception $e) {
    mysqli_rollback($con);
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    exit;
}
?>