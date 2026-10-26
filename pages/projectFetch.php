<?php
require_once('../include/db_config.php');

$q = mysqli_query($con, "SELECT * FROM projectMaster ORDER BY modificationDate DESC");
$i = 1;

while ($r = mysqli_fetch_assoc($q)) {

    // Status badge
    $statusLabel = $r['status'] == 1
        ? '<span class="badge bg-success text-white px-3 py-1" style="border-radius:20px; font-weight:600; font-size:0.78rem;"><i class="fa-solid fa-circle-check me-1"></i> Active</span>'
        : '<span class="badge bg-secondary text-white px-3 py-1" style="border-radius:20px; font-weight:600; font-size:0.78rem;"><i class="fa-solid fa-circle-xmark me-1"></i> Inactive</span>';

    // Priority Badge styling
    $p = strtoupper($r['priority'] ?? 'P3');
    if ($p === 'P1') {
        $priorityBadge = '<span class="badge bg-danger text-white px-2 py-1" style="border-radius:6px; font-weight:700;"><i class="fa-solid fa-fire me-1"></i> P1 - Critical</span>';
    } elseif ($p === 'P2') {
        $priorityBadge = '<span class="badge bg-warning text-dark px-2 py-1" style="border-radius:6px; font-weight:700;"><i class="fa-solid fa-triangle-exclamation me-1"></i> P2 - High</span>';
    } elseif ($p === 'P3') {
        $priorityBadge = '<span class="badge bg-info text-white px-2 py-1" style="border-radius:6px; font-weight:700;"><i class="fa-solid fa-bars me-1"></i> P3 - Medium</span>';
    } else {
        $priorityBadge = '<span class="badge bg-secondary text-white px-2 py-1" style="border-radius:6px; font-weight:600;">P4 - Low</span>';
    }

    // Hosting badge & links
    $hostingType = $r['hostingType'] ?? '';
    $accessLink  = !empty($r['git_access_link']) ? $r['git_access_link'] : '';
    $repoLink    = !empty($r['git_repo_link']) ? $r['git_repo_link'] : '';

    $accessBtn = !empty($accessLink) ? '<a href="' . htmlspecialchars($accessLink) . '" target="_blank" class="text-primary fw-bold ms-1" style="font-size:0.78rem;" title="Open Dev / Staging Server URL"><i class="fa-solid fa-globe me-1"></i>Open App</a>' : '';
    $repoBtn   = !empty($repoLink) ? '<a href="' . htmlspecialchars($repoLink) . '" target="_blank" class="text-dark ms-1" style="font-size:0.78rem;" title="Open Git Repository"><i class="fa-brands fa-github me-1"></i>Repo</a>' : '';

    if ($hostingType === 'Git') {
        $hostingBadge = '<div><span class="badge bg-dark text-white px-2 py-1" style="border-radius:6px;"><i class="fa-brands fa-github me-1"></i> Git</span> ' . $repoBtn . ' ' . $accessBtn . '</div>';
    } elseif ($hostingType === 'FTP') {
        $ftpLink = !empty($r['Link']) ? $r['Link'] : '';
        $ftpHost = !empty($r['Host']) ? htmlspecialchars($r['Host']) : '';
        $ftpUser = !empty($r['User']) ? htmlspecialchars($r['User']) : '';
        $ftpPass = !empty($r['Pass']) ? htmlspecialchars($r['Pass']) : '';

        $ftpDetails = '';
        if (!empty($ftpHost) || !empty($ftpUser)) {
            $ftpDetails = '<div class="mt-1" style="font-size:0.75rem; line-height:1.35;">';
            if (!empty($ftpHost)) $ftpDetails .= '<div><span class="text-muted">Host:</span> <strong class="text-dark">' . $ftpHost . '</strong></div>';
            if (!empty($ftpUser)) $ftpDetails .= '<div><span class="text-muted">User:</span> <code class="text-primary">' . $ftpUser . '</code></div>';
            if (!empty($ftpPass)) $ftpDetails .= '<div><span class="text-muted">Pass:</span> <code class="text-danger fw-bold">' . $ftpPass . '</code> <button type="button" class="btn btn-link text-secondary p-0 border-0 ms-1" style="font-size:0.75rem;" onclick="navigator.clipboard.writeText(\'' . addslashes($ftpPass) . '\')" title="Copy Password"><i class="fa-regular fa-copy"></i></button></div>';
            $ftpDetails .= '</div>';
        }

        $ftpBtn  = !empty($ftpLink) ? '<a href="' . htmlspecialchars($ftpLink) . '" target="_blank" class="text-primary fw-bold ms-1" style="font-size:0.78rem;" title="Open FTP Link"><i class="fa-solid fa-arrow-up-right-from-square me-1"></i> Panel</a>' : '';
        $hostingBadge = '<div><span class="badge bg-primary text-white px-2 py-1" style="border-radius:6px;"><i class="fa-solid fa-server me-1"></i> FTP</span> ' . $ftpBtn . $ftpDetails . '</div>';
    } else {
        $hostingBadge = (!empty($accessBtn) ? $accessBtn : '<span class="text-muted" style="font-size:0.8rem;">—</span>');
    }

    // Budget Hours & Hourly Rate
    $budgetHours = (!empty($r['budget_hours']) && floatval($r['budget_hours']) > 0)
        ? '<strong class="text-dark">' . number_format($r['budget_hours'], 1) . '</strong> <span class="text-muted" style="font-size:0.75rem;">hrs</span>'
        : '<span class="text-muted" style="font-style:italic;">—</span>';

    $hourlyRate = (!empty($r['hourly_rate']) && floatval($r['hourly_rate']) > 0)
        ? '<span class="text-success fw-bold">₹' . number_format($r['hourly_rate'], 2) . '</span><span class="text-muted" style="font-size:0.72rem;">/hr</span>'
        : '<span class="text-muted" style="font-style:italic;">—</span>';

    // Dates
    $modDate  = !empty($r['modificationDate']) && $r['modificationDate'] !== '0000-00-00 00:00:00' ? date('d M Y, h:i A', strtotime($r['modificationDate'])) : 'N/A';
    $deadline = !empty($r['deadLine']) && $r['deadLine'] !== '0000-00-00 00:00:00' ? date('d M Y, h:i A', strtotime($r['deadLine'])) : 'N/A';

    $actionButtons = '
    <div class="d-flex align-items-center gap-1">
        <button class="btn btn-primary btn-sm px-2 py-1"
            type="button"
            title="Edit Project"
            onclick="edit(\'' . $r['projectId'] . '\')">
            <i class="fa-solid fa-pen-to-square"></i> Edit
        </button>
        <button class="btn btn-outline-danger btn-sm px-2 py-1"
            type="button"
            title="Delete Project"
            onclick="deletefun(\'projectMaster_db.php\', \'' . $r['projectId'] . '\')">
            <i class="fa-solid fa-trash"></i>
        </button>
    </div>';

    echo "
    <tr>
        <td class='fw-bold text-secondary'>{$i}</td>
        <td>
            <div class='fw-bold text-dark' style='font-size:0.9rem;'>{$r['projectName']}</div>
            <small class='text-muted' style='font-size:0.72rem;'>Updated: {$modDate}</small>
        </td>
        <td><strong class='text-secondary'>{$r['client']}</strong></td>
        <td>{$hostingBadge}</td>
        <td>{$priorityBadge}</td>
        <td>{$budgetHours}</td>
        <td>{$hourlyRate}</td>
        <td><span class='badge bg-light text-dark border px-2 py-1' style='font-size:0.78rem;'><i class='fa-solid fa-calendar-day me-1 text-primary'></i> {$deadline}</span></td>
        <td>{$statusLabel}</td>
        <td>{$actionButtons}</td>
    </tr>";
    $i++;
}
?>
