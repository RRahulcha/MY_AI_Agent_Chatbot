<?php
include('../include/db_config.php');

if (isset($_POST['id'])) {
    $id = intval($_POST['id']);

    $query = mysqli_query($con, "SELECT * FROM signup WHERE srNo='$id'");
    if (mysqli_num_rows($query) > 0) {
        $row = mysqli_fetch_assoc($query);

        echo "
        <table class='table table-bordered table-sm mb-0'>
            <tr><th>Employment Type</th><td>{$row['employment_type']}</td></tr>
            <tr><th>Employee / Intern ID</th><td>{$row['internId']}</td></tr>
            <tr><th>Branch</th><td>{$row['branch']}</td></tr>
            <tr><th>Date of Birth</th><td>{$row['dob']}</td></tr>
            <tr><th>Department</th><td>{$row['department']}</td></tr>
            <tr><th>Office</th><td>{$row['office']}</td></tr>

            <tr><th>Shift In</th><td>{$row['expectedPunchIn']}</td></tr>
            <tr><th>Shift Out</th><td>{$row['expectedPunchOut']}</td></tr>

            <tr><th>Start Date</th><td>{$row['start_date']}</td></tr>
            <tr><th>End Date</th><td>{$row['end_date']}</td></tr>

            <tr><th>Casual Leave (CL)</th><td>{$row['CL']} / 4 Days</td></tr>
            <tr><th>Sick Leave (SL)</th><td>{$row['SL']} / 4 Days</td></tr>
            <tr><th>Privilege / Earned Leave (PL/EL)</th><td>{$row['PL']} / 5 Days</td></tr>

            <tr><th>Remarks</th><td>{$row['remarks']}</td></tr>
            <tr><th>Registration Date</th><td>{$row['registration_date']}</td></tr>
        </table>
        ";
    } else {
        echo "<div class='p-2 text-muted'>No record found for this user.</div>";
    }
}
