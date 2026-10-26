<?php
// Include PhpSpreadsheet manually
require 'vendors/PhpSpreadsheet/Spreadsheet.php';
require 'vendors/PhpSpreadsheet/Writer/Xlsx.php';

// Create a new Spreadsheet object
$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Add data to the sheet
$sheet->setCellValue('A1', 'Intern Name');
$sheet->setCellValue('B1', 'Date');
$sheet->setCellValue('C1', 'Status');

// Example data
$data = [
    ['John Doe', '2023-10-01', 'Present'],
    ['Jane Smith', '2023-10-01', 'Absent'],
];

$row = 2;
foreach ($data as $rowData) {
    $sheet->setCellValue('A' . $row, $rowData[0]);
    $sheet->setCellValue('B' . $row, $rowData[1]);
    $sheet->setCellValue('C' . $row, $rowData[2]);
    $row++;
}

// Save the file
$writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="attendance_report.xlsx"');
header('Cache-Control: max-age=0');
$writer->save('php://output');
exit;