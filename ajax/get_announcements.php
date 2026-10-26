<?php
// ajax/get_announcements.php
// Returns announcements list for HR DataTable
ob_start();
include '../include/db_config.php';
ob_end_clean();

header('Content-Type: application/json');

if (empty($_SESSION['userId'])) {
    echo json_encode([]);
    exit;
}

$sql = "
    SELECT
        am.id,
        am.title,
        am.priority,
        am.show_on_board,
        am.expiry_date,
        am.created_date,
        am.status,
        COUNT(mp.id) AS total_count,
        COALESCE(SUM(mp.is_read), 0) AS ack_count,
        s.fullName AS creator_name
    FROM announcement_master am
    LEFT JOIN announcement_mapping mp ON mp.announcement_id = am.id
    LEFT JOIN signup s ON CONVERT(s.userId USING utf8mb4) = CONVERT(am.created_by USING utf8mb4)
    WHERE am.status = 1
    GROUP BY am.id, s.fullName
    ORDER BY am.created_date DESC
";

$res = $conn->query($sql);
$rows = [];
$i = 1;
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $total = (int)$r['total_count'];
        $ack   = (int)$r['ack_count'];

        $p = strtolower($r['priority'] ?? 'normal');
        $priorityBadge = match($p) {
            'urgent'    => '<span class="badge bg-danger text-white px-2 py-1">⚡ Urgent</span>',
            'important' => '<span class="badge bg-warning text-dark px-2 py-1">⚠ Important</span>',
            default     => '<span class="badge bg-info text-white px-2 py-1">● Normal</span>',
        };

        $boardBadge = !empty($r['show_on_board']) ? '<span class="badge bg-success px-2 py-1">On Board</span>' : '<span class="badge bg-secondary px-2 py-1">Internal</span>';
        $expiry = !empty($r['expiry_date']) ? date('d M Y', strtotime($r['expiry_date'])) : '—';
        $date   = !empty($r['created_date']) ? date('d M Y, h:i A', strtotime($r['created_date'])) : '—';

        $color = ($ack === $total && $total > 0) ? '#28a745' : '#eab308';
        $ackBar = "<span style='font-weight:700;color:{$color};'>{$ack}/{$total}</span> acknowledged";

        $rows[] = [
            'sr_no'       => $i++,
            'title'       => htmlspecialchars($r['title'] ?? ''),
            'priority'    => $priorityBadge,
            'board'       => $boardBadge,
            'expiry'      => $expiry,
            'emp_count'   => $total,
            'ack_status'  => $ackBar,
            'created_date'=> $date,
            'view_btn'    => '<button class="btn btn-sm btn-info text-white view-reads" data-id="' . $r['id'] . '"><i class="fa-solid fa-eye mr-1"></i> Reads</button>',
            'delete_btn'  => '<button class="btn btn-sm btn-danger delete-ann" data-id="' . $r['id'] . '"><i class="fa-solid fa-trash"></i></button>',
        ];
    }
}

echo json_encode($rows);
