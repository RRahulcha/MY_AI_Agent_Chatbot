<?php
// ajax/get_placed_students.php
ob_start();
include '../include/db_config.php';
ob_end_clean();

header('Content-Type: application/json');

if (empty($_SESSION['userId'])) { echo json_encode([]); exit; }

$branch   = trim($_GET['branch'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo   = trim($_GET['date_to'] ?? '');

$where = ["1=1"];
$params = [];
$types  = "";

if ($branch !== '') {
    $where[]  = "branch = ?";
    $params[] = $branch;
    $types   .= "s";
}
if ($dateFrom !== '') {
    $where[]  = "placement_date >= ?";
    $params[] = $dateFrom;
    $types   .= "s";
}
if ($dateTo !== '') {
    $where[]  = "placement_date <= ?";
    $params[] = $dateTo;
    $types   .= "s";
}

$sql = "
    SELECT id, student_user_id, student_name, branch, company_name, role_offered, package_ctc, photo_path, placement_date, status, remarks
    FROM placements
    WHERE " . implode(" AND ", $where) . "
    ORDER BY id DESC
";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$res = $stmt->get_result();

$rows = [];
$i = 1;
while ($r = $res->fetch_assoc()) {
    $photoHtml = $r['photo_path']
        ? '<img src="../pages/' . htmlspecialchars($r['photo_path']) . '" style="width:40px;height:40px;border-radius:50%;object-fit:cover;border:2px solid #36e2c9;">'
        : '<div style="width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,#4f8cff,#36e2c9);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:16px;">' . strtoupper(mb_substr($r['student_name'] ?: '?', 0, 1)) . '</div>';

    $pkgHtml = $r['package_ctc']
        ? '<span class="badge" style="background:#e0f2fe;color:#0284c7;font-weight:700;">' . htmlspecialchars($r['package_ctc']) . '</span>'
        : '—';

    $stBadge = ($r['status'] === 'active')
        ? '<span class="badge bg-success">Active</span>'
        : '<span class="badge bg-secondary">Inactive</span>';

    $pDate = $r['placement_date'] ? date('d M Y', strtotime($r['placement_date'])) : '—';

    $rows[] = [
        'sr_no'          => $i++,
        'photo'          => $photoHtml,
        'student_name'   => htmlspecialchars($r['student_name']),
        'branch'         => htmlspecialchars($r['branch'] ?: '—'),
        'company_name'   => '<strong>' . htmlspecialchars($r['company_name']) . '</strong>',
        'role_offered'   => htmlspecialchars($r['role_offered'] ?: '—'),
        'package_ctc'    => $pkgHtml,
        'placement_date' => $pDate,
        'status'         => $stBadge,
        'actions'        => '
            <div class="d-flex gap-1">
                <button class="btn btn-sm btn-primary edit-student mr-1" data-id="' . $r['id'] . '"><i class="fa-solid fa-pen"></i> Edit</button>
                <button class="btn btn-sm btn-danger delete-student" data-id="' . $r['id'] . '"><i class="fa-solid fa-trash"></i></button>
            </div>',
    ];
}
$stmt->close();

echo json_encode($rows);
