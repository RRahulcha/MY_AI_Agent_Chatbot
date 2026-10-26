<?php
session_start();
if(!isset($_SESSION['userId']) && empty($_SESSIOn['userId'])){
    // header('location:login');
    // die();
     echo "<script>window.location.href = 'login.php';</script>";
    exit;
}

include('../include/db_config.php');
$dateTime = date('Y-m-d H:i:s');

if(isset($_POST['create'])){
    //require_once('../include/sweetAlert.php');
    
    $taskName = addslashes($_POST['name']);
    $status = "Pending";
    $userId = $_SESSION['userId'];
    $query = mysqli_query($con, "INSERT INTO to_do_list (`userId`, `task`, `status`, `dateTime`) VALUES ('$userId', '$taskName', '$status', '$dateTime')") or die("Sorry Try Again");
    
    if($query){
        $status = true;
        $msg = "Project is created successfully...";
    } else {
        $status = false;
        $msg = "Sorry, project can't be create. Please try again.";
    }
    echo $msg;
    //setSession($status, $msg);
    //header('location:projectMaster');
}

if(isset($_POST['change'])){
    $taskId = $_POST['task_id'];
    $get = mysqli_query($con, "SELECT * FROM to_do_list WHERE id = '$taskId'") or die("ERROR: Task not found");

    if(mysqli_num_rows($get) > 0) {
        $resulting = mysqli_fetch_assoc($get);
        $status = ($resulting['status'] == "Completed") ? "Pending" : "Completed";

        $query = mysqli_query($con, "UPDATE to_do_list SET `status` = '$status', `dateTime` = '$dateTime' WHERE id = '$taskId'");

        if($query){
            echo "Task status updated successfully.";
        } else {
            echo "ERROR: Could not update task status.";
        }
    }
}


if(isset($_POST['delete'])){
    $taskId = $_POST['task_id'];
    $query = mysqli_query($con, "DELETE FROM to_do_list WHERE id = '$taskId'");

    if($query){
        echo "Task deleted successfully.";
    } else {
        echo "ERROR: Task could not be deleted.";
    }
}


if(isset($_POST['fetch'])){
    $lists = mysqli_query($con, "SELECT * FROM to_do_list WHERE userId = '{$_SESSION['userId']}'");

    if(mysqli_num_rows($lists) == 0){
        echo "<h4 class='text-center' id='donttask'>You don't have any tasks</h4>";
    }

    while($fetch_list = mysqli_fetch_assoc($lists)){
        ?>
        <li class="<?= ($fetch_list['status'] == 'Completed') ? 'completed' : '' ?>">
            <div class="form-check form-check-flat">
                <label class="form-check-label">
                    <input class="checkbox" type="checkbox" <?= ($fetch_list['status'] == 'Completed') ? 'checked' : '' ?>
                           onclick="change_task('<?= $fetch_list['id']; ?>');">
                    <?= htmlspecialchars($fetch_list['task']); ?>
                    <i class="input-helper"></i>
                </label>
            </div>
            <i class="remove ti-close" onclick="delete_task('<?= $fetch_list['id']; ?>');"></i>
        </li>
        <?php
    }
}

?>