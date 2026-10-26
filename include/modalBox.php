<?php
// Start the session if it's not already started
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', 2592000);
    session_set_cookie_params(2592000);
    session_start();
}

// Check if the modal_shown cookie is set, if not set it to false
if (!isset($_COOKIE['modal_shown']) || $_COOKIE['modal_shown'] == 'false') {
    // Show the modal and set the cookie to true after displaying
    $showModal = true;
    setcookie('modal_shown', 'true', time() + (30 * 24 * 60 * 60), '/'); // Cookie expires in 30 days
} else {
    // Don't show the modal
    $showModal = false;
}

// Assuming session has the userId set and database connection is available
$userId = $_SESSION['userId'];

// Query for total pending tasks
$pendingTasksQuery = "
    SELECT COUNT(*) AS pendingCount
    FROM tasks
    WHERE userId = ? AND status = 'pending'
";
$stmt = $conn->prepare($pendingTasksQuery);
$stmt->bind_param("s", $userId);  // Binding the userId to the query
$stmt->execute();
$stmt->bind_result($pendingCount);
$stmt->fetch();
$stmt->close();

// Query for projects allocated to the user with deadline <= 30 days
$projectsQuery = "
    SELECT pm.projectName, pm.deadLine 
    FROM projectMaster pm
    INNER JOIN allocatedProject ap ON pm.projectId = ap.projectId
    WHERE ap.userId = ? 
    AND pm.deadLine <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    ORDER BY pm.deadLine ASC
";
$stmt = $conn->prepare($projectsQuery);
$stmt->bind_param("s", $userId);
$stmt->execute();
$projectsResult = $stmt->get_result();
$stmt->close();
?>

<!-- Modal HTML -->
<div id="myDialog" class="modal" <?php echo $showModal ? 'style="display:block;"' : ''; ?>>
    <!-- Overlay to dim the background -->
    <div class="modal-overlay" onclick="closeModal()"></div>

    <div class="modal-content">
        <!-- Close button for the modal -->
        <span class="close-btn" onclick="closeModal()">&times;</span>

        <!-- Total Pending Tasks Section -->
        <div class="section">
            <h3>Total Pending Tasks</h3>
            <p><?php echo $pendingCount; ?> Pending tasks</p>
            <a href="taskEntry" class="btn">View Details</a>
        </div>

        <!-- Projects Allocated Section -->
        <div class="section">
            <h3>Projects Allocated to You (Deadline <= 30 Days)</h3>
            <ul>
                <?php if ($projectsResult->num_rows > 0): ?>
                    <?php while ($row = $projectsResult->fetch_assoc()): ?>
                        <li>
                            <?php echo htmlspecialchars($row['projectName']); ?> - 
                            <?php echo date("Y-m-d", strtotime($row['deadLine'])); ?>
                        </li>
                    <?php endwhile; ?>
                <?php else: ?>
                    <li>No projects allocated within the next 30 days.</li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</div>

<!-- Modal Styles -->
<style>
    /* Modal Styles */
    .modal {
        display: none; /* Hidden by default */
        position: fixed; /* Stay in place */
        z-index: 1; /* Sit on top */
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        overflow: auto; /* Enable scroll if needed */
        background-color: rgba(0, 0, 0, 0.5); /* Dark background overlay */
    }

    .modal-overlay {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0, 0, 0, 0.5);
        z-index: 0;
    }

    .modal-content {
        background-color: #fff;
        margin: 15% auto;
        padding: 20px;
        border-radius: 5px;
        width: 80%;
        max-width: 600px;
        position: relative;
        z-index: 1;
        box-shadow: 0px 4px 8px rgba(0, 0, 0, 0.2);
    }

    .close-btn {
        position: absolute;
        top: 10px;
        right: 10px;
        font-size: 30px;
        cursor: pointer;
    }

    .section {
        margin-bottom: 20px;
    }

    h3 {
        margin-bottom: 10px;
    }

    .btn {
        display: inline-block;
        background-color: #4CAF50;
        color: white;
        padding: 10px 20px;
        text-align: center;
        text-decoration: none;
        border-radius: 5px;
        margin-top: 10px;
    }

    .btn:hover {
        background-color: #45a049;
    }
</style>

<!-- Modal Script -->
<script>
    // Get the modal
    var modal = document.getElementById("myDialog");

    // Function to close the modal
    function closeModal() {
        modal.style.display = "none";
    }

    // Close modal if the user clicks on the overlay
    window.onclick = function(event) {
        if (event.target === modal) {
            closeModal();
        }
    }
</script>