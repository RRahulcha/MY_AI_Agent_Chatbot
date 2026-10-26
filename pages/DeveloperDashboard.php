<?php
session_start();
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}

// ⭐ ADD THIS LINE - Check password status
include('../include/checkPasswordStatus.php');


if (!isset($_SESSION['userId']) && empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}
include('../include/db_config.php');

// TOTAL ISSUES
$totalIssues = mysqli_fetch_assoc(mysqli_query($con,"
    SELECT
        COUNT(*) total,
        SUM(status='Closed') closed,
        SUM(status!='Closed') open
    FROM feedbackIssues
    WHERE type='Issue'
"));

// TOTAL FEEDBACK
$totalFeedback = mysqli_fetch_assoc(mysqli_query($con,"
    SELECT
        COUNT(*) total,
        SUM(status='Closed') closed,
        SUM(status!='Closed') open
    FROM feedbackIssues
    WHERE type='Feedback'
"));

// TOP EMPLOYEES – ISSUES
$topIssueEmployees = mysqli_query($con,"
    SELECT s.fullName, COUNT(*) AS cnt
    FROM feedbackIssues f
    JOIN signup s
        ON s.userId COLLATE utf8mb4_unicode_ci
         = f.userId COLLATE utf8mb4_unicode_ci
    WHERE f.type = 'Issue'
    GROUP BY f.userId COLLATE utf8mb4_unicode_ci, s.fullName
    ORDER BY cnt DESC
    LIMIT 5
");

// TOP EMPLOYEES – FEEDBACK
$topFeedbackEmployees = mysqli_query($con,"
    SELECT s.fullName, COUNT(*) AS cnt
    FROM feedbackIssues f
    JOIN signup s
        ON s.userId COLLATE utf8mb4_unicode_ci
         = f.userId COLLATE utf8mb4_unicode_ci
    WHERE f.type = 'Feedback'
    GROUP BY f.userId COLLATE utf8mb4_unicode_ci, s.fullName
    ORDER BY cnt DESC
    LIMIT 5
");

// FUNCTION TO PARSE DESCRIPTION
function parseDescription($text)
{
    $result = [
        'description' => '',
        'remark'      => '',
        'deadline'    => '',
        'attachment'  => ''
    ];

    if (!$text) return $result;

    if (preg_match('/Attachment:\s*(.+)$/m', $text, $m)) {
        $result['attachment'] = trim($m[1]);
    }

    $parts = preg_split('/---(REMARK|DEADLINE)---/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    $result['description'] = trim($parts[0]);

    for ($i = 1; $i < count($parts); $i += 2) {
        $key = $parts[$i];
        $value = trim($parts[$i + 1] ?? '');

        if ($key === 'REMARK') {
            $result['remark'] = trim(preg_replace('/Attachment:\s*.+$/m', '', $value));
        }

        if ($key === 'DEADLINE') {
            $result['deadline'] = $value;
        }
    }

    return $result;
}

// MOST FREQUENT ISSUES — BIGRAMS WITH FULL ISSUE DETAILS
$issueQuery = mysqli_query($con, "
    SELECT id, module, description
    FROM feedbackIssues
    WHERE type = 'Issue'
      AND description IS NOT NULL
      AND description <> ''
    ORDER BY id ASC
");

$stopWords = [
    'the','and','is','are','to','of','in','on','for','with','a','an',
    'this','that','it','as','at','by','from','be','has','have','had',
    'was','were','will','would','can','could','should','may','might',
    'issue','issues','problem','error','bug','not','does','did','doing'
];

$frequency = [];

while ($row = mysqli_fetch_assoc($issueQuery)) {
    $parsed = parseDescription($row['description']);
    $text = strtolower($parsed['description']);
    $text = preg_replace('/[^a-z0-9\s]/', ' ', $text);
    $words = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);

    $filtered = [];
    foreach ($words as $w) {
        if (strlen($w) >= 3 && !in_array($w, $stopWords, true)) {
            $filtered[] = $w;
        }
    }

    for ($i = 0; $i + 1 < count($filtered); $i++) {
        $phrase = $filtered[$i] . ' ' . $filtered[$i + 1];

        if (!isset($frequency[$phrase])) {
            $frequency[$phrase] = [
                'issues' => [],
                'earliest' => null
            ];
        }

        if (!isset($frequency[$phrase]['issues'][$row['id']])) {
            $frequency[$phrase]['issues'][$row['id']] = [
                'id' => $row['id'],
                'module' => $row['module'],
                'description' => $parsed['description']
            ];

            // first occurrence = earliest
            if ($frequency[$phrase]['earliest'] === null) {
                $frequency[$phrase]['earliest'] =
                    $frequency[$phrase]['issues'][$row['id']];
            }
        }
    }
}

// sort by occurrence count DESC
uasort($frequency, function ($a, $b) {
    return count($b['issues']) <=> count($a['issues']);
});

// take top 5
$topIssues = array_slice($frequency, 0, 5, true);

$seenIssueIds = [];
$finalIssues = [];

foreach ($topIssues as $phrase => $data) {
    $earliest = $data['earliest'];

    if (isset($seenIssueIds[$earliest['id']])) {
        continue; // already shown
    }

    $seenIssueIds[$earliest['id']] = true;
    $finalIssues[$phrase] = $data;
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Developer Dashboard</title>

    <?php include('../partials/_links.php'); ?>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">

    <style>
        body {
            background: #f5f7fb;
        }

        .card {
            border-radius: 12px;
            border: 1px solid #eee;
        }

        .card-title {
            font-size: 13px;
            font-weight: 600;
            color: #1977e1;
            margin-bottom: 6px;
        }

        .stat-value {
            font-size: 26px;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .stat-sub {
            font-size: 12px;
            margin: 0;
        }

        .table {
            border: none;
            font-size: 12px;
        }

        .table thead th {
            background-color: #5486bfff;
            color: #fff;
            font-weight: 600;
            border: none;
            padding: 6px 10px;
        }

        .table td {
            padding: 8px 10px;
            vertical-align: top;
            white-space: normal;
            word-break: break-word;
        }

        .table tbody tr:hover {
            background-color: #dce4f8ff;
        }

        .issue-desc {
            line-height: 1.3;
        }

        .btn-sm {
            font-size: 11px;
            padding: 3px 8px;
        }

        .scroll-card {
            max-height: 260px;
            overflow-y: auto;
        }

        .expanded-item {
            font-size: 12px;
        }

        .heading {
            color: #1977e1 !important;
            font-weight: 600;
        }
    </style>
</head>

<body>

<div class="container-scroller">
    <?php include('../partials/_navbar.php'); ?>

    <div class="container-fluid page-body-wrapper">
        <?php include('../partials/_sidebar.php'); ?>

        <div class="main-panel">
            <div class="content-wrapper">
            <!-- STATS -->
            <div class="row mb-4">
                <div class="col-md-6 mb-3">
                    <div class="card p-3">
                        <div class="card-title heading">Total Issues</div>

                        <div class="stat-value">
                            <a href="developer_feedback.php?type=issue" class="stat-link">
                                <?= $totalIssues['total'] ?>
                            </a>
                        </div>

                        <p class="stat-sub text-success">
                            <a href="developer_feedback.php?type=issue&status=Closed" class="stat-link text-success">
                                Closed: <?= $totalIssues['closed'] ?>
                            </a>
                        </p>

                        <p class="stat-sub text-danger">
                            <a href="developer_feedback.php?type=issue&status=Open" class="stat-link text-danger">
                                Open: <?= $totalIssues['open'] ?>
                            </a>
                        </p>
                    </div>
                </div>

                <div class="col-md-6 mb-3">
                    <div class="card p-3">
                        <div class="card-title heading">Total Feedback</div>

                        <div class="stat-value">
                            <a href="developer_feedback.php?type=feedback" class="stat-link">
                                <?= $totalFeedback['total'] ?>
                            </a>
                        </div>

                        <p class="stat-sub text-success">
                            <a href="developer_feedback.php?type=feedback&status=Closed" class="stat-link text-success">
                                Closed: <?= $totalFeedback['closed'] ?>
                            </a>
                        </p>

                        <p class="stat-sub text-danger">
                            <a href="developer_feedback.php?type=feedback&status=Open" class="stat-link text-danger">
                                Open: <?= $totalFeedback['open'] ?>
                            </a>
                        </p>
                    </div>
                </div>
            </div>

            <!-- MOST FREQUENT ISSUES -->
            <div class="card mb-4">
                <div class="card-body">
                    <h6 class="card-title heading mb-3">Most Frequent Issues</h6>

                    <div class="table-responsive scroll-card">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Module</th>
                                    <th>Description</th>
                                    <th>Count</th>
                                    <th>Other Occurrence(s)</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($finalIssues as $phrase => $data):
                                $earliest = $data['earliest'];
                                $others = array_filter($data['issues'], function ($i) use ($earliest) {
                                    return $i['id'] !== $earliest['id'];
                                });
                                $rowId = 'occ_' . $earliest['id'];
                            ?>
                                <tr>
                                    <td><?= htmlspecialchars($earliest['module']) ?></td>
                                    <td class="issue-desc"><?= htmlspecialchars($earliest['description']) ?></td>
                                    <td><?= count($data['issues']) ?></td>
                                    <td>
                                        <?php if ($others): ?>
                                            <button class="btn btn-outline-primary btn-sm"
                                                data-toggle="collapse"
                                                data-target="#<?= $rowId ?>">
                                                View <?= count($others) ?>
                                            </button>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                </tr>

                                <?php if ($others): ?>
                                <tr class="collapse" id="<?= $rowId ?>">
                                    <td colspan="4" class="bg-light">
                                        <?php foreach ($others as $o): ?>
                                            <div class="border rounded p-2 mb-2 bg-white expanded-item">
                                                <strong><?= htmlspecialchars($o['module']) ?></strong><br>
                                                <?= htmlspecialchars($o['description']) ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </td>
                                </tr>
                                <?php endif; ?>

                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- TOP EMPLOYEES -->
            <div class="row">
                <div class="col-md-6 mb-3">
                    <div class="card">
                        <div class="card-body">
                            <h6 class="card-title heading">Top Employees – Issues</h6>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Employee</th>
                                            <th>Count</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php while($r=mysqli_fetch_assoc($topIssueEmployees)) { ?>
                                        <tr>
                                            <td><?= htmlspecialchars($r['fullName']) ?></td>
                                            <td><?= $r['cnt'] ?></td>
                                        </tr>
                                    <?php } ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-6 mb-3">
                    <div class="card">
                        <div class="card-body">
                            <h6 class="card-title heading">Top Employees – Feedback</h6>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Employee</th>
                                            <th>Count</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php while($r=mysqli_fetch_assoc($topFeedbackEmployees)) { ?>
                                        <tr>
                                            <td><?= htmlspecialchars($r['fullName']) ?></td>
                                            <td><?= $r['cnt'] ?></td>
                                        </tr>
                                    <?php } ?>
                                    </tbody>
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
<script src="../vendors/js/vendor.bundle.base.js"></script>
<script src="../js/off-canvas.js"></script>
<script src="../js/hoverable-collapse.js"></script>
<script src="../js/template.js"></script>

<?php include('../include/passwordChangeAlert.php'); ?>
</body>
</html>
