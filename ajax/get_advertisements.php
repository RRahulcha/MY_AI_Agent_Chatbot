<?php
// ajax/get_advertisements.php
// Returns advertisements for DataTable, optionally filtered by display_location
ob_start();
include '../include/db_config.php';
ob_end_clean();

header('Content-Type: application/json');

if (empty($_SESSION['userId'])) { echo json_encode([]); exit; }

$location = $_GET['location'] ?? '';

if ($location !== '') {
    $stmt = $conn->prepare("
        SELECT id, advertisement_group_id, title, description, media_type, media_path,
               display_location, status, created_at, start_date, end_date
        FROM advertisements
        WHERE display_location = ?
        ORDER BY created_at DESC
    ");
    $stmt->bind_param('s', $location);
} else {
    $stmt = $conn->prepare("
        SELECT id, advertisement_group_id, title, description, media_type, media_path,
               display_location, status, created_at, start_date, end_date
        FROM advertisements
        ORDER BY created_at DESC
    ");
}
$stmt->execute();
$res = $stmt->get_result();

$groups = [];
while ($r = $res->fetch_assoc()) {
    $gid = $r['advertisement_group_id'] ?: 'single_' . $r['id'];
    if (!isset($groups[$gid])) {
        $groups[$gid] = [
            'group_id'   => $gid,
            'items'      => [],
            'created_at' => $r['created_at'],
            'status'     => $r['status'],
            'start_date' => $r['start_date'],
            'end_date'   => $r['end_date'],
            'location'   => $r['display_location'],
        ];
    }
    $groups[$gid]['items'][] = $r;
}
$stmt->close();

$rows = [];
$i = 1;
foreach ($groups as $gid => $grp) {
    $items = $grp['items'];
    $firstItem = $items[0];
    $count = count($items);

    $preview = '';
    if ($firstItem['media_path']) {
        if ($firstItem['media_type'] === 'image') {
            $preview = '<img src="../pages/' . htmlspecialchars($firstItem['media_path']) . '" style="height:48px;border-radius:6px;object-fit:cover;">';
        } else {
            $preview = '<video src="../pages/' . htmlspecialchars($firstItem['media_path']) . '" style="height:48px;border-radius:6px;" muted></video>';
        }
    } else {
        $preview = '<span class="badge bg-secondary">No Media</span>';
    }

    $titlesInfo = implode('; ', array_filter(array_map(fn($it) => htmlspecialchars($it['title']), $items)));
    if (empty($titlesInfo)) $titlesInfo = '<em class="text-muted">Untitled</em>';

    $status = (int)$firstItem['status'];
    $statusBadge = $status
        ? '<span class="badge bg-success">Active</span>'
        : '<span class="badge bg-secondary">Inactive</span>';
    $toggleBtn = $status
        ? '<button class="btn btn-sm btn-warning toggle-status-ad mr-1" data-id="' . $firstItem['id'] . '" data-new-status="0" title="Pause">⏸</button>'
        : '<button class="btn btn-sm btn-success toggle-status-ad mr-1" data-id="' . $firstItem['id'] . '" data-new-status="1" title="Activate">▶</button>';

    $date = $grp['created_at'] ? date('d M Y', strtotime($grp['created_at'])) : '—';
    $shortGid = strlen($gid) > 18 ? '...' . substr($gid, -12) : $gid;

    $rows[] = [
        'sr_no'        => $i++,
        'advertisement_group_id' => '<code style="font-size:11px">' . htmlspecialchars($shortGid) . '</code>',
        'preview'      => $preview,
        'media_count'  => $count,
        'titles_info'  => $titlesInfo,
        'status_badge' => $statusBadge,
        'created_date' => $date,
        'actions'      => '
            <div class="d-flex gap-1">
                <button class="btn btn-sm btn-primary edit-ad mr-1" data-id="' . htmlspecialchars($gid) . '"><i class="fa-solid fa-pen"></i> Edit</button>
                ' . $toggleBtn . '
                <button class="btn btn-sm btn-danger delete-ad" data-id="' . $firstItem['id'] . '"><i class="fa-solid fa-trash"></i></button>
            </div>',
        'delete_btn'   => '<button class="btn btn-sm btn-danger delete-ad" data-id="' . $firstItem['id'] . '"><i class="fa-solid fa-trash"></i> Delete</button>',
    ];
}

echo json_encode($rows);
