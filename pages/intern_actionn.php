<?php
session_start();
include('../include/db_config.php');

header('Content-Type: application/json');
$response = ['ok' => false];

if (isset($_POST['action'], $_POST['userId']) && $_POST['action'] === 'delete') {
    $userId = mysqli_real_escape_string($con, $_POST['userId']);

    // Fetch intern data
    $check = mysqli_query($con, "SELECT * FROM signup WHERE userId='$userId' LIMIT 1");
    if ($check && mysqli_num_rows($check) > 0) {
        $employee = mysqli_fetch_assoc($check);

        // Columns to move
        $allowedColumns = [
            'fullName', 'email', 'office', 'expectedPunchIn', 'expectedPunchOut',
            'internId', 'designation', 'dob', 'mobileNo', 'username', 'type',
            'password', 'photo', 'status', 'createdDate'
        ];

        $columns = [];
        $values = [];
        foreach ($allowedColumns as $col) {
            if (isset($employee[$col])) {
                $columns[] = "`$col`";
                $values[] = "'" . mysqli_real_escape_string($con, $employee[$col]) . "'";
            }
        }

        $columnsStr = implode(", ", $columns);
        $valuesStr  = implode(", ", $values);

        mysqli_begin_transaction($con);
        try {
            // 1️⃣ Move to delete_data
            $insert = mysqli_query($con, "INSERT INTO delete_data ($columnsStr) VALUES ($valuesStr)");
            if (!$insert) {
                throw new Exception("Failed to insert into delete_data: " . mysqli_error($con));
            }

            // 2️⃣ Delete from signup so it disappears from table
            $delete = mysqli_query($con, "DELETE FROM signup WHERE userId='$userId'");
            if (!$delete) {
                throw new Exception("Failed to remove from signup: " . mysqli_error($con));
            }

            // ✅ Renumber srNo after delete
            mysqli_query($con, "SET @newSrNo = 0");
            mysqli_query($con, "UPDATE signup SET srNo = (@newSrNo := @newSrNo + 1) ORDER BY srNo");

            mysqli_commit($con);
            $response['ok'] = true;
            $response['msg'] = $employee['fullName'] . " moved to delete_data and removed from signup!";
        } catch (Exception $e) {
            mysqli_rollback($con);
            $response['msg'] = $e->getMessage();
        }
    } else {
        $response['msg'] = "User not found.";
    }
}

echo json_encode($response);
?>
