<?php
/**
 * weeklyPlanDownload.php
 * Pure PHP — no Composer, no libraries.
 * Format matches the reference PDF: Sr.No | Intern Name | Type | Client | Project | #Hours | Mon–Fri
 * One row per project assignment per intern (same intern spans multiple rows if multiple projects).
 *
 * FIXES:
 *  1. No faint/italic styling for unassigned rows — all text fully visible.
 *  2. PDF is directly downloaded (not previewed) using wkhtmltopdf.
 */

session_start();
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    header('Location: login.php'); exit;
}

include('../include/db_config.php');

define('DAILY_CAP', 8);

$format            = isset($_GET['format'])       ? trim($_GET['format'])       : 'excel';
$weekOffset        = isset($_GET['w'])            ? (int)$_GET['w']             : 0;
$branchFilter      = isset($_GET['branch'])       ? trim($_GET['branch'])       : '';
$modeFilter        = isset($_GET['mode'])         ? trim($_GET['mode'])         : '';
$batchFilter       = isset($_GET['batch'])        ? trim($_GET['batch'])        : '';
$projectFilter     = isset($_GET['project'])      ? trim($_GET['project'])      : '';
$availStatusFilter = isset($_GET['avail_status']) ? trim($_GET['avail_status']) : '';

// ── Week ──────────────────────────────────────────────────────────────────
$todayTs   = strtotime('today');
$dayOfWeek = (int)date('N', $todayTs);
$baseStart = strtotime('-' . ($dayOfWeek - 1) . ' days', $todayTs);
$weekStart = strtotime('+' . ($weekOffset * 7) . ' days', $baseStart);

$weekDays = [];
for ($i = 0; $i < 5; $i++) {
    $ts         = strtotime("+$i days", $weekStart);
    $weekDays[] = ['short' => date('D d-M', $ts), 'value' => date('Y-m-d', $ts)];
}
$weekLabel   = date('d M Y', $weekStart) . ' – ' . date('d M Y', strtotime('+4 days', $weekStart));
$branchLabel = $branchFilter ?: 'All Branches';
$filename    = 'WeeklyPlan_' . str_replace([' ', '/'], '_', $branchLabel) . '_' . date('Ymd', $weekStart);

// ── Interns Query (matching weeklyPlanOverview.php) ──────────────────────
$filterWhereParts = [
    "(TRIM(LOWER(s.status)) = 'active' OR s.status = '1' OR s.status = 1)",
    "(s.regstatus = 'Approved' OR s.regstatus IS NULL OR s.regstatus = '')",
    "s.internId IS NOT NULL",
    "TRIM(s.internId) != ''",
    "(s.designation IS NULL OR LOWER(s.designation) NOT IN ('admin', 'hr', 'superadmin', 'developer', 'md', 'staff'))",
    "(s.userRole IS NULL OR LOWER(s.userRole) NOT IN ('admin', 'hr', 'superadmin', 'md', 'staff'))"
];

if ($branchFilter !== '') {
    $bSafe = mysqli_real_escape_string($con, $branchFilter);
    $filterWhereParts[] = "TRIM(s.branch) = '$bSafe'";
}
if ($modeFilter !== '') {
    $mSafe = mysqli_real_escape_string($con, $modeFilter);
    $filterWhereParts[] = "LOWER(TRIM(s.mode)) = '$mSafe'";
}
if ($batchFilter !== '') {
    $batSafe = mysqli_real_escape_string($con, $batchFilter);
    $filterWhereParts[] = "s.batch_number = '$batSafe'";
}
if ($projectFilter !== '') {
    $pSafe = mysqli_real_escape_string($con, $projectFilter);
    $filterWhereParts[] = "s.internId COLLATE utf8mb4_unicode_ci IN (SELECT DISTINCT internId COLLATE utf8mb4_unicode_ci FROM internAllocations WHERE projectName = '$pSafe')";
}

$internWhere = implode(' AND ', $filterWhereParts);

$internResult = mysqli_query($con,
    "SELECT s.internId, s.fullName,
            COALESCE(s.branch, 'Not Assigned') AS branch,
            COALESCE(s.designation, s.userRole, 'Intern') AS role
     FROM signup s
     WHERE $internWhere
     ORDER BY s.fullName ASC"
);

$interns = [];
if ($internResult) {
    while ($r = mysqli_fetch_assoc($internResult)) {
        $interns[$r['internId']] = $r;
    }
}

// ── Allocations Query ──────────────────────────────────────────────────────
$wStart = $weekDays[0]['value'];
$wEnd   = $weekDays[4]['value']; // Mon–Fri

$internProjects = [];

if (!empty($interns)) {
    $escapedIds = array_map(function($id) use ($con) {
        return "'" . mysqli_real_escape_string($con, $id) . "'";
    }, array_keys($interns));
    $internIdList = implode(',', $escapedIds);

    $allocResult = mysqli_query($con, "
        SELECT a.internId COLLATE utf8mb4_unicode_ci AS internId,
               a.projectName, a.clientName, a.startDate, a.endDate,
               TIME_FORMAT(COALESCE(a.allocStartTime, '09:00'), '%H:%i') AS allocStartTime,
               TIME_FORMAT(COALESCE(a.allocEndTime,   '17:00'), '%H:%i') AS allocEndTime
        FROM internAllocations a
        WHERE a.startDate <= '$wEnd' AND a.endDate >= '$wStart'
          AND a.internId COLLATE utf8mb4_unicode_ci IN ($internIdList)
        ORDER BY a.internId, a.projectName
    ");

    if ($allocResult) {
        while ($a = mysqli_fetch_assoc($allocResult)) {
            $iid = $a['internId'];
            if (!isset($interns[$iid])) continue;

            $sh = explode(':', $a['allocStartTime']);
            $eh = explode(':', $a['allocEndTime']);
            $allocMins = max(0, ((int)$eh[0] * 60 + (int)($eh[1] ?? 0)) - ((int)$sh[0] * 60 + (int)($sh[1] ?? 0)));
            $dailyHours = round($allocMins / 60, 1);

            $dayCols = [];
            foreach ($weekDays as $day) {
                $dayCols[$day['value']] = ($day['value'] >= $a['startDate'] && $day['value'] <= $a['endDate'])
                    ? $dailyHours : 0;
            }
            $internProjects[$iid][] = [
                'project' => $a['projectName'],
                'client'  => $a['clientName'],
                'hours'   => $dailyHours,
                'days'    => $dayCols,
                'total'   => array_sum($dayCols),
            ];
        }
    }
}

// ── Build flat rows ────────────────────────────────────────────────────────
$rows   = [];
$serial = 1;
foreach ($interns as $iid => $intern) {
    $projects = $internProjects[$iid] ?? [];
    $role     = $intern['role'] ?? 'Intern';

    if (empty($projects)) {
        $dayCols = array_fill_keys(array_column($weekDays, 'value'), 0);
        $rows[] = [
            'no'      => $serial++,
            'name'    => $intern['fullName'],
            'type'    => $role,
            'client'  => 'N/A',
            'project' => 'N/A',
            'total'   => 0,
            'days'    => $dayCols,
            'free'    => true,
            'first'   => true,
        ];
    } else {
        $first = true;
        foreach ($projects as $p) {
            $rows[] = [
                'no'      => $first ? $serial++ : '',
                'name'    => $first ? $intern['fullName'] : '',
                'type'    => $first ? $role : '',
                'client'  => $p['client'],
                'project' => $p['project'],
                'total'   => $p['total'],
                'days'    => $p['days'],
                'free'    => false,
                'first'   => $first,
            ];
            $first = false;
        }
    }
}

// ═════════════════════════════════════════════════════════════════════════
// EXCEL
// ═════════════════════════════════════════════════════════════════════════
if ($format === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
    header('Cache-Control: max-age=0');
    echo "\xEF\xBB\xBF";
    $cols = 6 + count($weekDays);
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel">
<head><meta charset="UTF-8">
<style>
body{font-family:Arial,sans-serif;font-size:10pt;color:#222;}
table{border-collapse:collapse;}
.ttl td{
    background:#1f1f1f;
    color:#ffffff;
    font-size:14pt;
    font-weight:900;
    padding:12px 14px;
    border:1px solid #000;
    letter-spacing:0.5px;
}
.sub td{background:#f5f5f5;color:#555;font-size:8.5pt;padding:3px 12px;border:1px solid #ddd;}
.hdr th{
    background:#3b3b3b;
    color:#ffffff;
    font-size:9.5pt;
    font-weight:700;
    padding:8px 8px;
    border:1px solid #2a2a2a;
    text-align:center;
    white-space:nowrap;
}
.hdr th.L{text-align:left;}
/* ALL td: full opacity, no italic, solid color */
td{
    padding:7px 8px;
    border:1px solid #dcdcdc;
    font-size:10pt;
    vertical-align:middle;
    color:#222;
    font-style:normal;
}
.sr{text-align:center;color:#888;width:26px;font-size:9pt;}
.nm{font-weight:bold;color:#222;}
.tp{color:#555;font-size:9pt;}
.na{
    color:#555;
    font-style:italic;
    font-weight:500;
}
.wh{
    text-align:center;
    font-weight:700;
    color:#000;
    background:#f0f0ff;
}
.dh{text-align:center;font-weight:bold;color:#222;}
.d0{text-align:center;color:#bbb;}
.ev td{
    background:#f5f7fa;
}
.sp td{background:#e8e8e8;height:2px;padding:0;}
.ft td{
    background:#e9ecef;
    font-size:10pt;
    font-weight:700;
    border:1px solid #ccc;
    padding:8px;
    color:#222;
}
</style>
</head>
<body>
<table>
<tr class="ttl"><td colspan="<?php echo $cols;?>">Project Wise Interns Utilisation — <?php echo htmlspecialchars($branchLabel);?> — <?php echo htmlspecialchars($weekLabel);?></td></tr>
<tr class="sub"><td colspan="<?php echo $cols;?>">Generated: <?php echo date('d M Y, H:i');?> &nbsp;|&nbsp; Daily cap: <?php echo DAILY_CAP;?>h &nbsp;|&nbsp; Branch: <?php echo htmlspecialchars($branchLabel);?></td></tr>
<tr class="hdr">
<th class="L" style="width:26px;">Sr.No.</th>
<th class="L" style="min-width:140px;">Intern Name</th>
<th class="L" style="min-width:80px;">Type</th>
<th class="L" style="min-width:120px;">Client Name</th>
<th class="L" style="min-width:150px;">Project Name</th>
<th style="min-width:55px;">#Working Hours</th>
<?php foreach($weekDays as $d):?><th style="min-width:48px;"><?php echo htmlspecialchars($d['short']);?></th><?php endforeach;?>
</tr>
<?php
$prevName = '';
$rowNum   = 0;
foreach ($rows as $r):
    $nameChanged = ($r['first'] && $r['name'] !== $prevName && $prevName !== '');
    if ($nameChanged): ?><tr class="sp"><td colspan="<?php echo $cols;?>"></td></tr><?php endif;
    if ($r['first'] && $r['name'] !== '') $prevName = $r['name'];
    $rowNum++;
    $cls = ($rowNum % 2 === 0) ? 'ev' : '';
    // N/A cells get a subtle italic style but NOT faint — color stays normal
    $isNA = ($r['free']);
?>
<tr class="<?php echo $cls;?>">
<td class="sr"><?php echo htmlspecialchars((string)$r['no']);?></td>
<td class="nm"><?php echo htmlspecialchars($r['name']);?></td>
<td class="tp"><?php echo htmlspecialchars($r['type']);?></td>
<td><?php if($isNA): ?><span class="na"><?php echo htmlspecialchars($r['client']);?></span><?php else: ?><?php echo htmlspecialchars($r['client']);?><?php endif;?></td>
<td><?php if($isNA): ?><span class="na"><?php echo htmlspecialchars($r['project']);?></span><?php else: ?><?php echo htmlspecialchars($r['project']);?><?php endif;?></td>
<td class="wh"><?php echo $r['total'] > 0 ? $r['total'] : '—';?></td>
<?php foreach($weekDays as $day): $h = $r['days'][$day['value']] ?? 0;?>
<td class="<?php echo $h > 0 ? 'dh' : 'd0';?>"><?php echo $h > 0 ? $h : '—';?></td>
<?php endforeach;?>
</tr>
<?php endforeach;?>
<?php if (empty($rows)): ?><tr><td colspan="<?php echo $cols;?>" style="text-align:center;color:#aaa;padding:14px;font-style:italic;">No interns found.</td></tr><?php endif;?>
<tr><td colspan="<?php echo $cols;?>" style="border:none;height:8px;background:#fff;"></td></tr>
<tr class="ft">
<td colspan="5" style="text-align:right;">Total Interns: <?php echo count($interns);?> &nbsp;|&nbsp; Week: <?php echo htmlspecialchars($weekLabel);?></td>
<td colspan="<?php echo 1+count($weekDays);?>"></td>
</tr>
</table>
</body></html>
<?php
    exit;
}

// ═════════════════════════════════════════════════════════════════════════
// PDF — direct download via wkhtmltopdf (no preview)
// ═════════════════════════════════════════════════════════════════════════
if ($format === 'pdf') {
    $cols = 6 + count($weekDays);

    // Build HTML string for wkhtmltopdf
    ob_start();
?><!DOCTYPE html>
<html><head>
<meta charset="UTF-8">
<title><?php echo htmlspecialchars($filename);?></title>
<style>
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:Arial,sans-serif;font-size:9pt;color:#2d2d2d;padding:10px;}
.ph{margin-bottom:8px;}
.ph h1{font-size:11pt;color:#222;font-weight:bold;margin-bottom:2px;}
.ph p{font-size:8pt;color:#666;}
table{width:100%;border-collapse:collapse;table-layout:auto;}
thead tr.ttl th{background:#333;color:#fff;font-size:10pt;font-weight:bold;padding:7px 8px;text-align:left;border:1px solid #222;}
thead tr.hdr th{background:#555;color:#fff;font-size:7.5pt;font-weight:bold;padding:5px 4px;text-align:center;border:1px solid #444;white-space:nowrap;}
thead tr.hdr th.L{text-align:left;}
/* ALL tbody td: full color, no italic, no faint */
tbody td{padding:4px 5px;border:1px solid #D0D0D0;font-size:8.5pt;vertical-align:middle;color:#2d2d2d;font-style:normal;}
.sr{text-align:center;color:#999;width:22px;font-size:8pt;}
.nm{font-weight:bold;color:#222;}
.tp{color:#666;font-size:8pt;}
/* N/A cells: slightly muted but still fully readable */
.na-cell{color:#888;font-style:italic;}
.wh{text-align:center;font-weight:bold;color:#222;}
.dh{text-align:center;font-weight:bold;color:#222;}
.d0{text-align:center;color:#AAAAAA;}
tr.ev td{background:#f9f9f9;}
/* separator row */
tr.sp td{background:#e0e0e0;height:2px;padding:0;}
tfoot .ft td{background:#f5f5f5;font-size:8pt;font-weight:bold;color:#333;border:1px solid #ddd;padding:5px 7px;}
.leg{margin-top:8px;font-size:7.5pt;color:#777;}
</style>
</head>
<body>
<div class="ph">
<h1>Project Wise Interns Utilisation &mdash; <?php echo htmlspecialchars($branchLabel);?></h1>
<p>Week: <?php echo htmlspecialchars($weekLabel);?> &nbsp;|&nbsp; Generated: <?php echo date('d M Y, H:i');?> &nbsp;|&nbsp; Daily cap: <?php echo DAILY_CAP;?>h<?php if($branchFilter): ?> &nbsp;|&nbsp; Branch: <strong><?php echo htmlspecialchars($branchFilter);?></strong><?php endif;?></p>
</div>
<table>
<thead>
<tr class="ttl"><th colspan="<?php echo $cols;?>">Project Wise Interns Utilisation (<?php echo htmlspecialchars($branchLabel);?> &mdash; <?php echo htmlspecialchars($weekLabel);?>)</th></tr>
<tr class="hdr">
<th class="L" style="width:22px;">Sr.No.</th>
<th class="L" style="min-width:110px;">Intern Name</th>
<th class="L" style="min-width:65px;">Type</th>
<th class="L" style="min-width:90px;">Client Name</th>
<th class="L" style="min-width:120px;">Project Name</th>
<th style="min-width:48px;">#Working Hours</th>
<?php foreach($weekDays as $d):?><th style="min-width:40px;"><?php echo htmlspecialchars($d['short']);?></th><?php endforeach;?>
</tr>
</thead>
<tbody>
<?php
$prevName = '';
$rowNum   = 0;
foreach ($rows as $r):
    $sep = ($r['first'] && $r['name'] !== '' && $r['name'] !== $prevName && $prevName !== '');
    if ($sep): ?><tr class="sp"><td colspan="<?php echo $cols;?>"></td></tr><?php endif;
    if ($r['first'] && $r['name'] !== '') $prevName = $r['name'];
    $rowNum++;
    $cls  = ($rowNum % 2 === 0) ? 'ev' : '';
    $isNA = ($r['free']);
?>
<tr class="<?php echo $cls;?>">
<td class="sr"><?php echo htmlspecialchars((string)$r['no']);?></td>
<td class="nm"><?php echo htmlspecialchars($r['name']);?></td>
<td class="tp"><?php echo htmlspecialchars($r['type']);?></td>
<td class="<?php echo $isNA ? 'na-cell' : '';?>"><?php echo htmlspecialchars($r['client']);?></td>
<td class="<?php echo $isNA ? 'na-cell' : '';?>"><?php echo htmlspecialchars($r['project']);?></td>
<td class="wh"><?php echo $r['total'] > 0 ? $r['total'] : '—';?></td>
<?php foreach($weekDays as $day): $h = $r['days'][$day['value']] ?? 0;?>
<td class="<?php echo $h > 0 ? 'dh' : 'd0';?>"><?php echo $h > 0 ? $h : '—';?></td>
<?php endforeach;?>
</tr>
<?php endforeach;?>
<?php if (empty($rows)): ?><tr><td colspan="<?php echo $cols;?>" style="text-align:center;color:#aaa;padding:14px;">No interns found.</td></tr><?php endif;?>
</tbody>
<tfoot>
<tr class="ft">
<td colspan="5" style="text-align:right;">Total Interns: <?php echo count($interns);?> &nbsp;|&nbsp; Week: <?php echo htmlspecialchars($weekLabel);?></td>
<td colspan="<?php echo 1+count($weekDays);?>"></td>
</tr>
</tfoot>
</table>
<div class="leg">Numbers = daily hours assigned &nbsp;|&nbsp; — = not assigned that day &nbsp;|&nbsp; N/A rows = no project assigned this week</div>
</body></html>
<?php
    $htmlContent = ob_get_clean();

    // Write HTML to a temp file
    $tmpHtml = tempnam(sys_get_temp_dir(), 'wkpdf_') . '.html';
    $tmpPdf  = tempnam(sys_get_temp_dir(), 'wkpdf_') . '.pdf';
    file_put_contents($tmpHtml, $htmlContent);

    // Run wkhtmltopdf
    $wkPath = trim(shell_exec('which wkhtmltopdf'));
    if (empty($wkPath)) $wkPath = '/usr/bin/wkhtmltopdf';

    $cmd = escapeshellarg($wkPath)
        . ' --page-size A3'
        . ' --orientation Landscape'
        . ' --margin-top 8mm'
        . ' --margin-bottom 8mm'
        . ' --margin-left 8mm'
        . ' --margin-right 8mm'
        . ' --encoding utf-8'
        . ' --quiet'
        . ' ' . escapeshellarg($tmpHtml)
        . ' ' . escapeshellarg($tmpPdf)
        . ' 2>&1';

    shell_exec($cmd);

    if (file_exists($tmpPdf) && filesize($tmpPdf) > 0) {
        // Stream PDF directly as download
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '.pdf"');
        header('Content-Length: ' . filesize($tmpPdf));
        header('Cache-Control: max-age=0');
        readfile($tmpPdf);
    } else {
        // Fallback: stream the HTML as a file download if wkhtmltopdf failed
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.html"');
        echo $htmlContent;
    }

    // Cleanup temp files
    @unlink($tmpHtml);
    @unlink($tmpPdf);
    exit;
}

header('Location: weeklyPlanOverview.php?w=' . $weekOffset . '&branch=' . urlencode($branchFilter));
exit;
?>