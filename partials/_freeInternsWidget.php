<?php
/**
 * _freeInternsWidget.php
 * Reusable Free Interns availability widget for dashboards.
 * Include this file after db_config + availabilityEngine are loaded.
 *
 * Variables used:
 *   $con       — active MySQLi connection
 *   $userId    — logged-in user's ID
 *   $userRole  — logged-in user's role (lowercase)
 *
 * Widget renders a self-contained card with a date filter (default = today,
 * allows future dates) and a live table of available interns.
 */

// ── Date filter (default today, allow future) ──────────────────────────────
$_fi_date = (isset($_GET['fi_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['fi_date']))
    ? $_GET['fi_date']
    : date('Y-m-d');
$_fi_dateLabel = date('D, d M Y', strtotime($_fi_date));

// ── Fetch all active interns ───────────────────────────────────────────────
$_fi_interns = [];
$_fi_res = mysqli_query($con, "
    SELECT s.internId, s.fullName,
           COALESCE(i.role, s.designation, 'Intern') AS role,
           COALESCE(i.batch, s.batch_number, '') AS batch,
           COALESCE(s.office, '') AS office
    FROM   signup s
    LEFT   JOIN interns i ON s.internId COLLATE utf8mb4_unicode_ci = i.internId COLLATE utf8mb4_unicode_ci
    WHERE  LOWER(s.status) = 'active'
      AND  s.internId IS NOT NULL
      AND  TRIM(s.internId) != ''
      AND  s.userRole NOT IN ('admin', 'hr', 'resourceAllocator', 'teamLead', 'team_lead')
    ORDER  BY s.fullName ASC
");
if ($_fi_res) {
    while ($_fi_row = mysqli_fetch_assoc($_fi_res)) {
        $_fi_iid  = $_fi_row['internId'];
        $_fi_snap = calculateAvailabilityForDate($con, $_fi_iid, $_fi_date);

        $freeMins  = $_fi_snap['available'] ? (int)($_fi_snap['freeMinutes']  ?? 0) : 0;
        $totalMins = $_fi_snap['available'] ? (int)($_fi_snap['totalMinutes'] ?? 0) : 0;
        $usedMins  = $_fi_snap['available'] ? (int)($_fi_snap['usedMinutes']  ?? 0) : 0;
        $isBlocked = !$_fi_snap['available'];

        $freeH   = round($freeMins / 60, 1);
        $totalH  = $totalMins > 0 ? round($totalMins / 60, 1) : 0;
        $usedH   = round($usedMins / 60, 1);
        $usedPct = ($totalMins > 0) ? min(100, round($usedMins / $totalMins * 100)) : 0;

        if ($isBlocked)       { $statusLabel = 'On Leave / Holiday'; $statusClass = 'fi-blocked'; }
        elseif ($freeMins <= 0) { $statusLabel = 'Fully Booked';    $statusClass = 'fi-booked';  }
        elseif ($usedMins === 0){ $statusLabel = $freeH . 'h Free'; $statusClass = 'fi-free';    }
        else                    { $statusLabel = $freeH . 'h Free'; $statusClass = 'fi-partial'; }

        if (!$isBlocked && $freeMins > 0) {
            $_fi_interns[] = [
                'name'        => $_fi_row['fullName'],
                'internId'    => $_fi_iid,
                'role'        => $_fi_row['role'],
                'batch'       => $_fi_row['batch'],
                'office'      => $_fi_row['office'],
                'freeH'       => $freeH,
                'usedH'       => $usedH,
                'totalH'      => $totalH,
                'usedPct'     => $usedPct,
                'statusLabel' => $statusLabel,
                'statusClass' => $statusClass,
            ];
        }
    }
    // Sort: most free hours first
    usort($_fi_interns, fn($a, $b) => $b['freeH'] <=> $a['freeH']);
}
$_fi_count = count($_fi_interns);
?>

<!-- ── Free Interns Widget ───────────────────────────────────────────────── -->
<style>
.fi-widget { background:#fff; border:1px solid #e2e8f0; border-radius:14px; box-shadow:0 2px 12px rgba(0,0,0,.04); margin-bottom:24px; overflow:hidden; }
.fi-widget-header { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; padding:16px 20px 12px; border-bottom:1px solid #f1f5f9; }
.fi-widget-title  { font-size:.98rem; font-weight:800; color:#0f172a; margin:0; display:flex; align-items:center; gap:8px; }
.fi-widget-title i { color:#10b981; }
.fi-date-form { display:flex; align-items:center; gap:8px; }
.fi-date-input { border:1px solid #cbd5e1; border-radius:8px; padding:5px 10px; font-size:.8rem; color:#334155; outline:none; }
.fi-date-input:focus { border-color:#4f46e5; box-shadow:0 0 0 3px rgba(79,70,229,.12); }
.fi-date-btn { background:#4f46e5; color:#fff; border:none; border-radius:8px; padding:5px 12px; font-size:.8rem; font-weight:600; cursor:pointer; }
.fi-date-btn:hover { background:#4338ca; }
.fi-count-badge { background:#dcfce7; color:#166534; font-size:.74rem; font-weight:700; padding:3px 10px; border-radius:20px; }
.fi-widget-body  { padding:16px 20px; }
.fi-table { width:100%; border-collapse:collapse; font-size:.82rem; }
.fi-table th { background:#f8fafc; color:#475569; font-weight:700; text-transform:uppercase; font-size:.7rem; padding:8px 12px; border-bottom:2px solid #e2e8f0; text-align:left; }
.fi-table td { padding:10px 12px; border-bottom:1px solid #f1f5f9; color:#334155; vertical-align:middle; }
.fi-table tr:last-child td { border-bottom:none; }
.fi-table tr:hover td { background:#f8fafc; }
.fi-bar-wrap { background:#e2e8f0; border-radius:99px; height:6px; width:90px; overflow:hidden; }
.fi-bar      { height:6px; border-radius:99px; background:#10b981; }
.fi-bar.warn { background:#f59e0b; }
.fi-bar.busy { background:#ef4444; }
.fi-badge    { display:inline-block; font-size:.7rem; font-weight:700; padding:2px 8px; border-radius:10px; }
.fi-free    { background:#dcfce7; color:#166534; }
.fi-partial { background:#fef3c7; color:#92400e; }
.fi-blocked { background:#fee2e2; color:#991b1b; }
.fi-booked  { background:#e0e7ff; color:#3730a3; }
.fi-empty   { text-align:center; padding:32px 16px; color:#94a3b8; font-size:.86rem; }
.fi-empty i { font-size:2rem; display:block; margin-bottom:8px; }
</style>

<div class="fi-widget">
    <div class="fi-widget-header">
        <h5 class="fi-widget-title">
            <i class="fas fa-user-check"></i>
            Free Interns Pool
            <span class="fi-count-badge"><?= $_fi_count ?> Available</span>
        </h5>
        <form method="GET" action="" class="fi-date-form">
            <?php
            // Re-pass all existing GET params except fi_date
            foreach ($_GET as $k => $v) {
                if ($k !== 'fi_date') echo '<input type="hidden" name="' . htmlspecialchars($k) . '" value="' . htmlspecialchars($v) . '">';
            }
            ?>
            <label style="font-size:.76rem; font-weight:700; color:#64748b; margin:0;">Date:</label>
            <input type="date"
                   name="fi_date"
                   class="fi-date-input"
                   value="<?= htmlspecialchars($_fi_date) ?>"
                   min="<?= date('Y-m-d') ?>"
                   id="fi_date_input">
            <button type="submit" class="fi-date-btn"><i class="fas fa-sync-alt mr-1"></i>Refresh</button>
            <a href="freeInternPool.php" style="font-size:.76rem; font-weight:700; color:#4f46e5; text-decoration:none; margin-left:6px;" title="Full Report">Full Report →</a>
        </form>
    </div>

    <div class="fi-widget-body">
        <?php if (empty($_fi_interns)): ?>
            <div class="fi-empty">
                <i class="fas fa-users-slash"></i>
                No free interns found for <strong><?= htmlspecialchars($_fi_dateLabel) ?></strong>
            </div>
        <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="fi-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Intern Name</th>
                            <th>Batch</th>
                            <th>Office</th>
                            <th>Free Hours</th>
                            <th>Used / Total</th>
                            <th>Utilisation</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($_fi_interns as $idx => $fi): 
                            $barClass = ($fi['usedPct'] >= 80) ? 'busy' : (($fi['usedPct'] >= 50) ? 'warn' : '');
                        ?>
                        <tr>
                            <td class="text-muted"><?= $idx + 1 ?></td>
                            <td>
                                <strong><?= htmlspecialchars($fi['name']) ?></strong><br>
                                <small class="text-muted"><?= htmlspecialchars($fi['role']) ?></small>
                            </td>
                            <td><?= $fi['batch'] ? '<span style="background:#e0e7ff;color:#3730a3;font-size:.7rem;font-weight:700;padding:2px 8px;border-radius:8px;">' . htmlspecialchars($fi['batch']) . '</span>' : '—' ?></td>
                            <td><?= htmlspecialchars($fi['office']) ?: '—' ?></td>
                            <td><strong style="color:#10b981;"><?= $fi['freeH'] ?>h</strong></td>
                            <td><?= $fi['usedH'] ?>h / <?= $fi['totalH'] ?>h</td>
                            <td>
                                <div class="fi-bar-wrap">
                                    <div class="fi-bar <?= $barClass ?>" style="width:<?= $fi['usedPct'] ?>%;"></div>
                                </div>
                                <small class="text-muted"><?= $fi['usedPct'] ?>%</small>
                            </td>
                            <td><span class="fi-badge <?= $fi['statusClass'] ?>"><?= htmlspecialchars($fi['statusLabel']) ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<!-- ── /Free Interns Widget ──────────────────────────────────────────────── -->
