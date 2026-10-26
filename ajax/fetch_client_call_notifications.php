<?php
include('../include/db_config.php');

$q = mysqli_query($con, "
    SELECT 
        c.id,
        c.employee_userId,
        c.message,
        c.created_at,
        s.fullName
    FROM client_call_notifications c
    JOIN signup s 
        ON s.userId COLLATE utf8mb4_unicode_ci 
        = c.employee_userId COLLATE utf8mb4_unicode_ci
    ORDER BY c.id DESC
");

while ($r = mysqli_fetch_assoc($q)) {

    $id       = (int)$r['id'];
    $uid      = htmlspecialchars($r['employee_userId'], ENT_QUOTES);
    $msg      = htmlspecialchars($r['message'], ENT_QUOTES);
    $created  = date('d M Y, H:i', strtotime($r['created_at'])); // format as you like

    echo "<tr>
        <td>{$r['fullName']}</td>
        <td>{$r['message']}</td>
        <td>{$created}</td>
        <td>
            <button class='btn btn-sm btn-primary'
                onclick=\"editNotification($id, '$uid', '$msg')\">
                Edit
            </button>

            <form method='post'
                  action='Client_Call_Notification.php'
                  style='display:inline'
                  onsubmit=\"return confirm('Delete this notification?')\">
                <input type='hidden' name='delete_id' value='$id'>
                <button class='btn btn-sm btn-danger ml-1'>Delete</button>
            </form>
        </td>
    </tr>";
}
