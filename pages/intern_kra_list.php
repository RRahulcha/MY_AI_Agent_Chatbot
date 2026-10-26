<?php
session_start();
 
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href='login.php';</script>";
    exit;
}
 
include('../include/db_config.php');
 
// ── Handle export before any HTML output ──────────────────────────
if (isset($_GET['export']) && $_GET['export'] === '1') {
 
    $query2   = mysqli_query($con, "SELECT designation FROM signup WHERE userId = '" . mysqli_real_escape_string($con, $_SESSION['userId']) . "'");
    $user2    = mysqli_fetch_assoc($query2);
    $isRAexp  = ($user2['designation'] === 'resourceAllocator');
    $curUid   = mysqli_real_escape_string($con, $_SESSION['userId']);
 
    $fi = mysqli_real_escape_string($con, trim($_GET['filter_intern'] ?? ''));
    $fw = mysqli_real_escape_string($con, trim($_GET['filter_week']   ?? ''));
    $fy = mysqli_real_escape_string($con, trim($_GET['filter_year']   ?? ''));
    $fl = mysqli_real_escape_string($con, trim($_GET['filter_lead']   ?? ''));
 
    $wh = "k.status = 'active'";
    if (!$isRAexp)              $wh .= " AND k.submittedBy = '$curUid'";
    if (!empty($fi))            $wh .= " AND k.internName LIKE '%$fi%'";
    if (!empty($fw))            $wh .= " AND k.weekNumber = '$fw'";
    if (!empty($fy))            $wh .= " AND k.weekYear = '$fy'";
    if ($isRAexp && !empty($fl)) $wh .= " AND k.submittedByName LIKE '%$fl%'";
 
    $expQ = mysqli_query($con, "SELECT k.* FROM internKraAssignments k WHERE $wh ORDER BY k.submittedAt DESC");
 
    $rows = [];
    while ($r = mysqli_fetch_assoc($expQ)) {
        $r['_colleague'] = 'N/A';
        if ($isRAexp) {
            $cq = mysqli_query($con, "SELECT colleague FROM signup WHERE userId = '" . mysqli_real_escape_string($con, $r['submittedBy']) . "' LIMIT 1");
            if ($cq && mysqli_num_rows($cq) > 0) $r['_colleague'] = mysqli_fetch_assoc($cq)['colleague'] ?? 'N/A';
        }
        $rows[] = $r;
    }
 
    function xe($v) { return htmlspecialchars((string)$v, ENT_XML1); }
    function colLetter($n) {
        $l = '';
        while ($n > 0) { $n--; $l = chr(65 + ($n % 26)) . $l; $n = (int)($n / 26); }
        return $l;
    }
    function sIdx($type, $even = false) {
        switch ($type) {
            case 'hdr_navy':   return 1;
            case 'hdr_blue':   return 2;
            case 'hdr_orange': return 3;
            case 'center':     return $even ? 13 : 5;
            case 'left':       return $even ? 14 : 4;
            case 'name':       return 6;
        }
        return 0;
    }
    function scoreSI($n) { return $n >= 8 ? 7 : ($n >= 5 ? 8 : 9); }
    function avgSI($a)   { return $a >= 75 ? 10 : ($a >= 50 ? 11 : 12); }
    function sCell($col, $row, $val, $si) {
        $ref = colLetter($col) . $row;
        $v   = xe($val);
        return "<c r=\"$ref\" s=\"$si\" t=\"inlineStr\"><is><t>$v</t></is></c>";
    }
    function nCell($col, $row, $val, $si) {
        $ref = colLetter($col) . $row;
        return "<c r=\"$ref\" s=\"$si\"><v>$val</v></c>";
    }
 
    $headers = [
        ['label' => '#',               'bg' => 'navy', 'width' => 5],
        ['label' => 'Intern Name',     'bg' => 'navy', 'width' => 30],
    ];
    if ($isRAexp) {
        $headers[] = ['label' => 'Lead Name',  'bg' => 'navy', 'width' => 22];
        $headers[] = ['label' => 'Colleague',  'bg' => 'navy', 'width' => 18];
    }
    $headers = array_merge($headers, [
        ['label' => 'Week',            'bg' => 'navy',   'width' => 12],
        ['label' => 'Communication',   'bg' => 'blue',   'width' => 16],
        ['label' => 'Confidence',      'bg' => 'blue',   'width' => 13],
        ['label' => 'Clarity',         'bg' => 'blue',   'width' => 11],
        ['label' => 'Teamwork',        'bg' => 'blue',   'width' => 11],
        ['label' => 'Problem Solving', 'bg' => 'blue',   'width' => 16],
        ['label' => 'Learning',        'bg' => 'blue',   'width' => 11],
        ['label' => 'Technical',       'bg' => 'blue',   'width' => 11],
        ['label' => 'GitHub',          'bg' => 'blue',   'width' => 11],
        ['label' => 'AMS Processes',   'bg' => 'blue',   'width' => 14],
        ['label' => 'Avg %',           'bg' => 'orange', 'width' => 10],
        ['label' => 'Submitted',       'bg' => 'navy',   'width' => 15],
    ]);
 
    $colCount = count($headers);
 
    $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <numFmts count="1"><numFmt numFmtId="164" formatCode="0.0%"/></numFmts>
  <fonts count="8">
    <font><sz val="10"/><name val="Arial"/><color rgb="FF000000"/></font>
    <font><sz val="10"/><b/><name val="Arial"/><color rgb="FFFFFFFF"/></font>
    <font><sz val="10"/><b/><name val="Arial"/><color rgb="FF276221"/></font>
    <font><sz val="10"/><b/><name val="Arial"/><color rgb="FF9C6500"/></font>
    <font><sz val="10"/><b/><name val="Arial"/><color rgb="FF9C0006"/></font>
    <font><sz val="10"/><name val="Arial"/><color rgb="FF3A3A4A"/></font>
    <font><sz val="10"/><b/><name val="Arial"/><color rgb="FF2D2C5E"/></font>
    <font><sz val="10"/><name val="Arial"/><color rgb="FF2D2C5E"/></font>
  </fonts>
  <fills count="12">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF1F3864"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF2E75B6"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFED7D31"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFFFFFFF"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFF0F0FA"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFC6EFCE"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFFFEB9C"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFFFC7CE"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFE2EFDA"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFFFF2CC"/></patternFill></fill>
  </fills>
  <borders count="3">
    <border><left/><right/><top/><bottom/><diagonal/></border>
    <border>
      <left style="medium"><color rgb="FF000000"/></left><right style="medium"><color rgb="FF000000"/></right>
      <top style="medium"><color rgb="FF000000"/></top><bottom style="medium"><color rgb="FF000000"/></bottom>
    </border>
    <border>
      <left style="thin"><color rgb="FFB0B0C8"/></left><right style="thin"><color rgb="FFB0B0C8"/></right>
      <top style="thin"><color rgb="FFB0B0C8"/></top><bottom style="thin"><color rgb="FFB0B0C8"/></bottom>
    </border>
  </borders>
  <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
  <cellXfs count="15">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="1" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="1" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="5" fillId="5" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>
    <xf numFmtId="0" fontId="5" fillId="5" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0" fontId="6" fillId="5" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="2" fillId="7" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0" fontId="3" fillId="8" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0" fontId="4" fillId="9" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="164" fontId="2" fillId="7" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1" applyNumberFormat="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="164" fontId="3" fillId="8" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1" applyNumberFormat="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="164" fontId="4" fillId="9" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1" applyNumberFormat="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0" fontId="5" fillId="6" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0" fontId="7" fillId="6" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf>
  </cellXfs>
</styleSheet>';
 
    $sheetRows = '';
    $titleLabel = $isRAexp ? 'INTERN KRA REPORT — RESOURCE ALLOCATOR VIEW' : 'INTERN KRA REPORT';
    $sheetRows .= '<row r="1" ht="40" customHeight="1">';
    $sheetRows .= '<c r="A1" s="1" t="inlineStr"><is><t>' . xe($titleLabel) . '</t></is></c>';
    $sheetRows .= '</row>';
 
    $sheetRows .= '<row r="2" ht="30" customHeight="1">';
    foreach ($headers as $ci => $h) {
        $col = $ci + 1;
        $si  = $h['bg'] === 'blue' ? 2 : ($h['bg'] === 'orange' ? 3 : 1);
        $sheetRows .= sCell($col, 2, $h['label'], $si);
    }
    $sheetRows .= '</row>';
 
    $ri = 3;
    foreach ($rows as $kra) {
        $even = ($ri % 2 === 0);
        $ht   = !empty($kra['batch']) ? 28 : 18;
        $sheetRows .= "<row r=\"$ri\" ht=\"$ht\" customHeight=\"1\">";
        $col = 1;
        $sheetRows .= nCell($col, $ri, $ri - 1, sIdx('center', $even)); $col++;
        $name = $kra['internName'] . (!empty($kra['batch']) ? ' (' . $kra['batch'] . ')' : '');
        $sheetRows .= sCell($col, $ri, $name, 6); $col++;
        if ($isRAexp) {
            $sheetRows .= sCell($col, $ri, $kra['submittedByName'] ?? '', sIdx('left', $even)); $col++;
            $sheetRows .= sCell($col, $ri, $kra['_colleague'], sIdx('left', $even)); $col++;
        }
        $expWeekLabel = '';
        if (!empty($kra['weekNumber']) && !empty($kra['weekYear'])) {
            $expDto = new DateTime();
            $expDto->setISODate((int)$kra['weekYear'], (int)$kra['weekNumber']);
            $expWeekOfMonth = (int)ceil((int)$expDto->format('j') / 7);
            $expWeekLabel   = $expDto->format('M') . '-' . $expWeekOfMonth;
        }
        $sheetRows .= sCell($col, $ri, $expWeekLabel, sIdx('center', $even)); $col++;
        foreach (['communication','confidence','clarity','teamwork','problemSolving','learningAttitude','technicalKnowledge','githubActivity'] as $field) {
            $n = (int)$kra[$field];
            $sheetRows .= nCell($col, $ri, $n, scoreSI($n)); $col++;
        }
        $avg = (float)$kra['averagePercentage'];
        $sheetRows .= nCell($col, $ri, round($avg / 100, 4), avgSI($avg)); $col++;
        $dateStr = !empty($kra['submittedAt']) ? date('d M Y', strtotime($kra['submittedAt'])) : '';
        $sheetRows .= sCell($col, $ri, $dateStr, sIdx('center', $even));
        $sheetRows .= '</row>';
        $ri++;
    }
 
    $colsXml = '<cols>';
    foreach ($headers as $ci => $h) {
        $c = $ci + 1;
        $colsXml .= "<col min=\"$c\" max=\"$c\" width=\"{$h['width']}\" customWidth=\"1\"/>";
    }
    $colsXml .= '</cols>';
 
    $lastColLetter = colLetter($colCount);
    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <sheetViews><sheetView workbookViewId="0">
   <pane ySplit="2" topLeftCell="A3" activePane="bottomLeft" state="frozen"/>
  </sheetView></sheetViews>
  ' . $colsXml . '
  <sheetData>' . $sheetRows . '</sheetData>
  <mergeCells count="1"><mergeCell ref="A1:' . colLetter($colCount) . '1"/></mergeCells>
</worksheet>';
 
    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets><sheet name="Intern KRA" sheetId="1" r:id="rId1"/></sheets>
</workbook>';
 
    $relsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>';
 
    $dotRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>';
 
    $ctXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>';
 
    $files = [
        '[Content_Types].xml'        => $ctXml,
        '_rels/.rels'                => $dotRelsXml,
        'xl/workbook.xml'            => $workbookXml,
        'xl/_rels/workbook.xml.rels' => $relsXml,
        'xl/styles.xml'              => $stylesXml,
        'xl/worksheets/sheet1.xml'   => $sheetXml,
    ];
 
    $zip = ''; $cd = ''; $offset = 0;
    foreach ($files as $name => $content) {
        $nl  = strlen($name);
        $cl  = strlen($content);
        $crc = crc32($content);
        $lh  = "\x50\x4B\x03\x04" . pack('v',20) . pack('v',0) . pack('v',0)
             . pack('v',0x5400) . pack('v',0x5A21)
             . pack('V',$crc) . pack('V',$cl) . pack('V',$cl)
             . pack('v',$nl) . pack('v',0) . $name . $content;
        $zip .= $lh;
        $cd  .= "\x50\x4B\x01\x02" . pack('v',20) . pack('v',20) . pack('v',0) . pack('v',0)
              . pack('v',0x5400) . pack('v',0x5A21)
              . pack('V',$crc) . pack('V',$cl) . pack('V',$cl)
              . pack('v',$nl) . pack('v',0) . pack('v',0) . pack('v',0) . pack('v',0)
              . pack('V',0) . pack('V',$offset) . $name;
        $offset += strlen($lh);
    }
    $n   = count($files);
    $cds = strlen($cd);
    $zip .= $cd . "\x50\x4B\x05\x06" . pack('v',0) . pack('v',0)
          . pack('v',$n) . pack('v',$n) . pack('V',$cds) . pack('V',$offset) . pack('v',0);
 
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $fnRangeParts = [];
    if (!empty($fy)) $fnRangeParts[] = $fy;
    if (!empty($fw)) {
        $fnWDto = new DateTime();
        $fnWDto->setISODate((int)(!empty($fy) ? $fy : date('Y')), (int)$fw);
        $fnWeekOfMonth  = (int)ceil((int)$fnWDto->format('j') / 7);
        $fnRangeParts[] = $fnWDto->format('M') . $fnWeekOfMonth;
    }
    if (!empty($fi)) $fnRangeParts[] = preg_replace('/[^a-zA-Z0-9]/', '', $fi);
    $fnSuffix = !empty($fnRangeParts) ? '_' . implode('_', $fnRangeParts) : '';
    header('Content-Disposition: attachment; filename="Intern_KRA_' . date('Y_m_d') . $fnSuffix . '.xlsx"');
    header('Content-Length: ' . strlen($zip));
    header('Cache-Control: max-age=0');
    echo $zip;
    exit;
}
 
include('../include/db_config.php');
 
$query        = mysqli_query($con, "SELECT designation, userRole, fullName FROM signup WHERE userId = '" . mysqli_real_escape_string($con, $_SESSION['userId']) . "'");
$user         = mysqli_fetch_assoc($query);
$allowedRoles = ['seniorintern', 'teamlead', 'resourceallocator', 'resourcehead', 'admin', 'hr', 'juniorintern'];
 
$designation = str_replace([' ', '_'], '', strtolower($user['designation'] ?? ''));
$uRole       = str_replace([' ', '_'], '', strtolower($user['userRole'] ?? ''));
 
if (!$user || (!in_array($designation, $allowedRoles) && !in_array($uRole, $allowedRoles))) {
    echo "<script>alert('Unauthorized Access'); window.location.href='logout.php';</script>";
    exit;
}
 
$isResourceAllocator = in_array($designation, ['resourceallocator', 'resourcehead', 'resourcemanager']) || in_array($uRole, ['resourceallocator', 'resourcehead', 'resourcemanager']);
$designationLabels = [
    'juniorIntern'      => 'Junior Intern',
    'seniorIntern'      => 'Senior Intern',
    'teamLead'          => 'Team Lead',
    'resourceAllocator' => 'Resource Allocator'
];
$currentUserId = mysqli_real_escape_string($con, $_SESSION['userId']);
 
$filterIntern = mysqli_real_escape_string($con, trim($_GET['filter_intern'] ?? ''));
$filterWeek   = mysqli_real_escape_string($con, trim($_GET['filter_week']   ?? ''));
$filterYear   = mysqli_real_escape_string($con, trim($_GET['filter_year']   ?? ''));
$filterLead   = mysqli_real_escape_string($con, trim($_GET['filter_lead']   ?? ''));
 
$where = "k.status = 'active'";
if (!$isResourceAllocator)                       $where .= " AND k.submittedBy = '$currentUserId'";
if (!empty($filterIntern))                       $where .= " AND k.internName LIKE '%$filterIntern%'";
if (!empty($filterWeek))                         $where .= " AND k.weekNumber = '$filterWeek'";
if (!empty($filterYear))                         $where .= " AND k.weekYear = '$filterYear'";
if ($isResourceAllocator && !empty($filterLead)) $where .= " AND k.submittedByName LIKE '%$filterLead%'";
 
$kraQuery  = mysqli_query($con, "SELECT k.* FROM internKraAssignments k WHERE $where ORDER BY k.submittedAt DESC");
$yearQuery = mysqli_query($con, "SELECT DISTINCT weekYear FROM internKraAssignments WHERE status = 'active' ORDER BY weekYear DESC");
 
// Count total for the stats bar
$totalRows = $kraQuery ? mysqli_num_rows($kraQuery) : 0;
 
function scoreBadge($score) {
    $score = (int)$score;
    if ($score >= 8)      $cls = 'badge-success';
    elseif ($score >= 5)  $cls = 'badge-warning';
    else                  $cls = 'badge-danger';
    return '<span class="badge ' . $cls . '" style="font-size:0.75rem;padding:4px 8px;">' . $score . '</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Intern KRA Records</title>
    <?php include('../partials/_links.php'); ?>
    <link href="../vendors/select2/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css"/>
    <style>
        /* ── Page wrapper ───────────────────────────────── */
        .kra-page { background: #f4f4fb; min-height: 100vh; }
 
        /* ── Page header banner ─────────────────────────── */
        .kra-page-banner {
            background: linear-gradient(135deg, #4B49AC 0%, #7978e9 100%);
            border-radius: 18px;
            padding: 22px 28px;
            margin-bottom: 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 14px;
            box-shadow: 0 4px 20px rgba(75,73,172,0.22);
            position: relative;
            overflow: hidden;
        }
 
        .kra-page-banner::before {
            content: '';
            position: absolute;
            top: -40px; right: -40px;
            width: 180px; height: 180px;
            border-radius: 50%;
            background: rgba(255,255,255,0.06);
            pointer-events: none;
        }
 
        .kra-page-banner::after {
            content: '';
            position: absolute;
            bottom: -60px; right: 120px;
            width: 220px; height: 220px;
            border-radius: 50%;
            background: rgba(255,255,255,0.04);
            pointer-events: none;
        }
 
        .banner-left { position: relative; z-index: 1; }
 
        .banner-title {
            font-size: 1.35rem;
            font-weight: 700;
            color: #fff;
            margin: 0 0 4px 0;
            letter-spacing: -0.01em;
        }
 
        .banner-sub {
            font-size: 0.82rem;
            color: rgba(255,255,255,0.72);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
 
        .banner-actions {
            display: flex;
            gap: 10px;
            align-items: center;
            position: relative;
            z-index: 1;
        }
 
        /* ── Stat chips row ─────────────────────────────── */
        .stat-chips {
            display: flex;
            gap: 10px;
            margin-bottom: 18px;
            flex-wrap: wrap;
        }
 
        .stat-chip {
            background: #fff;
            border: 1px solid #e8e8f0;
            border-radius: 10px;
            padding: 8px 16px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.8rem;
            color: #6b6b80;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        }
 
        .stat-chip strong {
            font-size: 1.05rem;
            font-weight: 700;
            color: #2d2c5e;
        }
 
        .stat-chip .chip-dot {
            width: 8px; height: 8px;
            border-radius: 50%;
            flex-shrink: 0;
        }
 
        /* ── Filter strip ───────────────────────────────── */
        .filter-strip {
            background: #fff;
            border-radius: 14px;
            padding: 18px 20px;
            margin-bottom: 18px;
            border: 1px solid #e8e8f0;
            box-shadow: 0 1px 4px rgba(0,0,0,0.04);
        }
 
        .filter-strip-title {
            font-size: 0.68rem;
            font-weight: 700;
            color: #9090aa;
            letter-spacing: 0.07em;
            text-transform: uppercase;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
 
        .filter-strip-title::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #f0f0f5;
        }
 
        .filter-strip .form-control,
        .filter-strip .form-control-sm {
            font-size: 0.835rem;
            border: 1px solid #dddde8;
            border-radius: 8px;
            height: 36px;
            color: #3a3a4a;
            background: #fafafa;
            transition: border-color 0.15s, box-shadow 0.15s;
        }
 
        .filter-strip .form-control:focus {
            border-color: #4B49AC;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(75,73,172,0.1);
        }
 
        .filter-strip label {
            font-size: 0.72rem;
            font-weight: 700;
            color: #6b6b80;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            margin-bottom: 5px;
            display: block;
        }
 
        /* ── Buttons ────────────────────────────────────── */
        .btn-primary {
            background: #4B49AC;
            border-color: #4B49AC;
            border-radius: 8px;
            font-size: 0.82rem;
            font-weight: 600;
            padding: 7px 20px;
            letter-spacing: 0.02em;
            transition: background 0.15s, box-shadow 0.15s, transform 0.1s;
        }
 
        .btn-primary:hover {
            background: #3f3e96;
            border-color: #3f3e96;
            box-shadow: 0 4px 12px rgba(75,73,172,0.28);
            transform: translateY(-1px);
        }
 
        .btn-primary:active { transform: translateY(0); }
 
        /* White ghost button for banner */
        .btn-banner-ghost {
            background: rgba(255,255,255,0.15);
            border: 1.5px solid rgba(255,255,255,0.4);
            color: #fff;
            border-radius: 8px;
            font-size: 0.82rem;
            font-weight: 600;
            padding: 7px 18px;
            letter-spacing: 0.02em;
            transition: background 0.15s, transform 0.1s;
            white-space: nowrap;
        }
 
        .btn-banner-ghost:hover {
            background: rgba(255,255,255,0.25);
            color: #fff;
            transform: translateY(-1px);
        }
 
        .btn-banner-export {
            background: #fff;
            border: none;
            color: #4B49AC;
            border-radius: 8px;
            font-size: 0.82rem;
            font-weight: 700;
            padding: 7px 18px;
            letter-spacing: 0.02em;
            transition: background 0.15s, box-shadow 0.15s, transform 0.1s;
            white-space: nowrap;
        }
 
        .btn-banner-export:hover {
            background: #f0f0fa;
            color: #3f3e96;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            transform: translateY(-1px);
        }
 
        .btn-light.btn-sm {
            border-radius: 8px;
            font-size: 0.82rem;
            font-weight: 500;
            padding: 7px 18px;
            background: #f0f0f8;
            border-color: #dddde8;
            color: #555;
            transition: background 0.15s, transform 0.1s;
        }
 
        .btn-light.btn-sm:hover {
            background: #e4e3f4;
            border-color: #c8c7e0;
            transform: translateY(-1px);
        }
 
        .btn-warning.btn-sm {
            border-radius: 7px;
            font-size: 0.75rem;
            font-weight: 600;
            padding: 4px 12px;
            letter-spacing: 0.01em;
        }
 
        /* ── Table card ─────────────────────────────────── */
        .kra-card {
            background: #fff;
            border-radius: 16px;
            border: 1px solid #e8e8f0;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            overflow: visible;
        }
 
        .kra-card-inner {
            padding: 0 16px 16px 16px;
        }
 
        /* ── DataTables toolbar ──────────────────────────── */
        .dt-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 16px 10px 16px;
            border-bottom: 1px solid #f0f0f5;
            flex-wrap: wrap;
            gap: 8px;
        }
 
        .dt-toolbar .dataTables_length label {
            font-size: 0.8rem;
            color: #6b6b80;
            font-weight: 500;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 6px;
        }
 
        .dt-toolbar .dataTables_length select {
            font-size: 0.8rem;
            border: 1px solid #dddde8;
            border-radius: 6px;
            padding: 3px 8px;
            color: #3a3a4a;
            background: #fafafa;
            height: 30px;
        }
 
        /* ── Table ──────────────────────────────────────── */
        .kra-table {
            margin-bottom: 0;
            border-collapse: separate;
            border-spacing: 0;
        }
 
        .kra-table thead tr {
            background: linear-gradient(135deg, #4B49AC 0%, #5c5bbf 100%);
        }
 
        .kra-table thead th {
            background: transparent !important;
            color: rgba(255,255,255,0.92) !important;
            font-size: 0.7rem;
            font-weight: 600;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            white-space: nowrap;
            padding: 12px 14px;
            border: none !important;
        }
 
        .kra-table thead th:first-child { border-radius: 10px 0 0 0; }
        .kra-table thead th:last-child  { border-radius: 0 10px 0 0; }
 
        /* Sorting arrows override for dark header */
        .kra-table thead th.sorting::after,
        .kra-table thead th.sorting_asc::after,
        .kra-table thead th.sorting_desc::after {
            color: rgba(255,255,255,0.5) !important;
        }
 
        .kra-table tbody td {
            font-size: 0.82rem;
            vertical-align: middle;
            padding: 11px 14px;
            border-top: 1px solid #f4f4fb;
            border-left: none;
            border-right: none;
            border-bottom: none;
            color: #3a3a4a;
        }
 
        .kra-table tbody tr:first-child td { border-top: none; }
 
        .kra-table tbody tr {
            transition: background 0.12s ease;
        }
 
        .kra-table tbody tr:hover {
            background: #f6f5ff;
        }
 
        .kra-table tbody tr:hover td {
            color: #2d2c5e;
        }
 
        /* Zebra stripe - very subtle */
        .kra-table tbody tr:nth-child(even) {
            background: #fafafa;
        }
        .kra-table tbody tr:nth-child(even):hover {
            background: #f6f5ff;
        }
 
        /* ── Avg pill ───────────────────────────────────── */
        .avg-pill {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.76rem;
            font-weight: 700;
            letter-spacing: 0.02em;
        }
 
        .avg-high { background: #d1fae5; color: #065f46; }
        .avg-mid  { background: #fef3c7; color: #92400e; }
        .avg-low  { background: #fee2e2; color: #991b1b; }
 
        /* ── Intern name cell ───────────────────────────── */
        .intern-name {
            font-weight: 600;
            color: #2d2c5e;
        }
 
        /* ── Week cell ──────────────────────────────────── */
        .week-cell {
            font-size: 0.76rem;
            font-weight: 600;
            color: #5957c2;
            background: #eeeef9;
            border-radius: 6px;
            padding: 3px 9px;
            display: inline-block;
            white-space: nowrap;
            border: 1px solid #ddddf5;
        }
 
        /* ── Date cell ──────────────────────────────────── */
        .date-cell {
            font-size: 0.78rem;
            color: #8888a0;
            white-space: nowrap;
        }
 
        /* ── Serial number ──────────────────────────────── */
        .sl-num {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 22px;
            height: 22px;
            background: #f0f0f8;
            border-radius: 6px;
            font-size: 0.72rem;
            font-weight: 700;
            color: #9090b8;
        }
 
        /* ── DataTables pagination ───────────────────────── */
        .dt-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 16px;
            border-top: 1px solid #f0f0f5;
            flex-wrap: wrap;
            gap: 8px;
        }
 
        .dataTables_info {
            font-size: 0.78rem;
            color: #8888a0;
        }
 
        .dataTables_paginate .paginate_button {
            font-size: 0.78rem !important;
            border-radius: 7px !important;
            padding: 4px 10px !important;
            margin: 0 2px !important;
            border: 1px solid #e8e8f0 !important;
            color: #4B49AC !important;
            background: #fff !important;
            transition: all 0.15s !important;
        }
 
        .dataTables_paginate .paginate_button:hover {
            background: #eeeef9 !important;
            border-color: #4B49AC !important;
            color: #3f3e96 !important;
        }
 
        .dataTables_paginate .paginate_button.current,
        .dataTables_paginate .paginate_button.current:hover {
            background: #4B49AC !important;
            border-color: #4B49AC !important;
            color: #fff !important;
            font-weight: 700 !important;
        }
 
        .dataTables_paginate .paginate_button.disabled,
        .dataTables_paginate .paginate_button.disabled:hover {
            color: #ccc !important;
            background: #fafafa !important;
            border-color: #eee !important;
            cursor: default !important;
        }
 
        /* ── RA badge ───────────────────────────────────── */
        .ra-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: rgba(255,255,255,0.18);
            border: 1px solid rgba(255,255,255,0.3);
            color: #fff;
            font-size: 0.7rem;
            font-weight: 600;
            padding: 2px 10px;
            border-radius: 20px;
            letter-spacing: 0.03em;
        }
 
        /* ── Empty state ────────────────────────────────── */
        .empty-state {
            padding: 56px 20px;
            text-align: center;
            color: #8888a0;
            font-size: 0.875rem;
        }
 
        .empty-state .empty-icon {
            font-size: 2.4rem;
            margin-bottom: 10px;
            opacity: 0.35;
            display: block;
        }
 
        .empty-state a { color: #4B49AC; font-weight: 600; }
 
        /* ── Select2 override ───────────────────────────── */
        .select2-container--default .select2-selection--single {
            height: 36px;
            display: flex;
            align-items: center;
            border: 1px solid #dddde8;
            border-radius: 8px;
            background: #fafafa;
        }
 
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            padding-left: 10px;
            font-size: 0.835rem;
            line-height: 34px;
            color: #3a3a4a;
        }
 
        .select2-container--default .select2-selection--single .select2-selection__arrow { height: 34px; }
        .select2-container--default.select2-container--focus .select2-selection--single,
        .select2-container--default.select2-container--open  .select2-selection--single {
            border-color: #4B49AC;
            box-shadow: 0 0 0 3px rgba(75,73,172,0.1);
        }
        .select2-dropdown { font-size: 0.835rem; border-radius: 8px; }
    </style>
</head>
 
<body>
<div class="container-scroller kra-page">
    <?php include('../partials/_navbar.php'); ?>
 
    <div class="container-fluid page-body-wrapper">
        <?php include('../partials/_sidebar.php'); ?>
 
        <div class="main-panel">
            <div class="content-wrapper">
 
                <?php if (isset($_SESSION['msg'])): ?>
                <script>
                    document.addEventListener("DOMContentLoaded", function () {
                        Swal.fire({
                            icon: '<?= $_SESSION['status'] ? 'success' : 'error' ?>',
                            title: '<?= $_SESSION['status'] ? 'Success' : 'Error' ?>',
                            text: '<?= addslashes($_SESSION['msg']) ?>',
                            confirmButtonColor: '#4B49AC'
                        });
                    });
                </script>
                <?php unset($_SESSION['msg']); unset($_SESSION['status']); ?>
                <?php endif; ?>
 
                <div class="container mt-4">
 
                    <!-- ── Page banner ── -->
                    <div class="kra-page-banner">
                        <div class="banner-left">
                            <h3 class="banner-title">Intern KRA Records</h3>
                            <p class="banner-sub">
                                Weekly performance ratings
                                <?php if ($isResourceAllocator): ?>
                                    &nbsp;<span class="ra-badge">&#9670; Resource Allocator View</span>
                                <?php endif; ?>
                            </p>
                        </div>
                        <div class="banner-actions">
                            <a href="kraDashboard.php" class="btn btn-banner-ghost" style="background:rgba(255,255,255,0.25); color:#fff; font-weight:600;"><i class="fas fa-brain mr-1"></i> Analytics Dashboard</a>
                            <?php if (!$isResourceAllocator): ?>
                                <a href="intern_kra_form.php" class="btn btn-banner-ghost">+ Add KRA</a>
                            <?php endif; ?>
                            <a href="intern_kra_list.php?export=1&filter_intern=<?= urlencode($filterIntern) ?>&filter_week=<?= urlencode($filterWeek) ?>&filter_year=<?= urlencode($filterYear) ?>&filter_lead=<?= urlencode($filterLead) ?>"
                               class="btn btn-banner-export">&#8595; Export to Excel</a>
                        </div>
                    </div>
 
                    <!-- ── Stat chips ── -->
                    <div class="stat-chips">
                        <div class="stat-chip">
                            <span class="chip-dot" style="background:#4B49AC;"></span>
                            <span>Total Records &nbsp;<strong><?= $totalRows ?></strong></span>
                        </div>
                        <?php if (!empty($filterIntern) || !empty($filterWeek) || !empty($filterYear) || !empty($filterLead)): ?>
                        <div class="stat-chip">
                            <span class="chip-dot" style="background:#f59e0b;"></span>
                            <span>Filters active &nbsp;<strong><?= array_sum([!empty($filterIntern), !empty($filterWeek), !empty($filterYear), !empty($filterLead)]) ?></strong></span>
                        </div>
                        <?php endif; ?>
                    </div>
 
                    <!-- ── Filter strip ── -->
                    <div class="filter-strip">
                        <div class="filter-strip-title">&#9783; Filter Records</div>
                        <form method="GET" action="intern_kra_list.php">
                            <div class="row align-items-end">
                                <div class="col-md-3 mb-2">
                                    <label>Intern Name</label>
                                    <input type="text" name="filter_intern" class="form-control form-control-sm"
                                           placeholder="Search intern..." value="<?= htmlspecialchars($filterIntern, ENT_QUOTES, 'UTF-8') ?>">
                                </div>
 
                                <?php if ($isResourceAllocator): ?>
                                <div class="col-md-3 mb-2">
                                    <label>Lead Name</label>
                                    <input type="text" name="filter_lead" class="form-control form-control-sm"
                                           placeholder="Search lead..." value="<?= htmlspecialchars($filterLead, ENT_QUOTES, 'UTF-8') ?>">
                                </div>
                                <?php endif; ?>
 
                                <div class="col-md-2 mb-2">
    <label>Week</label>
    <select name="filter_week" class="form-control form-control-sm">
        <option value="">All Weeks</option>
        <?php
        $weekOptQ = mysqli_query($con, "SELECT DISTINCT weekNumber, weekYear FROM internKraAssignments WHERE status = 'active' ORDER BY weekYear DESC, weekNumber ASC");
        while ($wo = mysqli_fetch_assoc($weekOptQ)):
            $woDto = new DateTime();
            $woDto->setISODate((int)$wo['weekYear'], (int)$wo['weekNumber']);
            $woWeekOfMonth = (int)ceil((int)$woDto->format('j') / 7);
            $woLabel = $woDto->format('M') . '-' . $woWeekOfMonth . ' (' . $wo['weekYear'] . ')';
        ?>
            <option value="<?= $wo['weekNumber'] ?>"
                data-year="<?= $wo['weekYear'] ?>"
                <?= ($filterWeek == $wo['weekNumber'] && $filterYear == $wo['weekYear']) ? 'selected' : '' ?>>
                <?= $woLabel ?>
            </option>
        <?php endwhile; ?>
    </select>
</div>
 <div class="col-md-2 mb-2">
    <label>Year</label>
    <select name="filter_year" class="form-control form-control-sm">
        <option value="">All Years</option>
        <?php
        if ($yearQuery && mysqli_num_rows($yearQuery) > 0):
            while ($yr = mysqli_fetch_assoc($yearQuery)): ?>
                <option value="<?= htmlspecialchars($yr['weekYear'], ENT_QUOTES, 'UTF-8') ?>"
                    <?= ($filterYear == $yr['weekYear']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($yr['weekYear'], ENT_QUOTES, 'UTF-8') ?>
                </option>
            <?php endwhile;
        endif; ?>
    </select>
</div>
 
                               <input type="hidden" name="filter_year" id="filterYearHidden" value="<?= htmlspecialchars($filterYear, ENT_QUOTES, 'UTF-8') ?>">
<div class="col-md-2 mb-2">
    <button type="submit" class="btn btn-primary btn-sm btn-block">Apply Filter</button>
</div>
                                <div class="col-md-1 mb-2">
                                    <a href="intern_kra_list.php" class="btn btn-light btn-sm btn-block">Clear</a>
                                </div>
                            </div>
                        </form>
                    </div>
 
                    <!-- ── Table card ── -->
                    <div class="kra-card">
                        <div class="table-responsive">
                            <table class="table kra-table" id="kraTable">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Intern Name</th>
                                        <?php if ($isResourceAllocator): ?>
                                            <th>Lead Name</th>
                                            <th>Colleague</th>
                                        <?php endif; ?>
                                        <th>Week</th>
                                        <th>Comm.</th>
                                        <th>Conf.</th>
                                        <th>Clarity</th>
                                        <th>Teamwork</th>
                                        <th>Prob. Solving</th>
                                        <th>Learning</th>
                                        <th>Technical</th>
                                        <th>GitHub</th>
                                        <th>AMS Processes</th>
                                        <th>Avg %</th>
                                        <th>Submitted</th>
                                        <?php if ($isResourceAllocator): ?>
                                            <th>Action</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php
                                $sl = 1;
                                if ($kraQuery && mysqli_num_rows($kraQuery) > 0):
                                    while ($kra = mysqli_fetch_assoc($kraQuery)):
                                        $avg      = (float)$kra['averagePercentage'];
                                        $avgClass = $avg >= 75 ? 'avg-high' : ($avg >= 50 ? 'avg-mid' : 'avg-low');
 
                                        $colleagueName = 'N/A';
                                        $leadDesig     = '';
                                        if ($isResourceAllocator) {
                                            $colQ = mysqli_query($con,
                                                "SELECT colleague, designation FROM signup WHERE userId = '" .
                                                mysqli_real_escape_string($con, $kra['submittedBy']) . "' LIMIT 1"
                                            );
                                            if ($colQ && mysqli_num_rows($colQ) > 0) {
                                                $colRow        = mysqli_fetch_assoc($colQ);
                                                $colleagueName = $colRow['colleague'] ?? 'N/A';
                                                $leadDesig     = $colRow['designation'] ?? '';
                                            }
                                        }
 
                                        $weekLabel = '';
                                        if (!empty($kra['weekNumber']) && !empty($kra['weekYear'])) {
                                            $wDto = new DateTime();
                                            $wDto->setISODate((int)$kra['weekYear'], (int)$kra['weekNumber']);
                                            $wWeekOfMonth = (int)ceil((int)$wDto->format('j') / 7);
                                            $weekLabel    = $wDto->format('M') . '-' . $wWeekOfMonth;
                                        }
                                ?>
                                    <tr>
                                        <td><span class="sl-num"><?= $sl++ ?></span></td>
                                        <td>
                                            <span class="intern-name"><?= htmlspecialchars($kra['internName'], ENT_QUOTES, 'UTF-8') ?></span>
                                            <?php if (!empty($kra['batch'])): ?>
                                                <br><small class="text-muted" style="font-size:0.72rem;"><?= htmlspecialchars($kra['batch'], ENT_QUOTES, 'UTF-8') ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <?php if ($isResourceAllocator): ?>
                                        <td>
                                            <?= htmlspecialchars($kra['submittedByName'], ENT_QUOTES, 'UTF-8') ?>
                                            <?php if (!empty($leadDesig)): ?>
                                                <br><small class="text-muted" style="font-size:0.72rem;"><?= htmlspecialchars($designationLabels[$leadDesig] ?? $leadDesig, ENT_QUOTES, 'UTF-8') ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($colleagueName, ENT_QUOTES, 'UTF-8') ?></td>
                                        <?php endif; ?>
                                        <td><span class="week-cell"><?= htmlspecialchars($weekLabel, ENT_QUOTES, 'UTF-8') ?></span></td>
                                        <td><?= scoreBadge($kra['communication']) ?></td>
                                        <td><?= scoreBadge($kra['confidence']) ?></td>
                                        <td><?= scoreBadge($kra['clarity']) ?></td>
                                        <td><?= scoreBadge($kra['teamwork']) ?></td>
                                        <td><?= scoreBadge($kra['problemSolving']) ?></td>
                                        <td><?= scoreBadge($kra['learningAttitude']) ?></td>
                                        <td><?= scoreBadge($kra['technicalKnowledge']) ?></td>
                                        <td><?= scoreBadge($kra['githubActivity'] ?? 0) ?></td>
                                        <td><?= scoreBadge($kra['amsProcessAdherence'] ?? 0) ?></td>
                                        <td>
                                            <span class="avg-pill <?= $avgClass ?>">
                                                <?= number_format($avg, 1) ?>%
                                            </span>
                                        </td>
                                        <td class="date-cell" data-order="<?= strtotime($kra['submittedAt']) ?>">
                                            <?= date('d M Y', strtotime($kra['submittedAt'])) ?>
                                        </td>
                                        <?php if ($isResourceAllocator): ?>
                                        <td>
                                            <a href="intern_kra_edit.php?kra_id=<?= (int)$kra['kraId'] ?>"
                                               class="btn btn-warning btn-sm">Edit</a>
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php
                                    endwhile;
                                endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
 
                </div>
            </div>
        </div>
    </div>
</div>
 
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="../vendors/select2/select2.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
<script>
$(document).ready(function(){
    var table = $('#kraTable').DataTable({
        paging:     true,
        searching:  false,
        ordering:   true,
        order:      [[ <?= $isResourceAllocator ? 13 : 11 ?>, 'desc' ]],
        info:       true,
        pageLength: 10,
        lengthMenu: [[10, 15, 25, 50, 100, -1], [10, 15, 25, 50, 100, 'All']],
        pagingType: 'simple_numbers',
        language: {
            emptyTable:  '<div class="empty-state"><span class="empty-icon">📋</span>No KRA records found.<?php if (!$isResourceAllocator): ?><br><a href="intern_kra_form.php">Add the first one now →</a><?php endif; ?></div>',
            lengthMenu:  "Show _MENU_ entries",
            info:        "Showing _START_–_END_ of _TOTAL_ records",
            infoEmpty:   "No records to show",
            paginate: {
                first:    "«",
                last:     "»",
                next:     "›",
                previous: "‹"
            }
        },
       dom: '<"dt-toolbar"l>t<"dt-footer"ip>',
       columnDefs: [
           <?php if ($isResourceAllocator): ?>
           { targets: 14, orderable: false }
           <?php endif; ?>
       ]
    });
});
</script>
</body>
</html>