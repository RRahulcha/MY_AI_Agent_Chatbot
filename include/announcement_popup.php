<?php
// include/announcement_popup.php
// Blocking employee acknowledgment popup modal
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['userId'])) return;

$popupUserId = $_SESSION['userId'];

// Fetch unread announcements for logged-in user with collation conversion safety
$popupQuery = "
    SELECT
        am.id AS announcement_id,
        am.title,
        am.description,
        am.priority,
        am.created_date,
        mp.is_read
    FROM announcement_mapping mp
    INNER JOIN announcement_master am ON am.id = mp.announcement_id
    WHERE CONVERT(mp.employee_id USING utf8mb4) = CONVERT('$popupUserId' USING utf8mb4)
      AND mp.is_read = 0
      AND am.status = 1
      AND (am.scheduled_date IS NULL OR am.scheduled_date <= NOW())
      AND (am.expiry_date IS NULL OR am.expiry_date >= CURDATE())
    ORDER BY
        CASE am.priority WHEN 'urgent' THEN 1 WHEN 'important' THEN 2 ELSE 3 END ASC,
        am.created_date DESC
    LIMIT 1
";

$popupRes = $conn->query($popupQuery);
if (!$popupRes || $popupRes->num_rows === 0) return;

$annData = $popupRes->fetch_assoc();
$annId       = (int)$annData['announcement_id'];
$annTitle    = htmlspecialchars($annData['title']);
$annDesc     = nl2br(htmlspecialchars($annData['description'] ?? ''));
$annPriority = strtolower($annData['priority'] ?? 'normal');
$annDate     = date('d M Y, h:i A', strtotime($annData['created_date']));

$headerBg = match($annPriority) {
    'urgent'    => 'linear-gradient(135deg, #dc2626, #991b1b)',
    'important' => 'linear-gradient(135deg, #d97706, #b45309)',
    default     => 'linear-gradient(135deg, #2563eb, #1d4ed8)',
};

$badgeText = match($annPriority) {
    'urgent'    => '⚡ URGENT ANNOUNCEMENT',
    'important' => '⚠️ IMPORTANT NOTICE',
    default     => '📢 OFFICIAL ANNOUNCEMENT',
};
?>
<style>
.ann-popup-overlay {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(7, 11, 22, 0.85);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    z-index: 99999;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1.25rem;
    animation: annFadeIn 0.3s ease;
}
@keyframes annFadeIn {
    from { opacity: 0; transform: scale(0.96); }
    to { opacity: 1; transform: scale(1); }
}
.ann-popup-card {
    background: #0f1830;
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 1.25rem;
    max-width: 580px;
    width: 100%;
    overflow: hidden;
    box-shadow: 0 25px 60px rgba(0,0,0,0.6), inset 0 1px 0 rgba(255,255,255,0.1);
    color: #eef2fb;
    font-family: 'Inter', sans-serif;
}
.ann-popup-header {
    background: <?= $headerBg ?>;
    padding: 1.25rem 1.5rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.ann-popup-badge {
    font-family: 'Sora', sans-serif;
    font-weight: 800;
    font-size: 0.85rem;
    letter-spacing: 1px;
    color: #ffffff;
    text-transform: uppercase;
}
.ann-popup-body {
    padding: 1.75rem 1.5rem;
    max-height: 55vh;
    overflow-y: auto;
}
.ann-popup-title {
    font-family: 'Sora', sans-serif;
    font-weight: 800;
    font-size: 1.35rem;
    color: #ffffff;
    margin-bottom: 0.75rem;
    line-height: 1.3;
}
.ann-popup-desc {
    font-size: 0.95rem;
    color: #c8d0e7;
    line-height: 1.65;
    margin-bottom: 1rem;
    white-space: pre-wrap;
}
.ann-popup-footer {
    padding: 1rem 1.5rem 1.5rem 1.5rem;
    background: rgba(15, 24, 48, 0.5);
    border-top: 1px solid rgba(255, 255, 255, 0.08);
}
.ann-popup-btn {
    width: 100%;
    padding: 0.875rem;
    border-radius: 0.75rem;
    border: none;
    background: linear-gradient(135deg, #36e2c9 0%, #4f8cff 100%);
    color: #070b16;
    font-family: 'Sora', sans-serif;
    font-weight: 800;
    font-size: 1rem;
    cursor: pointer;
    transition: transform 0.2s, box-shadow 0.2s;
    box-shadow: 0 4px 20px rgba(54, 226, 201, 0.3);
}
.ann-popup-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 25px rgba(54, 226, 201, 0.5);
}
</style>

<div class="ann-popup-overlay" id="annOverlay">
    <div class="ann-popup-card">
        <div class="ann-popup-header">
            <div class="ann-popup-badge"><?= $badgeText ?></div>
            <div style="font-size:0.75rem;opacity:0.85;font-weight:600;"><?= $annDate ?></div>
        </div>
        <div class="ann-popup-body">
            <h3 class="ann-popup-title"><?= $annTitle ?></h3>
            <div class="ann-popup-desc"><?= $annDesc ?></div>
        </div>
        <div class="ann-popup-footer">
            <button class="ann-popup-btn" id="ackBtn" data-id="<?= $annId ?>">
                ✓ Acknowledge &amp; Continue
            </button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const ackBtn = document.getElementById('ackBtn');
    if (!ackBtn) return;

    ackBtn.addEventListener('click', function() {
        const annId = this.getAttribute('data-id');
        this.disabled = true;
        this.innerText = 'Acknowledging...';

        const fd = new FormData();
        fd.append('announcement_id', annId);

        fetch('../ajax/acknowledge_announcement.php', {
            method: 'POST',
            body: fd
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const overlay = document.getElementById('annOverlay');
                if (overlay) overlay.remove();
            } else {
                alert('Failed to acknowledge: ' + (data.msg || 'Error'));
                ackBtn.disabled = false;
                ackBtn.innerText = '✓ Acknowledge & Continue';
            }
        })
        .catch(err => {
            console.error('Acknowledgment error:', err);
            const overlay = document.getElementById('annOverlay');
            if (overlay) overlay.remove();
        });
    });
});
</script>
