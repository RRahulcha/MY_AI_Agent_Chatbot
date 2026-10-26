<?php
require_once('../include/db_config.php'); // Database connection
require_once('../vendors/fpdf186/fpdf.php'); // Ensure FPDF library is available

$mode = $_GET['mode']; // Internship mode (online/offline)
$type = $_GET['type']; // 'excel' or 'pdf'

    // Fetch data from the database
    $query = mysqli_query($con,"SELECT fullName, email, mobileNo, type FROM delete_data WHERE type != '' AND designation != 'admin'") or die($con->error);

    if ($type === 'excel') {
        // Export to Excel (CSV)
        // Set headers for Excel export
    header("Content-Type: application/xls");
    header("Content-Disposition: attachment; filename=inactive_interns_report_$mode.xls");
    header("Pragma: no-cache");
    header("Expires: 0");

    echo "Sr.No.\tIntern Name\tEmail\tMobile Number\tMode of Internship\n";
    $id = 1;
    while ($res = mysqli_fetch_assoc($query)) {
        echo $id++ . "\t" . $res['fullName'] . "\t" . $res['email'] . "\t" . $res['mobileNo'] . "\t" . $res['type'] . "\n";
    }
    } elseif ($type === 'pdf') {
        // Generate PDF
        $pdf = new FPDF();
        
        // Increase page width to accommodate wider columns
        $pdf->AddPage('L');  // Landscape mode (for more horizontal space)
        
        // Set Font
        $pdf->SetFont('Arial', 'B', 10);
    
        // Table Header: Increase column widths for 'Intern Name' and 'Email'
        $pdf->Cell(20, 10, 'Sr.No.', 1);   // Adjusted width for 'Sr.No.'
        $pdf->Cell(80, 10, 'Intern Name', 1);   // Increased width for 'Intern Name'
        $pdf->Cell(80, 10, 'Email', 1);       // Increased width for 'Email'
        $pdf->Cell(40, 10, 'Mobile No.', 1);
        $pdf->Cell(50, 10, 'Mode of Internship', 1);
        $pdf->Ln();
    
        $pdf->SetFont('Arial', '',10);

        // Table Content
        $id = 1;
        while ($res = mysqli_fetch_assoc($query)) {
            // Print the Sr.No.
            $pdf->Cell(20, 10, $id++, 1);
            // Print Intern Name with MultiCell to wrap the text if needed
            $pdf->Cell(80, 10, $res['fullName'], 1);
            // Print Email with MultiCell to wrap the text if needed
            $pdf->Cell(80, 10, $res['email'], 1);
            // Print Mobile No.
            $pdf->Cell(40, 10, $res['mobileNo'], 1);
            // Print Mode of Internship
            $pdf->Cell(50, 10, $res['type'], 1);
            
            // Move to next line after each row
            $pdf->Ln();
        }
    
        // Output PDF
        $pdf->Output("D", "Inactive_interns_report_$mode.pdf");
        exit();
    }
       
?>
