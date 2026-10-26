<?php
session_start();
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}
include('../include/db_config.php');
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>Notifications - SETTribe AMS</title>
    <?php include('../partials/_links.php') ?>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">


    <style>
        .btn-secondary {
            color: white;
        }

        .form-control {
            height: 2.3rem;
        }

        .required {
            color: red;
        }

        .select2-container .select2-selection--single {
            height: 37px !important;
            display: flex;
            align-items: center;
        }

        .card {
            border: none;
            border-radius: 12px;
            background: #fff;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);
            padding: 15px;
        }

        .table thead th {
            background-color: #103d9eff;
            color: #fff;
            font-weight: 600;
            white-space: nowrap;
        }

        .table tbody tr:hover {
            background-color: #f1f5ff;
        }

        .btn-mark-read {
            padding: 4px 8px;
            font-size: 0.8rem;
            white-space: nowrap;
        }

        .dataTables_wrapper .dataTables_length,
        .dataTables_wrapper .dataTables_filter {
            margin-bottom: 15px;
        }

        .dataTables_wrapper .dataTables_paginate {
            margin-top: 15px;
        }

        /* ✅ RESPONSIVE COLUMN WIDTHS */
        .dt-column-srno {
            width: 60px !important;
            min-width: 60px;
        }

        .dt-column-title {
            width: 150px !important;
            min-width: 150px;
            max-width: 150px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .dt-column-message {
            width: 250px !important;
            min-width: 250px;
            max-width: 250px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .dt-column-date {
            width: 140px !important;
            min-width: 140px;
        }

        .dt-column-status {
            width: 90px !important;
            min-width: 90px;
        }

        .dt-column-action {
            width: 120px !important;
            min-width: 120px;
        }

        /* ✅ HORIZONTAL SCROLL */
        .dataTables_scrollBody {
            overflow-x: auto !important;
            -webkit-overflow-scrolling: touch;
        }

        .table-responsive {
            overflow: visible !important;
        }

        /* ✅ Mobile optimization */
        @media (max-width: 768px) {
            .dt-column-message {
                width: 180px !important;
                min-width: 180px;
                max-width: 180px;
            }

            .dt-column-title {
                width: 120px !important;
                min-width: 120px;
                max-width: 120px;
            }
        }
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
                        <div class="col-lg-12 grid-margin stretch-card">
                            <div class="card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <h4 class="card-title mb-0">Notifications</h4>
                                        <button class="btn btn-primary" onclick="markAllAsRead()">
                                            <i class="fa fa-check mr-1"></i>Mark All as Read
                                        </button>
                                    </div>

                                    <!-- ✅ RESPONSIVE DATATABLE CONTAINER -->
                                    <div class="table-responsive">
                                        <div class="dataTables_scroll">
                                            <table class="table table-hover table-sm" id="notificationTable"
                                                width="100%">
                                                <thead>
                                                    <tr>
                                                        <th class="dt-column-srno">Sr. No.</th>
                                                        <th class="dt-column-title">Title</th>
                                                        <th class="dt-column-message">Message</th>
                                                        <th class="dt-column-date">Date</th>
                                                        <th class="dt-column-status">Status</th>
                                                        <th class="dt-column-action">Action</th>
                                                    </tr>
                                                </thead>
                                                <tbody></tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Core JS -->
    <script src="../vendors/js/vendor.bundle.base.js"></script>
    <!-- Sidebar and template scripts -->
    <script src="../js/off-canvas.js"></script>
    <script src="../js/hoverable-collapse.js"></script>
    <script src="../js/template.js"></script>
    <script src="../js/settings.js"></script>
    <script src="../js/todolist.js"></script>

    <!-- ✅ DATATABLES -->
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>

    <!-- Plugins -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <!-- Custom Script -->
    <script>
        $(document).ready(function () {
            // ✅ FULLY RESPONSIVE DATATABLE
            let table = $('#notificationTable').DataTable({
                ajax: {
                    url: '../ajax/fetch_notifications_datatable.php',
                    type: 'GET',
                    dataSrc: 'data'
                },
                columns: [
                    {
                        data: null,
                        render: function (data, type, row, meta) {
                            return meta.row + 1;
                        },
                        className: 'dt-column-srno all'
                    },
                    {
                        data: 'title',
                        className: 'dt-column-title all',
                        render: function (data, type, row) {
                            return '<span title="' + data + '">' + data + '</span>';
                        }
                    },
                    
                       {
    data: 'message',
    className: 'dt-column-message all',
    render: function (data, type, row) {
        var safeMsg = $('<div>').text(data).html();
        var linkHtml = '';
      if (row.link && row.link.trim() !== '' && row.type === 'meeting') {
            var safeLink = $('<div>').text(row.link).html();
            linkHtml = '<br><a href="' + safeLink + '" target="_blank" rel="noopener noreferrer" '
                + 'class="btn btn-success btn-sm mt-1" style="font-size:.72rem;padding:3px 10px;white-space:nowrap;">'
                + '<i class="fa fa-video mr-1"></i>Join Meeting</a>';
        }
        return '<span title="' + safeMsg + '">' + safeMsg + '</span>' + linkHtml;
    }
},
                    {
                        data: 'created_at',
                        className: 'dt-column-date all'
                    },
                    {
                        data: 'status',
                        className: 'dt-column-status all'
                    },
                    {
                        data: null,
                        className: 'dt-column-action all',
                        orderable: false,
                        render: function (data, type, row) {
                            if (row.is_read == 0) {
                                return `<button class="btn btn-primary btn-mark-read btn-sm" onclick="markAsRead(${row.id})">
                                            <i class="fa fa-eye"></i> Read
                                        </button>`;
                            } else {
                                return `<span class="badge badge-success"><i class="fa fa-check"></i> Read</span>`;
                            }
                        }
                    }
                ],
                // ✅ RESPONSIVE CONFIGURATION
                responsive: {
                    details: {
                        type: 'column',
                        target: 0
                    }
                },
                scrollX: true,           // Horizontal scroll
                scrollCollapse: true,
                autoWidth: false,        // Disable auto-width for fixed columns
                pageLength: 15,          // More rows per page
                lengthMenu: [[10, 15, 25, 50, -1], [10, 15, 25, 50, "All"]],
                order: [[3, 'desc']],    // Sort by Date descending
                language: {
                    emptyTable: "No notifications available.",
                    search: "Search notifications:",
                    lengthMenu: "Show _MENU_ notifications per page"
                },
                // ✅ CUSTOM DOM LAYOUT
                dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>' +
                    '<"row"<"col-sm-12"tr>>' +
                    '<"row"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>',
                // ✅ COLUMN WIDTHS (alternative method)
                columnDefs: [
                    { width: '60px', targets: 0 },
                    { width: '150px', targets: 1 },
                    { width: '250px', targets: 2 },
                    { width: '140px', targets: 3 },
                    { width: '90px', targets: 4 },
                    { width: '120px', targets: 5, orderable: false }
                ]
            });

            // ✅ Mark as Read function
            window.markAsRead = function (notificationId) {
                Swal.fire({
                    title: 'Mark as Read?',
                    text: 'This notification will be marked as read.',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, mark as read',
                    cancelButtonText: 'Cancel',
                    confirmButtonColor: '#103d9e'
                }).then((result) => {
                    if (result.isConfirmed) {
                        $.ajax({
                            url: "../ajax/mark_notification_read.php",
                            type: "POST",
                            dataType: "json",
                            data: { notificationId: notificationId },
                            success: function (res) {
                                if (res.success) {
                                    Swal.fire({
                                        icon: 'success',
                                        title: 'Success!',
                                        text: res.message,
                                        timer: 1500,
                                        showConfirmButton: false,
                                        toast: true,
                                        position: 'top-end'
                                    });
                                    table.ajax.reload(null, false);
                                } else {
                                    Swal.fire({
                                        icon: 'error',
                                        title: 'Error!',
                                        text: res.message
                                    });
                                }
                            },
                            error: function () {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Error!',
                                    text: 'Failed to mark notification as read.'
                                });
                            }
                        });
                    }
                });
            };

            // ✅ Mark All as Read function
            window.markAllAsRead = function () {
                Swal.fire({
                    title: 'Mark All as Read?',
                    text: 'All notifications will be marked as read.',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, mark all',
                    cancelButtonText: 'Cancel',
                    confirmButtonColor: '#103d9e'
                }).then((result) => {
                    if (result.isConfirmed) {
                        $.ajax({
                            url: "../ajax/mark_notification_read.php",
                            type: "POST",
                            dataType: "json",
                            data: { action: "markAll" },
                            success: function (res) {
                                if (res.success) {
                                    Swal.fire({
                                        icon: 'success',
                                        title: 'Success!',
                                        text: res.message,
                                        timer: 1500,
                                        showConfirmButton: false,
                                        toast: true,
                                        position: 'top-end'
                                    });
                                    table.ajax.reload(null, false);
                                } else {
                                    Swal.fire({
                                        icon: 'error',
                                        title: 'Error!',
                                        text: res.message
                                    });
                                }
                            },
                            error: function () {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Error!',
                                    text: 'Failed to mark all notifications as read.'
                                });
                            }
                        });
                    }
                });
            };

            // ✅ Auto-refresh every 2 minutes (navbar bell already polls every 30 s)
            setInterval(function () {
                table.ajax.reload(null, false);
            }, 120000);

        });
    </script>

    <?php
    require_once('../include/sweetAlert.php');
    if (isset($_SESSION['status'])) {
        $status = $_SESSION['status'];
        $msg = $_SESSION['msg'];
        echo "<script>toastMsg('" . ($status ? 'success' : 'error') . "', '$msg');</script>";
        unset($_SESSION['status'], $_SESSION['msg']);
    }
    ?>
</body>

</html>