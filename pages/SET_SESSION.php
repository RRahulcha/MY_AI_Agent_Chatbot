<?php
session_start();

// Handle intern type filter
if (isset($_REQUEST['type'])) {
    $type = $_REQUEST['type'];
    if ($type === "Reset" || $type === "All") {
        $_SESSION['int_type'] = null;
    } else {
        $_SESSION['int_type'] = $type;
    }
}

// Handle branch filter
if (isset($_REQUEST['branch'])) {
    $branch = $_REQUEST['branch'];
    if ($branch === "Reset" || $branch === "All") {
        $_SESSION['int_branch'] = null;
    } else {
        $_SESSION['int_branch'] = $branch;
    }
}

$back = $_REQUEST['back'] ?? 'adminDashboard.php';
header("Location: " . $back);
exit;
?>