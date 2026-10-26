<?php
require_once('../include/db_config.php');
require('../vendors/fpdf186/fpdf.php');

$mode       = isset($_GET['mode']) ? trim((string)$_GET['mode']) : '';
$batch      = isset($_GET['batch']) ? trim((string)$_GET['batch']) : '';
$exportType = isset($_GET['type']) ? trim((string)$_GET['type']) : 'excel';

$whereConditions = ["designation != 'admin'", "status = 'Active'"];

if (!empty($mode)) {
    $safeMode = mysqli_real_escape_string($con, $mode);
    $whereConditions[] = "type = '$safeMode'";
}

if (!empty($batch)) {
    $safeBatch = mysqli_real_escape_string($con, $batch);
    $whereConditions[] = "batch_number = '$safeBatch'";
}

$whereSql = implode(' AND ', $whereConditions);

$query = mysqli_query($con, "SELECT * FROM signup WHERE $whereSql ORDER BY srNo DESC") or die($con->error);

if ($exportType === 'excel') {
    // Set headers for Excel export
    header("Content-Type: application/xls");
    header("Content-Disposition: attachment; filename=active_interns_report.xls");
    header("Pragma: no-cache");
    header("Expires: 0");

    echo "Sr.No.\tIntern Name\tEmail\tMobile Number\tBatch\tMode of Internship\n";
    $id = 1;
    while ($res = mysqli_fetch_assoc($query)) {
        echo $id++ . "\t" . $res['fullName'] . "\t" . $res['email'] . "\t" . $res['mobileNo'] . "\t" . ($res['batch_number'] ?? '') . "\t" . $res['type'] . "\n";
    }
} elseif ($exportType === 'pdf') {
    // Generate PDF
    $pdf = new FPDF();
    $pdf->AddPage('L');
    $pdf->SetFont('Arial', 'B', 10);

    // Table Header
    $pdf->Cell(15, 10, 'Sr.No.', 1);
    $pdf->Cell(70, 10, 'Intern Name', 1);
    $pdf->Cell(75, 10, 'Email', 1);
    $pdf->Cell(35, 10, 'Mobile No.', 1);
    $pdf->Cell(35, 10, 'Batch', 1);
    $pdf->Cell(45, 10, 'Mode of Internship', 1);
    $pdf->Ln();

    $pdf->SetFont('Arial', '', 10);

    // Table Content
    $id = 1;
    while ($res = mysqli_fetch_assoc($query)) {
        $pdf->Cell(15, 10, $id++, 1);
        $pdf->Cell(70, 10, $res['fullName'], 1);
        $pdf->Cell(75, 10, $res['email'], 1);
        $pdf->Cell(35, 10, $res['mobileNo'], 1);
        $pdf->Cell(35, 10, $res['batch_number'] ?? '', 1);
        $pdf->Cell(45, 10, $res['type'], 1);
        $pdf->Ln();
    }

    // Output PDF
    $pdf->Output("D", "active_interns_report.pdf");
} else {
    echo "Invalid export type!";
}
?>
