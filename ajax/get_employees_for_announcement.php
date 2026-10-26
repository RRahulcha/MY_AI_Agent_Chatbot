<?php
// ajax/get_employees_for_announcement.php
ob_start();
include '../include/db_config.php';
if (ob_get_length()) ob_clean();

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header('Content-Type: application/json');

if (empty($_SESSION['userId'])) {
    echo json_encode([]);
    exit;
}

$sql = "
    SELECT userId, fullName, designation, branch
    FROM signup
    WHERE fullName IS NOT NULL 
      AND TRIM(fullName) != ''
      AND (TRIM(LOWER(status)) = 'active' OR status = '1' OR status = 1)
    ORDER BY fullName ASC
";

$res = $conn->query($sql);
$data = [];

if ($res) {
    while ($r = $res->fetch_assoc()) {
        $data[] = [
            'userId'      => $r['userId'],
            'fullName'    => trim($r['fullName']),
            'designation' => trim($r['designation'] ?? '') ?: 'Intern/Employee',
            'branch'      => trim($r['branch'] ?? '')
        ];
    }
}

echo json_encode($data);
exit;
