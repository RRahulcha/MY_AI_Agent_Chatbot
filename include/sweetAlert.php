<?php
// session_start();
function setSession($status, $msg){
    $_SESSION['status'] = $status;
    $_SESSION['msg'] = $msg;
}
?>

<style>
.swal2-popup .swal2-icon {
    margin: 0 auto !important;
}
</style>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    const Toast = Swal.mixin({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true,
        didOpen: (toast) => {
            toast.addEventListener('mouseenter', Swal.stopTimer)
            toast.addEventListener('mouseleave', Swal.resumeTimer)
        }
    })
</script>
<?php
function toastMsg($status, $msg){
?>
<script>
    Toast.fire({
        icon: '<?php echo $status ?>',
        text: '<?php echo $msg ?>'
    })
</script>
<?php
}

function sweetMsg($status, $msg, $title = ''){
    $safeMsg = addslashes($msg);
    $formattedMsg = str_replace(['\r\n', '\n', '\r', ' | '], '<br>• ', $safeMsg);
    $headerTitle  = $title ? addslashes($title) : ($status === 'error' ? 'Validation Alert' : 'Notification');
?>
<script>
    Swal.fire({
        title: '<?php echo $headerTitle; ?>',
        html: '<div style="text-align:left;font-size:0.92rem;line-height:1.6;color:#333;margin-top:10px;"><?php echo $formattedMsg; ?></div>',
        icon: '<?php echo $status; ?>',
        confirmButtonColor: '#4b49ac',
        confirmButtonText: 'OK, Understood',
        width: '560px',
        padding: '1.5rem',
        background: '#ffffff'
    });
</script>
<?php
}
?>
<script>

    let toast = (msg,icon) => {
        Toast.fire({
            icon: icon,
            text: msg
        })
    }
    let deletefun = (location, id) => {
        Swal.fire({
            title: 'Are you sure?',
            text: "You won't be able to revert this!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Yes, delete it!'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location = location+"?delete="+id;
            }
        })
    }
    let endfun = (location, id) => {
        Swal.fire({
            title: 'Are you sure?',
            text: "You won't be able to revert this!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Yes,Mark as Completed!'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location = location+"?end="+id;
            }
        })
    }
    
    let logoutfun = (location) => {
        Swal.fire({
            title: 'Are you sure?',
            text: "You want to Log Out!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Yes, Log Out!'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location = location;
            }
        })
    }
    

    
    let alreadyExit = () => {
        var status;
        Swal.fire({
            title: 'This scheme is already exit.',
            text: "You want to continue?",
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Yes',
            cancelButtonText: 'No'
        }).then((result) => {
            if (result.isConfirmed) {
                $("#habitation").val(null).trigger("change");
                // status = true;
            } else {
                // status = false;
            }
        });
        
        return status;
    }

</script>

