<?php
session_start();
include('../include/db_config.php');

if (isset($_GET['id'])) {
    $id = mysqli_real_escape_string($con, $_GET['id']);
    
    // Fetch intern details and document path
    $query = mysqli_query($con, "
        SELECT e.exp_letter_path, s.fullName, s.email 
        FROM exit_process e 
        LEFT JOIN signup s ON e.userId COLLATE utf8mb4_unicode_ci = s.userId COLLATE utf8mb4_unicode_ci 
        WHERE e.id = '$id'
    ");
    
    $data = mysqli_fetch_assoc($query);
    
    if ($data) {
        $internEmail = trim($data['email']);
        $internName  = htmlspecialchars($data['fullName']);
        
        // Dynamically generate the public URL for the PDF
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
        $host = $_SERVER['HTTP_HOST'];
        
        // This calculates the base path of your project (e.g. /Dev-SETTribe-AMS)
        $base_dir = str_replace('\\', '/', dirname($_SERVER['PHP_SELF']));
        $base_dir = str_replace('/pages', '', $base_dir); 
        
        $fileUrl = $protocol . "://" . $host . $base_dir . "/uploads/" . $data['exp_letter_path'];

        // ---------------------------------------------------------
        // Construct the Email Content
        // ---------------------------------------------------------
        $subject = "Experience Letter & Relieving Confirmation";
        
        $body = "Dear " . $internName . ",\n\n";
        $body .= "Congratulations on the successful completion of your tenure with us!\n\n";
        $body .= "We have officially processed your exit clearance. Your technical handover and access revocations have been confirmed, and your final exit request has been approved.\n\n";
        
        // Including the direct download link instead of an attachment
        $body .= "Please find your official Experience Letter at the secure link below:\n";
        $body .= $fileUrl . "\n\n";
        
        $body .= "(Note: You can download and save this document for your records.)\n\n";
        $body .= "We appreciate your contributions during your time here and wish you the absolute best in your future career endeavors.\n\n";
        $body .= "Warm Regards,\nHuman Resources Department";

        // ---------------------------------------------------------
        // Generate the Gmail Compose URL
        // ---------------------------------------------------------
        $gmailUrl = "https://mail.google.com/mail/?view=cm&fs=1" 
                  . "&to=" . urlencode($internEmail) 
                  . "&su=" . urlencode($subject) 
                  . "&body=" . urlencode($body);

        $_SESSION['msg'] = "Gmail opened in a new tab. Please review the email and click Send!";
        $_SESSION['status'] = true;

        // Output JavaScript to open Gmail in a new tab, and refresh the current HR page
        echo "<script>
            window.open('$gmailUrl', '_blank');
            window.location.href = 'hr_exit_process.php';
        </script>";
        exit();

    } else {
        $_SESSION['msg'] = "Could not find intern details in the database.";
        $_SESSION['status'] = false;
    }
} else {
    $_SESSION['msg'] = "Invalid Request.";
    $_SESSION['status'] = false;
}

header("Location: hr_exit_process.php");
exit();
?>