<?php
session_start();

if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    http_response_code(403); exit;
}

include('../include/db_config.php');

$query        = mysqli_query($con, "SELECT designation, userRole FROM signup WHERE userId = '" . mysqli_real_escape_string($con, $_SESSION['userId']) . "'");
$user         = mysqli_fetch_assoc($query);
$allowedRolesClean = ['seniorintern', 'teamlead', 'resourceallocator', 'resourcehead', 'resourcemanager', 'admin', 'superadmin', 'hr'];

$desigClean = strtolower(str_replace([' ', '_'], '', $user['designation'] ?? ''));
$roleClean  = strtolower(str_replace([' ', '_'], '', $user['userRole'] ?? ''));

if (!$user || (!in_array($desigClean, $allowedRolesClean) && !in_array($roleClean, $allowedRolesClean))) {
    http_response_code(403); exit;
}

$isRA          = in_array($desigClean, ['resourceallocator', 'resourcehead', 'resourcemanager']) || in_array($roleClean, ['resourceallocator', 'resourcehead', 'resourcemanager']);
$currentUserId = mysqli_real_escape_string($con, $_SESSION['userId']);

$filterIntern = mysqli_real_escape_string($con, trim($_GET['filter_intern'] ?? ''));
$filterWeek   = mysqli_real_escape_string($con, trim($_GET['filter_week']   ?? ''));
$filterYear   = mysqli_real_escape_string($con, trim($_GET['filter_year']   ?? ''));
$filterLead   = mysqli_real_escape_string($con, trim($_GET['filter_lead']   ?? ''));

$where = "k.status = 'active'";
if (!$isRA)                       $where .= " AND k.submittedBy = '$currentUserId'";
if (!empty($filterIntern))        $where .= " AND k.internName LIKE '%$filterIntern%'";
if (!empty($filterWeek))          $where .= " AND k.weekNumber = '$filterWeek'";
if (!empty($filterYear))          $where .= " AND k.weekYear = '$filterYear'";
if ($isRA && !empty($filterLead)) $where .= " AND k.submittedByName LIKE '%$filterLead%'";

$kraQuery = mysqli_query($con, "SELECT k.* FROM internKraAssignments k WHERE $where ORDER BY k.submittedAt DESC");

// ── Collect rows ───────────────────────────────────────────────────
$rows = [];
while ($kra = mysqli_fetch_assoc($kraQuery)) {
    if ($isRA) {
        $colQ = mysqli_query($con, "SELECT colleague FROM signup WHERE userId = '" . mysqli_real_escape_string($con, $kra['submittedBy']) . "' LIMIT 1");
        $kra['_colleague'] = ($colQ && mysqli_num_rows($colQ) > 0) ? (mysqli_fetch_assoc($colQ)['colleague'] ?? 'N/A') : 'N/A';
    }
    $rows[] = $kra;
}

// ── Helpers ────────────────────────────────────────────────────────
function x($v) { return htmlspecialchars((string)$v, ENT_XML1); }

function scoreColor($n) {
    if ($n >= 8) return ['bg' => 'C6EFCE', 'fg' => '276221'];
    if ($n >= 5) return ['bg' => 'FFEB9C', 'fg' => '9C6500'];
    return              ['bg' => 'FFC7CE', 'fg' => '9C0006'];
}

function avgColor($avg) { // avg is out of 100
    if ($avg >= 75) return ['bg' => 'C6EFCE', 'fg' => '276221'];
    if ($avg >= 50) return ['bg' => 'FFEB9C', 'fg' => '9C6500'];
    return                 ['bg' => 'FFC7CE', 'fg' => '9C0006'];
}

// ── Build headers ──────────────────────────────────────────────────
$headers = [
    ['label' => '#',               'bg' => '1F3864', 'width' => 5],
    ['label' => 'Intern Name',     'bg' => '1F3864', 'width' => 30],
];
if ($isRA) {
    $headers[] = ['label' => 'Lead Name',  'bg' => '1F3864', 'width' => 22];
    $headers[] = ['label' => 'Colleague',  'bg' => '1F3864', 'width' => 18];
}
$headers = array_merge($headers, [
    ['label' => 'Week',            'bg' => '1F3864', 'width' => 12],
    ['label' => 'Communication',   'bg' => '2E75B6', 'width' => 16],
    ['label' => 'Confidence',      'bg' => '2E75B6', 'width' => 13],
    ['label' => 'Clarity',         'bg' => '2E75B6', 'width' => 11],
    ['label' => 'Teamwork',        'bg' => '2E75B6', 'width' => 11],
    ['label' => 'Problem Solving', 'bg' => '2E75B6', 'width' => 16],
    ['label' => 'Learning',        'bg' => '2E75B6', 'width' => 11],
    ['label' => 'Technical',       'bg' => '2E75B6', 'width' => 11],
    ['label' => 'GitHub',          'bg' => '2E75B6', 'width' => 11],
    ['label' => 'AMS Processes',   'bg' => '2E75B6', 'width' => 14],
    ['label' => 'Avg %',           'bg' => 'ED7D31', 'width' => 10],
    ['label' => 'Submitted',       'bg' => '1F3864', 'width' => 15],
]);

$colCount = count($headers);

// ── XML: shared strings ────────────────────────────────────────────
// We'll use inline strings (t="inlineStr") — no shared string table needed

// ── XML: styles ───────────────────────────────────────────────────
// We'll define all needed styles upfront
// Style index map:
// 0 = default
// 1 = header navy
// 2 = header blue
// 3 = header orange
// 4 = data normal (left)
// 5 = data center
// 6 = data name (bold, left)
// 7 = score green
// 8 = score yellow
// 9 = score red
// 10 = avg green
// 11 = avg yellow
// 12 = avg red (percent format)
// 13 = data center even row
// 14 = data normal even row

$stylesXml = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <numFmts count="1">
    <numFmt numFmtId="164" formatCode="0.0%"/>
  </numFmts>
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
      <left style="medium"><color rgb="FF000000"/></left>
      <right style="medium"><color rgb="FF000000"/></right>
      <top style="medium"><color rgb="FF000000"/></top>
      <bottom style="medium"><color rgb="FF000000"/></bottom>
    </border>
    <border>
      <left style="thin"><color rgb="FFB0B0C8"/></left>
      <right style="thin"><color rgb="FFB0B0C8"/></right>
      <top style="thin"><color rgb="FFB0B0C8"/></top>
      <bottom style="thin"><color rgb="FFB0B0C8"/></bottom>
    </border>
  </borders>
  <cellStyleXfs count="1">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>
  </cellStyleXfs>
  <cellXfs count="15">
    <!-- 0: default -->
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <!-- 1: header navy -->
    <xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">
      <alignment horizontal="center" vertical="center" wrapText="1"/>
    </xf>
    <!-- 2: header blue -->
    <xf numFmtId="0" fontId="1" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">
      <alignment horizontal="center" vertical="center" wrapText="1"/>
    </xf>
    <!-- 3: header orange -->
    <xf numFmtId="0" fontId="1" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">
      <alignment horizontal="center" vertical="center" wrapText="1"/>
    </xf>
    <!-- 4: data normal left, white -->
    <xf numFmtId="0" fontId="5" fillId="5" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">
      <alignment horizontal="left" vertical="center"/>
    </xf>
    <!-- 5: data center, white -->
    <xf numFmtId="0" fontId="5" fillId="5" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">
      <alignment horizontal="center" vertical="center"/>
    </xf>
    <!-- 6: data name bold, white -->
    <xf numFmtId="0" fontId="6" fillId="5" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">
      <alignment horizontal="left" vertical="center" wrapText="1"/>
    </xf>
    <!-- 7: score green -->
    <xf numFmtId="0" fontId="2" fillId="7" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">
      <alignment horizontal="center" vertical="center"/>
    </xf>
    <!-- 8: score yellow -->
    <xf numFmtId="0" fontId="3" fillId="8" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">
      <alignment horizontal="center" vertical="center"/>
    </xf>
    <!-- 9: score red -->
    <xf numFmtId="0" fontId="4" fillId="9" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">
      <alignment horizontal="center" vertical="center"/>
    </xf>
    <!-- 10: avg % green -->
    <xf numFmtId="164" fontId="2" fillId="7" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1" applyNumberFormat="1">
      <alignment horizontal="center" vertical="center"/>
    </xf>
    <!-- 11: avg % yellow -->
    <xf numFmtId="164" fontId="3" fillId="8" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1" applyNumberFormat="1">
      <alignment horizontal="center" vertical="center"/>
    </xf>
    <!-- 12: avg % red -->
    <xf numFmtId="164" fontId="4" fillId="9" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1" applyNumberFormat="1">
      <alignment horizontal="center" vertical="center"/>
    </xf>
    <!-- 13: data center, even row -->
    <xf numFmtId="0" fontId="5" fillId="6" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">
      <alignment horizontal="center" vertical="center"/>
    </xf>
    <!-- 14: data left, even row -->
    <xf numFmtId="0" fontId="7" fillId="6" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">
      <alignment horizontal="left" vertical="center" wrapText="1"/>
    </xf>
  </cellXfs>
</styleSheet>
XML;

// ── XML: worksheet ─────────────────────────────────────────────────
function colLetter($n) {
    $l = '';
    while ($n > 0) {
        $n--;
        $l = chr(65 + ($n % 26)) . $l;
        $n = (int)($n / 26);
    }
    return $l;
}

function styleIdx($type, $rowEven = false) {
    switch ($type) {
        case 'hdr_navy':   return 1;
        case 'hdr_blue':   return 2;
        case 'hdr_orange': return 3;
        case 'center':     return $rowEven ? 13 : 5;
        case 'left':       return $rowEven ? 14 : 4;
        case 'name':       return 6;
    }
    return 0;
}

function scoreStyleIdx($n) {
    if ($n >= 8) return 7;
    if ($n >= 5) return 8;
    return 9;
}

function avgStyleIdx($avg) {
    if ($avg >= 75) return 10;
    if ($avg >= 50) return 11;
    return 12;
}

function strCell($col, $row, $val, $styleIdx) {
    $ref = colLetter($col) . $row;
    $v   = x($val);
    return "<c r=\"$ref\" s=\"$styleIdx\" t=\"inlineStr\"><is><t>$v</t></is></c>";
}

function numCell($col, $row, $val, $styleIdx) {
    $ref = colLetter($col) . $row;
    return "<c r=\"$ref\" s=\"$styleIdx\"><v>$val</v></c>";
}

// Build sheet XML
$sheetRows = '';

// Header row (row 1)
$sheetRows .= '<row r="1" ht="30" customHeight="1">';
foreach ($headers as $ci => $h) {
    $col = $ci + 1;
    $si  = $h['bg'] === '1F3864' ? 1 : ($h['bg'] === '2E75B6' ? 2 : 3);
    $sheetRows .= strCell($col, 1, $h['label'], $si);
}
$sheetRows .= '</row>';

// Data rows
$ri = 2;
foreach ($rows as $kra) {
    $even = ($ri % 2 === 0);
    $ht   = !empty($kra['batch']) ? 28 : 18;
    $sheetRows .= "<row r=\"$ri\" ht=\"$ht\" customHeight=\"1\">";

    $col = 1;

    // #
    $sheetRows .= numCell($col, $ri, $ri - 1, styleIdx('center', $even));
    $col++;

    // Intern Name
    $name = $kra['internName'] . (!empty($kra['batch']) ? ' (' . $kra['batch'] . ')' : '');
    $sheetRows .= strCell($col, $ri, $name, 6);
    $col++;

    if ($isRA) {
        $sheetRows .= strCell($col, $ri, $kra['submittedByName'] ?? '', styleIdx('left', $even));
        $col++;
        $sheetRows .= strCell($col, $ri, $kra['_colleague'], styleIdx('left', $even));
        $col++;
    }

    // Week
    $sheetRows .= strCell($col, $ri, 'W' . (int)$kra['weekNumber'] . ' · ' . (int)$kra['weekYear'], styleIdx('center', $even));
    $col++;

    // Scores
    foreach (['communication','confidence','clarity','teamwork','problemSolving','learningAttitude','technicalKnowledge','githubActivity','amsProcessAdherence'] as $field) {
        $n = (int)($kra[$field] ?? 0);
        $sheetRows .= numCell($col, $ri, $n, scoreStyleIdx($n));
        $col++;
    }

    // Avg %
    $avg = (float)$kra['averagePercentage'];
    $sheetRows .= numCell($col, $ri, round($avg / 100, 4), avgStyleIdx($avg));
    $col++;

    // Submitted
    $dateStr = !empty($kra['submittedAt']) ? date('d M Y', strtotime($kra['submittedAt'])) : '';
    $sheetRows .= strCell($col, $ri, $dateStr, styleIdx('center', $even));

    $sheetRows .= '</row>';
    $ri++;
}

// Column widths XML
$colsXml = '<cols>';
foreach ($headers as $ci => $h) {
    $col = $ci + 1;
    $colsXml .= "<col min=\"$col\" max=\"$col\" width=\"{$h['width']}\" customWidth=\"1\"/>";
}
$colsXml .= '</cols>';

$lastCell = colLetter($colCount) . max($ri - 1, 1);

$sheetXml = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <sheetViews>
    <sheetView workbookViewId="0">
      <pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>
    </sheetView>
  </sheetViews>
  $colsXml
  <sheetData>
    $sheetRows
  </sheetData>
</worksheet>
XML;

// ── Assemble XLSX (zip) ────────────────────────────────────────────
$workbookXml = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
          xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="Intern KRA" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>
XML;

$relsXml = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>
XML;

$contentTypesXml = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml"  ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml"            ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml"   ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/styles.xml"              ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>
XML;

$dotRelsXml = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>
XML;

// Write to temp zip
$tmpFile = tempnam(sys_get_temp_dir(), 'kra_') . '.xlsx';
$zip = new ZipArchive();
$zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('[Content_Types].xml',          $contentTypesXml);
$zip->addFromString('_rels/.rels',                  $dotRelsXml);
$zip->addFromString('xl/workbook.xml',              $workbookXml);
$zip->addFromString('xl/_rels/workbook.xml.rels',   $relsXml);
$zip->addFromString('xl/styles.xml',                $stylesXml);
$zip->addFromString('xl/worksheets/sheet1.xml',     $sheetXml);
$zip->close();

// ── Stream to browser ──────────────────────────────────────────────
while (ob_get_level()) ob_end_clean();

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="Intern_KRA_' . date('Ymd') . '.xlsx"');
header('Content-Length: ' . filesize($tmpFile));
header('Cache-Control: max-age=0');

readfile($tmpFile);
unlink($tmpFile);
exit;
?>