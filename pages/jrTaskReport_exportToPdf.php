<?php
require '../include/db_config.php';
require '../vendors/fpdf186/fpdf.php';

// Retrieve filters from GET request
$intern = $_GET['intern'] ?? '';
$fdate = $_GET['fdate'] ?? '';
$tdate = $_GET['tdate'] ?? '';

// Apply filters
$nameFilter = !empty($intern) ? "taskEntry.userId = '$intern'" : "1=1";
$dateFilter = !empty($fdate) && !empty($tdate) 
    ? "dateTime BETWEEN '$fdate 00:00:00' AND '$tdate 23:59:59'" 
    : "1=1";

// Build SQL query
$query = "SELECT taskEntry.*, signup.fullName 
          FROM taskEntry 
          JOIN signup ON taskEntry.userId = signup.userId 
          WHERE $nameFilter AND $dateFilter";

// Execute query
$result = mysqli_query($con, $query);

// Check for errors
if (!$result) {
    die("Query Error: " . mysqli_error($con));
}

// Initialize PDF
$pdf = new FPDF();
$pdf->AddPage();
$pdf->SetFont('Arial', 'B', 12);

// Add title
$pdf->Cell(190, 10, 'Junior Task Report', 0, 1, 'C');
$pdf->Ln(10);

// Table headers
$pdf->SetFont('Arial', 'B', 10);
$pdf->SetFillColor(200, 220, 255); // Light blue background
$pdf->Cell(50, 10, 'Intern Name', 1, 0, 'C', true);
$pdf->Cell(60, 10, 'Task', 1, 0, 'C', true);
$pdf->Cell(40, 10, 'Expected Time', 1, 0, 'C', true);
$pdf->Cell(40, 10, 'Location', 1, 1, 'C', true);

// Populate table rows
$pdf->SetFont('Arial', '', 10);
while ($row = mysqli_fetch_assoc($result)) {
    $expectedDeadline = date_create($row['expectedDeadline']);
    $formattedDeadline = date_format($expectedDeadline, "h:ia (d M y)");
    
    $pdf->Cell(50, 10, $row['fullName'], 1);
    $pdf->Cell(60, 10, $row['task'], 1);
    $pdf->Cell(40, 10, $formattedDeadline, 1);
    $pdf->Cell(40, 10, $row['location'], 1, 1);
}

// Check if no data was found
if (mysqli_num_rows($result) == 0) {
    $pdf->Cell(190, 10, 'No data found for the selected filters.', 1, 1, 'C');
}

// Output the PDF
$pdf->Output('D', 'jrTaskReport.pdf'); // Force download
exit;
?>
