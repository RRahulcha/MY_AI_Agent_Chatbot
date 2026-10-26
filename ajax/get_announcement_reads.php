<?php
// ajax/get_announcement_reads.php
// Returns employees and their read/acknowledged status for a specific announcement
ob_start();
include '../include/db_config.php';
ob_end_clean();

header('Content-Type: application/json');

if (empty($_SESSION['userId'])) {
    echo json_encode([]);
    exit;
}

$announcementId = (int)($_GET['id'] ?? 0);
if (!$announcementId) {
    echo json_encode([]);
    exit;
}

$sql = "
    SELECT
        s.fullName AS emp_name,
        COALESCE(s.designation, mp.designation, '—') AS designation,
        COALESCE(s.batch_number, mp.batch, '—') AS batch,
        mp.is_read,
        mp.read_date
    FROM announcement_mapping mp
    LEFT JOIN signup s ON CONVERT(s.userId USING utf8mb4) = CONVERT(mp.employee_id USING utf8mb4)
    WHERE mp.announcement_id = ?
    ORDER BY mp.is_read DESC, s.fullName ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $announcementId);
$stmt->execute();
$res = $stmt->get_result();

$rows = [];
while ($r = $res->fetch_assoc()) {
    $statusBadge = $r['is_read']
        ? '<span class="badge bg-success text-white">✓ Read / Acknowledged</span>'
        : '<span class="badge bg-warning text-dark">⏳ Pending</span>';

    $readDate = $r['read_date'] ? date('d M Y, h:i A', strtotime($r['read_date'])) : '—';

    $rows[] = [
        'emp_name'    => htmlspecialchars($r['emp_name'] ?? 'Unknown Employee'),
        'designation' => htmlspecialchars($r['designation'] ?? '—'),
        'batch'       => htmlspecialchars($r['batch'] ?? '—'),
        'read_status' => $statusBadge,
        'read_date'   => $readDate,
    ];
}
$stmt->close();

echo json_encode($rows);
