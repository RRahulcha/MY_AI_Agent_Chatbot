function exportTableToExcel() {
    // Get the table element
    var table = document.getElementById('projectMasterTable');
    
    // Create a clone of the table to avoid modifying the original
    var tableClone = table.cloneNode(true);

    // Remove the last column from the header row
    var headerRow = tableClone.rows[0];
    headerRow.deleteCell(headerRow.cells.length - 1);

    // Loop through the table rows and remove the last column
    for (var i = 1; i < tableClone.rows.length; i++) { // Skip header row
        var row = tableClone.rows[i];
        row.deleteCell(row.cells.length - 1);
    }

    // Loop through the table rows and format date columns
    for (var i = 1; i < tableClone.rows.length; i++) { // Skip header row
        var cells = tableClone.rows[i].cells;

        // Assuming date columns are 3rd (index 2) and 4th (index 3) columns
        for (var j = 2; j <= 3; j++) {
            var dateCell = cells[j];
            // First, try parsing the date string
            var dateStr = dateCell.innerText.trim();
            var date = new Date(dateStr);
            
            if (!isNaN(date.getTime())) {  // Check if date is valid
                // Format date as DD/MM/YYYY HH:MM
                var dd = String(date.getDate()).padStart(2, '0');
                var mm = String(date.getMonth() + 1).padStart(2, '0');
                var yyyy = date.getFullYear();
                var hours = String(date.getHours()).padStart(2, '0');
                var minutes = String(date.getMinutes()).padStart(2, '0');
                
                dateCell.innerText = `${dd}/${mm}/${yyyy} ${hours}:${minutes}`;
            }
        }
    }

    // Create a workbook with specific options and apply styles
    var workbook = XLSX.utils.table_to_book(tableClone, {
        sheet: "Project Master Report",
        cellStyles: true // Enable styling
    });

    // Apply styles for all cells (align left and increase column size)
    var sheet = workbook.Sheets["Project Master Report"];
    for (var cell in sheet) {
        // Skip the header row and apply styles to each cell
        if (cell[0] === '!') continue; // Skip meta properties

        // Set the alignment to left
        sheet[cell].s = { alignment: { horizontal: 'left' } };

        // Increase the column size (width) for better visibility
        var col = cell.replace(/[0-9]/g, ''); // Extract column letter (A, B, C, etc.)
        if (!sheet['!cols']) sheet['!cols'] = [];
        if (!sheet['!cols'][col.charCodeAt(0) - 65]) {
            sheet['!cols'][col.charCodeAt(0) - 65] = { wch: 20 }; // Width set to 20
        }
    }

    // Export the workbook to an Excel file
    XLSX.writeFile(workbook, 'ProjectMaster.xlsx');
}

    async function exportTableToPDF() {
        const { jsPDF } = window.jspdf;

        // Create a new instance of jsPDF
        const doc = new jsPDF();

        // Add title to the PDF
        doc.text('Project Master Report', 14, 10);

        // Extract table data and format it for jsPDF
        const table = document.getElementById('projectMasterTable');
        const tableData = [];

        // Add table header
        const header = Array.from(table.querySelectorAll('thead tr th')).map(th => th.innerText);
        tableData.push(header);

        // Add table rows
        const rows = Array.from(table.querySelectorAll('tbody tr'));
        rows.forEach(row => {
            const rowData = Array.from(row.querySelectorAll('td, th')).map(cell => cell.innerText);
            tableData.push(rowData);
        });

        // Add table to PDF
        doc.autoTable({
            head: [header],
            body: tableData.slice(1), // Exclude header for body
            startY: 20,
        });

        // Save the PDF
        doc.save('ProjectMaster.pdf');
}