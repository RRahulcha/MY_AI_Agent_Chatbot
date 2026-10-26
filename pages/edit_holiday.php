<?php
session_start();
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}

include('../include/db_config.php'); 

/* ✅ Get Holiday ID */
if (!isset($_GET['id'])) {
    die("Holiday ID missing");
}

$id = $_GET['id'];

/* ✅ Fetch existing holiday */
$result = mysqli_query($con, "SELECT * FROM holidays WHERE id='$id'");
if (mysqli_num_rows($result) == 0) {
    die("Holiday not found");
}
$holiday = mysqli_fetch_assoc($result);

/* ✅ Update logic */
if (isset($_POST['updateHoliday'])) {
    $title = $_POST['title'];
    $from_date = $_POST['from_date'];
    $to_date = $_POST['to_date'];
    $description = $_POST['description'];

    $stmt = mysqli_prepare(
    $con,
    "UPDATE holidays 
     SET title = ?, from_date = ?, to_date = ?, description = ?
     WHERE id = ?"
);

mysqli_stmt_bind_param(
    $stmt,
    "ssssi",
    $title,
    $from_date,
    $to_date,
    $description,
    $id
);

mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);


    
    $_SESSION['popup'] = [
        'type' => 'success',
        'message' => 'Holiday updated successfully'
    ];

    header("location:view_holidays.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Edit Holiday</title>
    <?php include('../partials/_links.php'); ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>

    <style>
        .btn-secondary { color: white; }
        .required { color: red; }
        .form-control { border-radius: 6px; }
        .card-header { font-size: 1.1rem; font-weight: 600; }
    </style>
</head>

<body>
<div class="container-scroller">

    <?php include('../partials/_navbar.php'); ?>

    <div class="container-fluid page-body-wrapper">
        <?php 
        include('../partials/_sidebar.php');
        include('../partials/_settings-panel.php');
        ?>

        <div class="main-panel">
            <div class="content-wrapper">
                <div class="row">
                    <div class="col-12 grid-margin stretch-card">
                        <div class="card">
                            <div class="card-body">

                                <h4 class="card-title">
                                    <i class="fa-solid fa-calendar"></i> Edit Holiday
                                </h4>

                                <form class="forms-sample" method="post">

                                    <!-- Title -->
                                    <div class="form-group">
                                        <label>Title <span class="required">*</span></label>
                                        <input type="text"
                                               class="form-control"
                                               name="title"
                                               value="<?= htmlspecialchars($holiday['title']); ?>"
                                               required>
                                    </div>

                                    <!-- From Date -->
                                    <div class="form-group">
                                        <label>From Date <span class="required">*</span></label>
                                        <input type="date"
                                               class="form-control"
                                               name="from_date"
                                               value="<?= $holiday['from_date']; ?>"
                                               required>
                                    </div>

                                    <!-- To Date -->
                                    <div class="form-group">
                                        <label>To Date <span class="required">*</span></label>
                                        <input type="date"
                                               class="form-control"
                                               name="to_date"
                                               value="<?= $holiday['to_date']; ?>"
                                               required>
                                    </div>

                                    <!-- Description -->
                                    <div class="form-group">
                                        <label>Description</label>
                                        <textarea class="form-control"
                                                  name="description"
                                                  rows="4"><?= htmlspecialchars($holiday['description']); ?></textarea>
                                    </div>

                                    <!-- Buttons -->
                                    <button type="submit"
                                            name="updateHoliday"
                                            class="btn btn-primary mr-2">
                                        Update
                                    </button>

                                    <a href="view_holidays.php" class="btn btn-secondary">
                                        Cancel
                                    </a>

                                </form>

                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require_once('../include/sweetAlert.php');
if(isset($_SESSION['popup'])){
    toastMsg($_SESSION['popup']['type'], $_SESSION['popup']['message']);
    unset($_SESSION['popup']);
}
?>
</body>
</html>
