<?php
session_start();
include('../include/db_config.php');

$id = intval($_POST['id'] ?? 0);
$userId = $_SESSION['userId'] ?? '';

if ($id && !empty($userId)) {

    $q = mysqli_query($con, "
        SELECT from_date, to_date, status FROM leaves WHERE id = $id AND userId = '$userId'
    ");

    if ($q && $r = mysqli_fetch_assoc($q)) {
        $today = date('Y-m-d');

        if ($r['status'] === 'Approved' && $r['to_date'] < $today) {
            echo "<p class='text-danger fw-bold'>This leave is expired and cannot be cancelled.</p>";
            exit;
        }

        $start = strtotime($r['from_date']);
        $end = strtotime($r['to_date']);
        $output = "<div class='text-start mb-2'><p class='small text-muted mb-2'>Cancel specific date or use 'Yes, Cancel Leave' below to cancel the full application:</p>";

        while ($start <= $end) {
            $single = date("d-m-Y", $start);
            $singleDb = date("Y-m-d", $start);
            $isPast = ($singleDb < $today);

            $output .= "
                <div style='display:flex; justify-content:space-between; align-items:center; padding:6px 12px; margin-bottom:6px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px;'>
                    <span style='font-size:0.9rem; font-weight:600; color:#334155;'>
                        <i class='fa-regular fa-calendar me-1 text-primary'></i> $single
                    </span>";
            if ($isPast) {
                $output .= "<span class='badge bg-secondary text-white' style='font-size:0.75rem;'>Past Date</span>";
            } else {
                $output .= "<button 
                        type='button' 
                        class='btn btn-outline-danger btn-sm px-2 py-1' 
                        style='font-size:0.78rem; font-weight:600;' 
                        onclick='cancelLeaveSingle(\"$singleDb\", $id)'>
                        <i class='fa-solid fa-trash me-1'></i> Cancel Day
                    </button>";
            }
            $output .= "</div>";

            $start = strtotime("+1 day", $start);
        }

        $output .= "</div>";
        echo $output;
        exit;
    }
}

echo "<p class='text-muted'>Leave application not found.</p>";
?>