<?php
session_start();
if (!isset($_SESSION['userId'])) {
    header("Location: login.php");
    exit;
}
// role based access
if($_SESSION['userRole']!=='developer') {
    echo '<script>
        alert("You are not authorized to access this page.");
        window.location.href = "login.php";
        </script>';
    die();
} 
include('../include/db_config.php');
?>

<!DOCTYPE html>
<html>
<head>
    <title>Developer Feedback Panel</title>
    <?php include('../partials/_links.php'); ?>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
</head>

<body>
<div class="container-scroller">
<?php include('../partials/_navbar.php'); ?>
<div class="container-fluid page-body-wrapper">
<?php include('../partials/_sidebar.php'); ?>

<div class="main-panel">
<div class="content-wrapper">

<div class="card">
<div class="card-body">
<h4 class="card-title">Feedback / Issues</h4>

<div class="row mb-3">
    <div class="col-md-3">
        <select id="filterType" class="form-control">
            <option value="">All Types</option>
        </select>
    </div>

    <div class="col-md-3">
        <select id="filterStatus" class="form-control">
            <option value="">All Status</option>
        </select>
    </div>

    <div class="col-md-4">
        <select id="filterEmployee" class="form-control">
            <option value="">All Employees</option>
        </select>
    </div>
</div>

<div class="table-responsive">
<table class="table table-hover" id="feedbackTable">
<thead>
<tr>
    <th></th>
    <th>Sr. No.</th>
    <th>Module</th>
    <th>Description</th>
    <th>Type</th>
    <th>Status</th>
    <th>Raised By</th>
    <th>Action</th>
</tr>
</thead>
<tbody>
<?php
$q = mysqli_query($con, "
    SELECT
        f.*,
        s.fullName
    FROM feedbackIssues f
    LEFT JOIN signup s
        ON f.userId COLLATE utf8mb4_unicode_ci
         = s.userId COLLATE utf8mb4_unicode_ci
    ORDER BY f.createdAt DESC
");

if (!$q) {
    die('SQL ERROR: ' . mysqli_error($con));
}

function parseDescription($text)
{
    $result = [
        'description' => '',
        'remark'      => '',
        'deadline'    => '',
        'attachment'  => ''
    ];

    if (!$text) return $result;

    // Extract attachment line
    if (preg_match('/Attachment:\s*(.+)$/m', $text, $m)) {
        $result['attachment'] = trim($m[1]);
    }

    $parts = preg_split('/---(REMARK|DEADLINE)---/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    $result['description'] = trim($parts[0]);

    for ($i = 1; $i < count($parts); $i += 2) {
        $key = $parts[$i];
        $value = trim($parts[$i + 1] ?? '');

        if ($key === 'REMARK') {
            // Remove attachment text from remark
            $result['remark'] = trim(preg_replace('/Attachment:\s*.+$/m', '', $value));
        }

        if ($key === 'DEADLINE') {
            $result['deadline'] = $value;
        }
    }

    return $result;
}

while ($r = mysqli_fetch_assoc($q)) {

    $parsed      = parseDescription($r['description']);
    $isClosed    = $r['status'] === 'Closed';
    $hasDeadline = !empty($parsed['deadline']);

    // Short description for table
    $shortDesc = mb_strimwidth(
        strip_tags($parsed['description']),
        0,
        80,
        '…'
    );

    // Extra data for row expand
    $data = [
    'description' => nl2br(htmlspecialchars($parsed['description'])),
    'remark'     => nl2br(htmlspecialchars($parsed['remark'] ?: '-')),
    'attachment' => $parsed['attachment'],
    'deadline'   => $parsed['deadline']
        ? (new DateTime($parsed['deadline']))->format('d M Y, h:i A')
        : '-',
    'createdAt'  => (new DateTime($r['createdAt']))->format('d M Y, h:i A')
    ];

    $dataAttr = htmlspecialchars(json_encode($data), ENT_QUOTES);

    echo '
    <tr data-extra=\'' . $dataAttr . '\'>
        <td class="details-control">
            <i class="mdi mdi-chevron-down"></i>
        </td>

        <td></td> <!-- SR NO -->

        <td>' . htmlspecialchars($r['module']) . '</td>

        <td class="desc-col">
            <span title="' . htmlspecialchars($parsed['description']) . '">
                ' . htmlspecialchars($shortDesc) . '
            </span>
        </td>

        <td>
            <span class="badge badge-info">' . htmlspecialchars($r['type']) . '</span>
        </td>

        <td><span class="badge badge-' . ($isClosed ? 'success' : 'warning') . '">' . htmlspecialchars(trim($r['status'])) . '</span></td>

        <td>' . htmlspecialchars($r['fullName'] ?: 'Unknown') . '</td>

        <td>';
    
    if ($isClosed) {
        echo '
            <button class="btn btn-sm btn-secondary" disabled>Closed</button>
            <button class="btn btn-sm btn-warning" onclick="addDeadline(' . $r['id'] . ')">
                ' . ($hasDeadline ? 'Change Deadline' : 'Add Deadline') . '
            </button>
        ';
    } else {
        echo '
            <button class="btn btn-sm btn-danger" onclick="closeIssue(' . $r['id'] . ')">Close</button>
            <button class="btn btn-sm btn-warning" onclick="addDeadline(' . $r['id'] . ')">
                ' . ($hasDeadline ? 'Change Deadline' : 'Add Deadline') . '
            </button>
        ';
    }

    echo '</td></tr>';
}
?>
</tbody>
</table>
</div>

</div>
</div>

</div>
</div>
</div>
</div>

<script src="../vendors/js/vendor.bundle.base.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
// TOGGLE ROW
$(document).ready(function () {

        function format(data) {
            let attachmentHtml = data.attachment
                ? `<p><strong>Attachment:</strong>
                    <a href="../uploads/issues/${data.attachment}" target="_blank">
                        ${data.attachment}
                    </a>
                </p>`
                : `<p><strong>Attachment:</strong> -</p>`;

            return `
                <div style="padding:12px 20px; background:#f8f9fc;">
                    <p><strong>Description:</strong><br>${data.description}</p>
                    <hr>
                    <p><strong>Remark:</strong><br>${data.remark}</p>
                    ${attachmentHtml}
                    <p><strong>Deadline:</strong> ${data.deadline}</p>
                    <p><strong>Raised At:</strong> ${data.createdAt}</p>
                </div>
            `;
        }

    $('#feedbackTable tbody').on('click', 'td.details-control', function () {
        var tr = $(this).closest('tr');
        var row = table.row(tr);
        var data = JSON.parse(tr.attr('data-extra'));

        if (row.child.isShown()) {
            row.child.hide();
            tr.removeClass('shown');
        } else {
            row.child(format(data)).show();
            tr.addClass('shown');
        }
    });

    var table = $('#feedbackTable').DataTable({
        order: [],
        columnDefs: [
            { orderable: false, targets: 0 },
            {
                targets: [4, 5], // Type & Status columns (UPDATED INDEXES)
                render: function (data, type) {
                    if (type === 'filter' || type === 'sort') {
                        return $('<div>').html(data).text();
                    }
                    return data;
                }
            }
        ],
        initComplete: function () {
            let api = this.api();

            api.column(4).data().unique().sort().each(function (d) {
                $('#filterType').append(`<option value="${$('<div>').html(d).text()}">${$('<div>').html(d).text()}</option>`);
            });

            api.column(5).data().unique().sort().each(function (d) {
                $('#filterStatus').append(`<option value="${$('<div>').html(d).text()}">${$('<div>').html(d).text()}</option>`);
            });

            api.column(6).data().unique().sort().each(function (d) {
                $('#filterEmployee').append(`<option value="${d}">${d}</option>`);
            });
        }
    });

    // ===== AUTO APPLY FILTERS FROM URL =====
    const params = new URLSearchParams(window.location.search);
    const urlType = params.get('type');
    const urlStatus = params.get('status');

    // Apply Type filter
    if (urlType) {
        $('#filterType').val(urlType);
        table.column(4).search('^' + urlType + '$', true, false);
    }

    // Apply Status filter
    if (urlStatus) {
        $('#filterStatus').val(urlStatus);
        table.column(5).search('^' + urlStatus + '$', true, false);
    }

    // Draw table once after all filters
    if (urlType || urlStatus) {
        table.draw();
    }

    table.on('order.dt search.dt', function () {
    table.column(1, { search: 'applied', order: 'applied' })
        .nodes()
        .each(function (cell, i) {
            cell.innerHTML = i + 1;
        });
    }).draw();


    // Filter handlers
    $('#filterType').on('change', function () {
    table.column(4).search(this.value).draw();
    });

    $('#filterStatus').on('change', function () {
        table.column(5).search(this.value).draw();
    });

    $('#filterEmployee').on('change', function () {
        table.column(6).search(this.value).draw();
    });
});

// CLOSE ISSUE
function closeIssue(issueId) {
    Swal.fire({
        title: '\nClose Issue',
        showCloseButton: true,          // ❌ icon top-right
        showCancelButton: false,
        confirmButtonText: 'Submit',
        cancelButtonText: 'Cancel',
        width: 480,
        html: `
            <div class="text-left">
                <label class="font-weight-bold mb-1">
                    Remark <span class="text-danger">*</span>
                </label>
                <textarea
                    id="remark"
                    class="swal2-textarea remark-textarea"
                    placeholder="Enter closing remark"
                    style="min-height:70px; resize:none;"></textarea>

                <label class="font-weight-bold mt-2">
                    Attachment <small class="text-muted">(optional)</small>
                </label>
                <input type="file" id="attachment" class="swal2-file">
            </div>
        `,
        preConfirm: () => {
            const remark = Swal.getPopup().querySelector('#remark').value.trim();
            if (!remark) {
                Swal.showValidationMessage('Remark is required');
                return false;
            }
            return true;
        }
    }).then((result) => {
        if (!result.isConfirmed) return;

        const popup = Swal.getPopup();
        const formData = new FormData();

        formData.append('issueId', issueId);
        formData.append('remark', popup.querySelector('#remark').value);

        const file = popup.querySelector('#attachment').files[0];
        if (file) {
            formData.append('attachment', file);
        }

        fetch('../ajax/close_issue.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
        .then(() => {
            Swal.fire('Closed', 'Issue has been closed successfully.', 'success')
                .then(() => location.reload());
        });
    });
}

// ADD DEADLINE
function addDeadline(id) {

    const now = new Date();
    const minDateTime = now.toISOString().slice(0,16); // yyyy-MM-ddTHH:mm

    Swal.fire({
        title: 'Set Deadline',
        showCloseButton: true,
        confirmButtonText: 'Save Deadline',
        cancelButtonText: 'Cancel',
        width: 420,
        html: `
            <div class="text-left">
                <label class="font-weight-bold">
                    &nbsp;&nbsp;Deadline <span class="text-danger">*</span>
                </label>
                <input type="datetime-local"
                    id="deadline"
                    class="swal2-input"
                    min="${minDateTime}">
            </div>
        `,
        preConfirm: () => {
            const d = document.getElementById('deadline').value;
            if (!d) {
                Swal.showValidationMessage('Deadline is required');
                return false;
            }

            if (new Date(d) < new Date()) {
                Swal.showValidationMessage('Please select a future date & time');
                return false;
            }

            return d;
        }
    }).then((result) => {
        if (!result.isConfirmed) return;

        $.post('../ajax/add_deadline.php', {
            issueId: id,
            deadline: result.value
        }, function () {
            Swal.fire('Saved', 'Deadline updated successfully.', 'success')
                .then(() => location.reload());
        });
    });
}
</script>

<style>
    td.details-control {
    cursor: pointer;
    text-align: center;
    }

    td.details-control i {
        font-size: 18px;
    }

    tr.shown td.details-control i {
        transform: rotate(180deg);
    }

    td.desc-col {
    max-width: 110px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    }

    .swal2-close {
        position: absolute !important;
        top: 12px !important;
        right: 12px !important;
        left: auto !important;
        margin: 0 !important;
        font-size: 22px !important;
        color: #6c757d;
    }

    /* Compact remark textarea */
    .remark-textarea {
        min-height: 55px !important;
        height: 55px !important;
    }
</style>

</body>
</html>
