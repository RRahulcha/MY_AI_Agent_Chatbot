<?php
// Assume $con is your active mysqli database connection object.
// You must include your database connection details here.
include('../include/db_config.php');

date_default_timezone_set('Asia/Kolkata');

// Check if the request is an AJAX request and the 'office' parameter is set.
if (isset($_POST['office'])) {
    $officeFilter = $_POST['office'];

    // Initialize the default 'All Employees' option
    $options = '<option value="">-- All Employees --</option>';

    // Base query to fetch active employees who are not admins/hr
    $dropdownQuery = "SELECT userId, fullName FROM signup WHERE (designation IS NULL OR LOWER(designation) NOT IN ('admin', 'hr', 'superadmin', 'developer', 'md')) AND (userRole IS NULL OR LOWER(userRole) NOT IN ('admin', 'hr', 'superadmin', 'md')) AND (TRIM(LOWER(status)) = 'active' OR status = '1' OR status = 1) AND (regstatus = 'Approved' OR regstatus IS NULL OR regstatus = '')";

    // Add the office filter if a specific location is selected
    if (!empty($officeFilter)) {
        // Sanitize the input
        $sanitizedOffice = mysqli_real_escape_string($con, $officeFilter);
        $dropdownQuery .= " AND office = '" . $sanitizedOffice . "'";
    }

    $dropdownQuery .= " ORDER BY fullName ASC";

    $dropdownRes = mysqli_query($con, $dropdownQuery);

    if ($dropdownRes) {
        while ($dRow = mysqli_fetch_assoc($dropdownRes)) {
            // No need to check $empFilter here, as this is a fresh list.
            // The selected attribute is handled by the main page logic upon form submission.
            $options .= "<option value='{$dRow['userId']}'>{$dRow['fullName']}</option>";
        }
    } else {
        // Handle database error
        $options .= "<option value='' disabled>Error fetching employees</option>";
    }

    // Output the HTML options string
    echo $options;

} else {
    // Return the default 'All Employees' option if no office is specified
    echo '<option value="">-- All Employees --</option>';
}

// Ensure no other output before the options
exit;
?>