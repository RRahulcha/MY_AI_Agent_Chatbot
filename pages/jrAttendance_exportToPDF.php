<?php
require '../include/db_config.php'; // Database connection
require '../vendors/fpdf186/fpdf.php'; // Include FPDF

// Create a new PDF object
$pdf = new FPDF();
$pdf->AddPage();
$pdf->SetFont('Arial', 'B', 12);

// Add headers
$pdf->Cell(40, 10, 'Intern Name', 1);
$pdf->Cell(40, 10, 'Date', 1);
$pdf->Cell(40, 10, 'Status', 1);
$pdf->Ln();

// Example data
$data = [
    ['John Doe', '2023-10-01', 'Present'],
    ['Jane Smith', '2023-10-01', 'Absent'],
];

// Add data to the PDF
foreach ($data as $row) {
    $pdf->Cell(40, 10, $row[0], 1);
    $pdf->Cell(40, 10, $row[1], 1);
    $pdf->Cell(40, 10, $row[2], 1);
    $pdf->Ln();
}

// Output the PDF
$pdf->Output('D', 'attendance_report.pdf');
exit;