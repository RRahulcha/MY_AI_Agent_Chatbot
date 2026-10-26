    <?php
    session_start();
    include('../include/db_config.php');

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
        $leaveId = mysqli_real_escape_string($con, $_POST['id']);
        $userId = $_SESSION['userId'] ?? '';

        if (empty($userId)) {
            echo "error";
            exit;
        }

        // Get the leave type and dates
        $check = mysqli_query($con, "SELECT leave_type, leave_name, status, from_date, to_date 
            FROM leaves 
            WHERE (id='$leaveId' OR leave_id='$leaveId') AND userId='$userId'
        ");

        if ($check && mysqli_num_rows($check) > 0) {
            $rem = mysqli_fetch_assoc($check);

            $today = date('Y-m-d');

            // ❌ Block cancel if approved & date passed
            if (
                $rem['status'] === 'Approved' &&
                $rem['to_date'] < $today
            ) {
                echo "expired";
                exit;
            }

            $rawType = !empty($rem['leave_type']) ? $rem['leave_type'] : ($rem['leave_name'] ?? '');
            $prevStatus = $rem['status'];

            $columnMap = [
                'SL' => 'SL', 'Sick Leave' => 'SL',
                'CL' => 'CL', 'Casual Leave' => 'CL',
                'PL' => 'PL', 'Privilege / Earned Leave' => 'PL', 'Personal Leave' => 'PL', 'Privilege Leave' => 'PL', 'Earned Leave' => 'PL',
                'RO' => 'RO', 'Rostering Off' => 'RO',
                'TD' => 'TD', 'Tour Duty' => 'TD'
            ];
            $signupCol = $columnMap[$rawType] ?? '';

            // Calculate days span (excluding Sundays)
            $days = 0;
            $s = strtotime($rem['from_date']);
            $e = strtotime($rem['to_date']);
            for ($d = $s; $d <= $e; $d += 86400) {
                if (date('N', $d) != 7) {
                    $days++;
                }
            }
            if ($days < 1) $days = 1;

            // Refund leave balance if leave was previously Approved and valid column mapped
            if ($prevStatus === 'Approved' && !empty($signupCol)) {
                mysqli_query($con, "UPDATE signup SET `$signupCol` = `$signupCol` + $days WHERE userId='$userId'");
            }

            // ── SOFT DELETE: Update status to 'Cancelled' instead of physical DELETE ──
            $cancel = mysqli_query($con, "UPDATE leaves SET status='Cancelled', updated_date=NOW() WHERE (id='$leaveId' OR leave_id='$leaveId') AND userId='$userId'");

            if ($cancel) {
                echo "success";
                exit;
            }
        }
    }

    // If any step fails, return error
    echo "error";
    ?>