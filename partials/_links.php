<script>
(function() {
    try {
        var m = localStorage.getItem('ams_theme_mode') || 'light';
        if (m === 'dark') {
            document.documentElement.classList.add('dark-theme', 'sidebar-dark');
            document.documentElement.setAttribute('data-theme', 'dark');
            var syncBody = function() {
                if (document.body && !document.body.classList.contains('dark-theme')) {
                    document.body.classList.add('dark-theme', 'sidebar-dark');
                    document.body.setAttribute('data-theme', 'dark');
                }
            };
            syncBody();
            if (!document.body) {
                var obs = new MutationObserver(function() {
                    if (document.body) {
                        syncBody();
                        obs.disconnect();
                    }
                });
                obs.observe(document.documentElement, { childList: true });
                document.addEventListener('DOMContentLoaded', syncBody);
            }
        } else {
            document.documentElement.classList.remove('dark-theme', 'sidebar-dark');
            document.documentElement.setAttribute('data-theme', 'light');
            if (document.body) {
                document.body.classList.remove('dark-theme', 'sidebar-dark');
                document.body.setAttribute('data-theme', 'light');
            }
        }
    } catch(e) {}
})();
</script>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
<title>Dashboard</title>
<link rel="stylesheet" href="../vendors/feather/feather.css">
<link rel="stylesheet" href="../vendors/ti-icons/css/themify-icons.css">
<link rel="stylesheet" href="../vendors/css/vendor.bundle.base.css">
<link rel="stylesheet" href="../vendors/datatables.net-bs4/dataTables.bootstrap4.css">
<link rel="stylesheet" href="../vendors/ti-icons/css/themify-icons.css">
<link rel="stylesheet" href="../js/select.dataTables.min.css">
<link rel="stylesheet" href="../css/vertical-layout-light/style.css?v=2">
<link rel="stylesheet" href="../css/ams-theme.css?v=8">
<link rel="stylesheet" href="../vendors/mdi/css/materialdesignicons.min.css">
<link rel="stylesheet" href="../vendors/select2/select2.min.css">
<link rel="stylesheet" href="../vendors/select2-bootstrap-theme/select2-bootstrap.min.css">
<link rel="stylesheet" href="../vendors/datatables.net-bs4/dataTables.bootstrap4.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="shortcut icon" href="../images/logos/SETTribe_logo.png" />
<link rel="icon" type="image/png" href="../images/logos/SETTribe_logo.png" />
<link rel="apple-touch-icon" href="../images/logos/SETTribe_logo.png" />
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
    .select2-container .select2-selection--single {
        box-sizing: border-box;
        cursor: pointer;
        padding: 1%;
        display: block;
        height: 100%;
        user-select: none;
        -webkit-user-select: none;
    }
    .select2-container--default .select2-selection--multiple .select2-selection__choice{
        font-size: 1rem;
        padding: 0.25rem;
    }

    /* ═══════════════════════════════════════════════════════════════
       UNIVERSAL DEVICE RESPONSIVE SYSTEM (Mobile, Tablet, Laptop, Desktop)
       ═══════════════════════════════════════════════════════════════ */
    *, *::before, *::after {
        box-sizing: border-box;
    }

    html, body {
        max-width: 100vw;
        overflow-x: hidden;
        -webkit-text-size-adjust: 100%;
    }

    /* Container fluid & body wrappers */
    .container-scroller {
        width: 100% !important;
        max-width: 100vw !important;
        overflow-x: hidden !important;
        min-height: 100vh !important;
    }

    .page-body-wrapper {
        min-height: calc(100vh - 60px) !important;
        width: 100% !important;
        max-width: 100vw !important;
    }

    /* Auto responsive tables with momentum scrolling */
    .table-responsive,
    .dataTables_wrapper {
        width: 100% !important;
        max-width: 100% !important;
        overflow-x: auto !important;
        -webkit-overflow-scrolling: touch !important;
    }

    .table {
        width: 100% !important;
    }

    /* Auto images & media */
    img, video, canvas, svg {
        max-width: 100%;
        height: auto;
    }

    /* Card auto fluid sizing */
    .card {
        max-width: 100% !important;
    }

    /* Small screens (< 576px) optimizations */
    @media (max-width: 575.98px) {
        .content-wrapper {
            padding: 12px 8px !important;
            width: 100% !important;
        }

        .card .card-body {
            padding: 14px 12px !important;
        }

        /* Prevent row margin blowouts on mobile */
        .row {
            margin-left: -5px !important;
            margin-right: -5px !important;
        }
        .row > [class*="col-"] {
            padding-left: 5px !important;
            padding-right: 5px !important;
        }

        /* Responsive typography */
        h1, .h1 { font-size: 1.45rem !important; }
        h2, .h2 { font-size: 1.25rem !important; }
        h3, .h3 { font-size: 1.12rem !important; }
        h4, .h4 { font-size: 1.02rem !important; }

        /* Touch friendly form inputs */
        input[type="text"],
        input[type="email"],
        input[type="password"],
        input[type="date"],
        input[type="number"],
        select,
        textarea {
            font-size: 14px !important;
        }

        /* Modals responsive auto-width */
        .modal-dialog {
            margin: 12px auto !important;
            max-width: calc(100vw - 20px) !important;
        }

        /* DataTables filter & pagination stacking */
        .dataTables_wrapper .dataTables_length,
        .dataTables_wrapper .dataTables_filter {
            width: 100% !important;
            float: none !important;
            text-align: left !important;
            margin-bottom: 8px !important;
        }
        .dataTables_wrapper .dataTables_filter input {
            width: 100% !important;
            margin-left: 0 !important;
        }
        .dataTables_wrapper .dataTables_paginate {
            float: none !important;
            text-align: center !important;
            margin-top: 10px !important;
        }
    }

    /* Tablets & Laptops (576px to 991px) */
    @media (min-width: 576px) and (max-width: 991.98px) {
        .content-wrapper {
            padding: 16px 14px !important;
        }
        .modal-dialog {
            max-width: min(600px, calc(100vw - 40px)) !important;
        }
    }
</style>

