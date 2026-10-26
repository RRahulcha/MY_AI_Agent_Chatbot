<?php
// ============================================================
// SETTribe AMS — Advertisement Display Board
// Full-screen auto-rotating kiosk dashboard, slides every 10s
// ============================================================

require_once '../include/db_config.php'; // expects mysqli connection in $conn


// ---------- Helper ----------
function fetchAll($conn, $sql) {
    $res = $conn->query($sql);
    $rows = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) $rows[] = $row;
    }
    return $rows;
}
function fetchOne($conn, $sql) {
    $res = $conn->query($sql);
    return $res ? $res->fetch_assoc() : null;
}

// ---------- 1. Project counts ----------
$projectCounts = fetchOne($conn, "
    SELECT
        SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) AS running,
        SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END) AS past,
        COUNT(*) AS total
    FROM projectMaster
");

// ---------- 2. Active projects with client, owner, leader, team ----------
$activeProjects = fetchAll($conn, "
    SELECT projectId, projectName, client, modifiedUserId, deadLine, priority, hostingType
    FROM projectMaster
    WHERE status = 1
    ORDER BY projectId DESC
");

// Pre-fetch all allocations + user names in one go to avoid N+1 queries
$allAllocations = fetchAll($conn, "
    SELECT ap.projectId, ap.userId, s.fullName, s.designation, s.profilePhoto
    FROM allocatedProject ap
    LEFT JOIN signup s ON s.userId = ap.userId
    ORDER BY ap.projectId, ap.id ASC
");
$allocByProject = [];
foreach ($allAllocations as $a) {
    $allocByProject[$a['projectId']][] = $a;
}

// Owner lookups (modifiedUserId -> fullName)
$ownerIds = array_unique(array_filter(array_column($activeProjects, 'modifiedUserId')));
$ownerNames = [];
if (!empty($ownerIds)) {
    $idsEsc = array_map(fn($id) => "'" . $conn->real_escape_string($id) . "'", $ownerIds);
    $rows = fetchAll($conn, "SELECT userId, fullName FROM signup WHERE userId IN (" . implode(',', $idsEsc) . ")");
    foreach ($rows as $r) $ownerNames[$r['userId']] = $r['fullName'];
}

// Build enriched active project list
foreach ($activeProjects as &$p) {
    $team = $allocByProject[$p['projectId']] ?? [];
    $p['owner']  = $ownerNames[$p['modifiedUserId']] ?? 'Unassigned';
    $p['leader'] = !empty($team) ? $team[0]['fullName'] : 'Unassigned';
    $p['team']   = array_slice($team, 1);
    $p['team_count'] = count($team);
}
unset($p);

// ---------- 3. Past projects ----------
$pastProjects = fetchAll($conn, "
    SELECT projectId, projectName, client, modificationDate
    FROM projectMaster
    WHERE status = 0
    ORDER BY modificationDate DESC
    LIMIT 12
");

// ---------- 4. Client list (distinct, from active projects) ----------
$clients = fetchAll($conn, "
    SELECT client, COUNT(*) AS project_count
    FROM projectMaster
    WHERE status = 1 AND client IS NOT NULL AND client != ''
    GROUP BY client
    ORDER BY project_count DESC
");

// ---------- 5. Intern stats ----------
$internStats = fetchOne($conn, "
    SELECT
        COUNT(*) AS total_interns,
        SUM(CASE WHEN LOWER(TRIM(mode)) = 'online' THEN 1 ELSE 0 END) AS online_count,
        SUM(CASE WHEN LOWER(TRIM(mode)) = 'offline' THEN 1 ELSE 0 END) AS offline_count
    FROM signup
    WHERE userRole IN ('junior_intern','senior_intern')
      AND status = 'Active'
");

// ---------- 6. Branch-wise intern stats ----------
$branchStats = fetchAll($conn, "
    SELECT
        LOWER(TRIM(branch)) AS branch_key,
        COUNT(*) AS cnt,
        SUM(CASE WHEN LOWER(TRIM(mode)) = 'online' THEN 1 ELSE 0 END) AS online_cnt,
        SUM(CASE WHEN LOWER(TRIM(mode)) = 'offline' THEN 1 ELSE 0 END) AS offline_cnt
    FROM signup
    WHERE userRole IN ('junior_intern','senior_intern')
      AND status = 'Active'
      AND LOWER(TRIM(branch)) IN ('kinwat','pune')
    GROUP BY branch_key
");

// ---------- 7. Total active signups ----------
$activeSignupCount = fetchOne($conn, "
    SELECT COUNT(*) AS total_active
    FROM signup
    WHERE status = 'Active'
");

$branchData = ['kinwat' => ['cnt'=>0,'online_cnt'=>0,'offline_cnt'=>0], 'pune' => ['cnt'=>0,'online_cnt'=>0,'offline_cnt'=>0]];
foreach ($branchStats as $b) {
    $branchData[$b['branch_key']] = $b;
}

// ---------- Assemble final data payload ----------
$dashboardData = [
    'generated_at'    => date('d M Y, h:i A'),
    'project_counts'  => $projectCounts,
    'active_projects' => $activeProjects,
    'past_projects'   => $pastProjects,
    'clients'         => $clients,
    'intern_stats'    => $internStats,
    'branch_stats'    => $branchData,
    'active_signup_count' => (int)($activeSignupCount['total_active'] ?? 0),
];

// ---------- 8. Advertisements ----------
$rawAds = fetchAll($conn, "
    SELECT id, advertisement_group_id, title, description as other_info, media_type,
           media_path as file_path, display_location, status, start_date, end_date, created_at
    FROM advertisements
    WHERE status = 1
    ORDER BY id ASC
");
$adsMain = [];
$adsHeader = [];
$adsFooter = [];

foreach ($rawAds as $ad) {
    $loc = $ad['display_location'];
    $groupId = $ad['advertisement_group_id'] ?: 'single_' . $ad['id'];
    
    if ($loc === 'Top Header Bar') {
        if (!isset($adsHeader[$groupId])) $adsHeader[$groupId] = [];
        $adsHeader[$groupId][] = $ad;
    } elseif ($loc === 'Bottom Footer Bar') {
        if (!isset($adsFooter[$groupId])) $adsFooter[$groupId] = [];
        $adsFooter[$groupId][] = $ad;
    } else {
        if (!isset($adsMain[$groupId])) $adsMain[$groupId] = [];
        $adsMain[$groupId][] = $ad;
    }
}

// ---------- 9. Announcements & Placed Students ----------
$announcements = fetchAll($conn, "
    SELECT id, title, description, priority, created_date
    FROM announcement_master
    WHERE show_on_board = 1 AND status = 1 AND (expiry_date IS NULL OR expiry_date >= CURDATE())
    ORDER BY
        CASE priority WHEN 'urgent' THEN 1 WHEN 'important' THEN 2 ELSE 3 END ASC,
        created_date DESC
");

$placedStudents = fetchAll($conn, "
    SELECT id, student_name, branch, company_name, role_offered, package_ctc, photo_path, placement_date
    FROM placements
    WHERE status = 'active'
    ORDER BY placement_date DESC, id DESC
");

$dashboardData['adsHeader'] = array_values($adsHeader);
$dashboardData['adsFooter'] = array_values($adsFooter);
$dashboardData['adsMain'] = array_values($adsMain);
$dashboardData['announcements'] = $announcements;
$dashboardData['placed_students'] = $placedStudents;

$dataJson = json_encode($dashboardData, JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SETTribe AMS — Live Display Board</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@500&display=swap" rel="stylesheet">
<style>
/* ============================================================
   CSS VARIABLES & RESET
   ============================================================ */
:root {
    --bg-deep: #1a1230;
    --bg-panel: #0f1830;
    --bg-panel-2: #131f3e;
    --accent-cyan: #36e2c9;
    --accent-blue: #4f8cff;
    --accent-amber: #ffb648;
    --accent-rose: #ff6b8b;
    --accent-hero-red: #ff3b5c;
    --text-primary: #eef2fb;
    --text-muted: #8a97bd;
    --line: rgba(255,255,255,0.07);
    --grid-line: rgba(79,140,255,0.04);
    --topbar-h: 5.5rem;
    --bottombar-h: 2.2rem;
    --spotlight-h: 30vh;
    --slide-pad-v: 0.75rem;
    --slide-pad-h: 2.5rem;
    --gap: 1.25rem;
}

* { margin: 0; padding: 0; box-sizing: border-box; }

html, body {
    width: 100%; height: 100%;
    overflow: hidden;
    background: var(--bg-deep);
    color: var(--text-primary);
    font-family: 'Inter', sans-serif;
    font-size: clamp(12px, 1vw, 17px);
}



body {
    background:
        linear-gradient(var(--grid-line) 1px, transparent 1px),
        linear-gradient(90deg, var(--grid-line) 1px, transparent 1px),
        radial-gradient(circle at 15% 20%, rgba(139, 92, 246, 0.22) 0%, transparent 45%),
        radial-gradient(circle at 85% 15%, rgba(54, 226, 201, 0.18) 0%, transparent 45%),
        radial-gradient(circle at 50% 80%, rgba(255, 59, 92, 0.15) 0%, transparent 50%),
        radial-gradient(circle at 80% 85%, rgba(79, 140, 255, 0.18) 0%, transparent 45%),
        linear-gradient(160deg, #1a1230 0%, #14152b 50%, #0f1830 100%);
    background-size: 48px 48px, 48px 48px, 100% 100%, 100% 100%, 100% 100%, 100% 100%, 100% 100%;
}

/* Moving Glowing Orbs for Premium Background Effect */
@keyframes orbit1 {
    0% { transform: translate(0, 0); }
    33% { transform: translate(15vw, 15vh); }
    66% { transform: translate(-10vw, 25vh); }
    100% { transform: translate(0, 0); }
}
@keyframes orbit2 {
    0% { transform: translate(0, 0); }
    33% { transform: translate(-25vw, -10vh); }
    66% { transform: translate(10vw, -20vh); }
    100% { transform: translate(0, 0); }
}
.bg-orb-1 {
    position: fixed; top: -20%; left: -10%; width: 60vw; height: 60vw;
    background: radial-gradient(circle, rgba(79,140,255,0.10), transparent 60%);
    filter: blur(90px); z-index: 1; animation: orbit1 40s infinite linear alternate; pointer-events: none;
}
.bg-orb-2 {
    position: fixed; bottom: -20%; right: -10%; width: 70vw; height: 70vw;
    background: radial-gradient(circle, rgba(54,226,201,0.07), transparent 60%);
    filter: blur(90px); z-index: 1; animation: orbit2 50s infinite linear alternate; pointer-events: none;
}

/* ============================================================
   TOP BAR
   ============================================================ */
.topbar {
    position: fixed;
    top: 0; left: 0; right: 0;
    height: var(--topbar-h);
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 2.5rem;
    background: linear-gradient(180deg, rgba(15,24,48,0.97), rgba(15,24,48,0.60));
    border-bottom: 1px solid var(--line);
    z-index: 200;
    backdrop-filter: blur(8px);
    gap: 1rem;
}

.brand-logo {
    height: 38px;
    width: auto;
    max-width: 160px;
    object-fit: contain;
    flex-shrink: 0;
}
.brand {
    display: flex;
    align-items: center;
    gap: 1rem;
    min-width: 0;
}

.brand-text h1 {
    font-family: 'Sora', sans-serif;
    font-weight: 700;
    font-size: clamp(1.1rem, 2vw, 1.65rem);
    letter-spacing: 0.5px;
    white-space: nowrap;
}

.brand-text p {
    font-size: clamp(0.65rem, 0.9vw, 0.8rem);
    color: var(--text-muted);
    letter-spacing: 1.5px;
    text-transform: uppercase;
    margin-top: 0;
    white-space: nowrap;
}
.topbar-right {
    display: flex;
    align-items: center;
    gap: clamp(1rem, 2vw, 2.25rem);
    flex-shrink: 0;
}

.live-pill {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 0.45rem 1rem;
    border-radius: 999px;
    background: rgba(54,226,201,0.10);
    border: 1px solid rgba(54,226,201,0.35);
    font-size: clamp(0.7rem, 0.85vw, 0.82rem);
    font-weight: 600;
    letter-spacing: 1px;
    color: var(--accent-cyan);
    white-space: nowrap;
}

.live-dot {
    width: 8px; height: 8px;
    border-radius: 50%;
    background: var(--accent-cyan);
    animation: pulse 1.8s infinite;
    flex-shrink: 0;
}

@keyframes pulse {
    0%   { box-shadow: 0 0 0 0 rgba(54,226,201,0.6); }
    70%  { box-shadow: 0 0 0 10px rgba(54,226,201,0); }
    100% { box-shadow: 0 0 0 0 rgba(54,226,201,0); }
}

.clock {
    text-align: right;
    font-family: 'JetBrains Mono', monospace;
    white-space: nowrap;
}
.clock .time { font-size: clamp(1.1rem, 1.8vw, 1.5rem); font-weight: 600; }
.clock .date { font-size: clamp(0.65rem, 0.85vw, 0.8rem); color: var(--text-muted); margin-top: 2px; }

/* ============================================================
   PROGRESS BAR + DOTS
   ============================================================ */
.progress-track {
    position: fixed;
    bottom: 0; left: 0; right: 0;
    height: 4px;
    background: rgba(255,255,255,0.05);
    z-index: 200;
}

.progress-fill {
    height: 100%;
    width: 0%;
    background: linear-gradient(90deg, var(--accent-cyan), var(--accent-blue));
    box-shadow: 0 0 16px rgba(54,226,201,0.6);
}

.dots {
    position: fixed;
    bottom: 12px; left: 0; right: 0;
    display: flex;
    justify-content: center;
    gap: 8px;
    z-index: 200;
    flex-wrap: wrap;
    padding: 0 1rem;
}

.dot {
    width: 8px; height: 8px;
    border-radius: 50%;
    background: rgba(255,255,255,0.18);
    transition: all .35s ease;
    flex-shrink: 0;
}

.dot.active {
    background: var(--accent-cyan);
    width: 24px;
    border-radius: 4px;
    box-shadow: 0 0 10px rgba(54,226,201,0.6);
}

/* ============================================================
   STAGE & SLIDES
   ============================================================ */
.stage {
    position: fixed;
    top: var(--topbar-h);
    bottom: calc(var(--bottombar-h) + var(--spotlight-h));
    left: 0; right: 0;
}

.slide {
    position: absolute;
    inset: var(--slide-pad-v) var(--slide-pad-h);
    opacity: 0;
    transform: translateY(40px) scale(0.96);
    transition:
        opacity .75s cubic-bezier(.22,1,.36,1),
        transform .75s cubic-bezier(.22,1,.36,1);
    pointer-events: none;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.slide.active {
    opacity: 1;
    transform: translateY(0) scale(1);
    pointer-events: auto;
}

.slide-eyebrow {
    font-size: clamp(0.7rem, 0.9vw, 0.85rem);
    letter-spacing: 3px;
    text-transform: uppercase;
    color: var(--accent-cyan);
    font-weight: 600;
    margin-bottom: 0.3rem;
    flex-shrink: 0;
}

.slide-title {
    font-family: 'Sora', sans-serif;
    font-size: clamp(1.4rem, 3vw, 2.6rem);
    font-weight: 800;
    letter-spacing: -0.5px;
    margin-bottom: clamp(0.75rem, 1.5vw, 1.5rem);
    flex-shrink: 0;
    line-height: 1.15;
}

/* ============================================================
   STAT CARDS
   ============================================================ */
.stat-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: var(--gap);
    margin-bottom: clamp(0.75rem, 1.5vw, 1.75rem);
    flex-shrink: 0;
}

.stat-card {
    background: linear-gradient(160deg, rgba(15,24,48,0.75), rgba(19,31,62,0.55));
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 1.25rem;
    padding: clamp(1rem, 1.8vw, 1.5rem);
    position: relative;
    overflow: hidden;
    box-shadow: 0 10px 25px rgba(0,0,0,0.4);
    transition: transform 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275), box-shadow 0.4s ease;
}

@keyframes floatStat {
    0% { transform: translateY(0); }
    50% { transform: translateY(-6px); }
    100% { transform: translateY(0); }
}

.slide.active .stat-card {
    animation: floatStat 6s infinite ease-in-out;
}
.slide.active .stat-card:nth-child(2) { animation-delay: 0.5s; }
.slide.active .stat-card:nth-child(3) { animation-delay: 1.0s; }
.slide.active .stat-card:nth-child(4) { animation-delay: 1.5s; }

.stat-card:hover {
    transform: translateY(-8px) scale(1.02) !important;
    box-shadow: 0 15px 35px rgba(0,0,0,0.6), inset 0 0 15px rgba(255,255,255,0.05);
}

.stat-card::after {
    content: '';
    position: absolute;
    top: -40%; right: -30%;
    width: 160px; height: 160px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(79,140,255,0.15), transparent 70%);
    pointer-events: none;
}

.stat-card .label {
    font-size: clamp(0.65rem, 0.85vw, 0.78rem);
    letter-spacing: 1.5px;
    text-transform: uppercase;
    color: var(--text-muted);
    font-weight: 600;
}

.stat-card .value {
    font-family: 'Sora', sans-serif;
    font-size: clamp(1.6rem, 3.5vw, 3.4rem);
    font-weight: 800;
    margin-top: 0.4rem;
    line-height: 1;
}

.stat-card .sub {
    font-size: clamp(0.7rem, 0.85vw, 0.85rem);
    color: var(--text-muted);
    margin-top: 0.4rem;
}

.stat-card.accent-cyan  { background: rgba(54, 226, 201, 0.08); border: 1px solid rgba(54, 226, 201, 0.35); box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4), inset 0 1px 0 rgba(54, 226, 201, 0.2); }
.stat-card.accent-blue  { background: rgba(79, 140, 255, 0.08); border: 1px solid rgba(79, 140, 255, 0.35); box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4), inset 0 1px 0 rgba(79, 140, 255, 0.2); }
.stat-card.accent-amber { background: rgba(255, 182, 72, 0.08); border: 1px solid rgba(255, 182, 72, 0.35); box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4), inset 0 1px 0 rgba(255, 182, 72, 0.2); }
.stat-card.accent-rose  { background: rgba(244, 63, 94, 0.08); border: 1px solid rgba(244, 63, 94, 0.35); box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4), inset 0 1px 0 rgba(244, 63, 94, 0.2); }
.stat-card.accent-hero  { background: rgba(255, 59, 92, 0.08); border: 1px solid rgba(255, 59, 92, 0.35); box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4), inset 0 1px 0 rgba(255, 59, 92, 0.2); }

.stat-card.accent-cyan .value  { color: var(--accent-cyan); text-shadow: 0 0 20px rgba(54, 226, 201, 0.6); }
.stat-card.accent-blue .value  { color: var(--accent-blue); text-shadow: 0 0 20px rgba(79, 140, 255, 0.6); }
.stat-card.accent-amber .value { color: var(--accent-amber); text-shadow: 0 0 20px rgba(255, 182, 72, 0.6); }
.stat-card.accent-rose .value  { color: var(--accent-rose); text-shadow: 0 0 20px rgba(244, 63, 94, 0.6); }
.stat-card.accent-hero .value  { color: var(--accent-hero-red); text-shadow: 0 0 20px rgba(255, 59, 92, 0.6); }

/* ============================================================
   PANELS
   ============================================================ */
.panel {
    background: linear-gradient(160deg, rgba(54,226,201,0.06), rgba(19,31,62,0.85));
    border: 1px solid rgba(54,226,201,0.18);
    border-radius: 1.25rem;
    padding: clamp(1rem, 2vw, 1.75rem);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    backdrop-filter: blur(12px);
    box-shadow: 0 4px 20px rgba(54,226,201,0.08), 0 8px 32px rgba(0,0,0,0.5);
}

.panel:hover {
    box-shadow: 0 4px 24px rgba(54,226,201,0.15), 0 12px 40px rgba(0,0,0,0.6);
    border-color: rgba(54,226,201,0.28);
}

.panel h3 {
    font-family: 'Sora', sans-serif;
    font-size: clamp(0.9rem, 1.2vw, 1.1rem);
    font-weight: 700;
    margin-bottom: 0.875rem;
    display: flex;
    align-items: center;
    gap: 10px;
    flex-shrink: 0;
}

.panel h3::before {
    content: '';
    width: 5px; height: 18px;
    border-radius: 3px;
    background: var(--accent-cyan);
    display: inline-block;
    flex-shrink: 0;
}

/* ============================================================
   LAYOUT GRIDS
   ============================================================ */
.cols-2 {
    display: grid;
    grid-template-columns: 1.3fr 1fr;
    gap: var(--gap);
    flex: 1;
    min-height: 0;
}

.cols-3 {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: var(--gap);
}

.full-h {
    flex: 1;
    display: flex;
    flex-direction: column;
    min-height: 0;
    overflow: hidden;
}

/* ============================================================
   MARQUEE (horizontal continuous scroll)
   ============================================================ */
.marquee-wrap {
    overflow: hidden;
    border-radius: 0.875rem;
    border: 1px solid var(--line);
    background: rgba(255,255,255,0.02);
    padding: 0.875rem 0;
    flex-shrink: 0;
}

.marquee {
    display: flex;
    gap: 2.5rem;
    white-space: nowrap;
    animation: scroll-left 40s linear infinite;
}

.marquee:hover { animation-play-state: paused; }

.marquee-item {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: clamp(0.85rem, 1.1vw, 1rem);
    font-weight: 600;
    padding: 0 1.25rem;
    flex-shrink: 0;
}

.marquee-item .mdot {
    width: 7px; height: 7px;
    border-radius: 50%;
    background: var(--accent-cyan);
    flex-shrink: 0;
}

@keyframes scroll-left {
    from { transform: translateX(0); }
    to   { transform: translateX(-50%); }
}

/* ============================================================
   AUTO-SCROLL CONTAINER (vertical continuous scroll)
   Used for: project list, past projects, clients
   ============================================================ */
.vscroll-outer {
    flex: 1;
    overflow: hidden;
    position: relative;
    min-height: 0;
    mask-image: linear-gradient(
        to bottom,
        transparent 0%,
        black 4%,
        black 96%,
        transparent 100%
    );
    -webkit-mask-image: linear-gradient(
        to bottom,
        transparent 0%,
        black 4%,
        black 96%,
        transparent 100%
    );
}

.vscroll-track {
    display: flex;
    flex-direction: column;
    will-change: transform;
    /* animation is applied by JS once slide becomes active */
}

.vscroll-track.paused { animation-play-state: paused !important; }

@keyframes scroll-up {
    from { transform: translateY(0) translateZ(0); }
    to   { transform: translateY(var(--scroll-dist, -50%)) translateZ(0); }
}

/* ============================================================
   PROJECT LIST CARDS
   ============================================================ */
.project-list {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.project-card {
    background: linear-gradient(160deg, rgba(79,140,255,0.10), rgba(255,255,255,0.02));
    border: 1px solid rgba(79,140,255,0.22);
    border-radius: 0.875rem;
    padding: clamp(0.75rem, 1.2vw, 1.1rem) clamp(1rem, 1.5vw, 1.4rem);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    flex-shrink: 0;
    box-shadow: 0 4px 20px rgba(79,140,255,0.08);
    transition: all .3s cubic-bezier(0.25, 0.8, 0.25, 1);
}

.project-card:hover { 
    border-color: rgba(54,226,201,0.4); 
    background: rgba(54,226,201,0.06);
    transform: translateX(8px);
    box-shadow: 0 5px 20px rgba(54,226,201,0.15);
}

.project-card .pc-left { min-width: 0; flex: 1; }

.project-card .pc-left .pc-name {
    font-weight: 700;
    font-size: clamp(0.85rem, 1.1vw, 1.05rem);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.project-card .pc-left .pc-client {
    font-size: clamp(0.7rem, 0.85vw, 0.82rem);
    color: var(--text-muted);
    margin-top: 3px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.project-card .pc-right {
    font-size: clamp(0.7rem, 0.85vw, 0.82rem);
    color: var(--accent-cyan);
    font-weight: 600;
    white-space: nowrap;
    flex-shrink: 0;
}

/* ============================================================
   CLIENT GRID CARDS
   ============================================================ */
.client-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: clamp(0.75rem, 1.2vw, 1.25rem);
    padding-bottom: 0.5rem;
}

.client-card {
    background: linear-gradient(160deg, rgba(79,140,255,0.14), rgba(255,255,255,0.02));
    border: 1px solid rgba(79,140,255,0.30);
    border-radius: 1.1rem;
    padding: clamp(1rem, 1.5vw, 1.5rem);
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    box-shadow: 0 4px 20px rgba(79,140,255,0.10);
    transition: all .4s cubic-bezier(0.25, 0.8, 0.25, 1);
    flex-shrink: 0;
}

.client-card:hover {
    border-color: rgba(79,140,255,0.5);
    background: rgba(79,140,255,0.08);
    transform: translateY(-6px);
    box-shadow: 0 10px 30px rgba(79,140,255,0.25);
}

.client-card .cname {
    font-family: 'Sora', sans-serif;
    font-size: clamp(0.9rem, 1.2vw, 1.15rem);
    font-weight: 700;
    line-height: 1.3;
}

.client-card .cmeta { font-size: clamp(0.7rem, 0.85vw, 0.82rem); color: var(--text-muted); }

.client-icon {
    width: clamp(2.2rem, 3vw, 2.9rem);
    height: clamp(2.2rem, 3vw, 2.9rem);
    border-radius: 0.75rem;
    background: linear-gradient(135deg, rgba(79,140,255,0.25), rgba(54,226,201,0.25));
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: 'Sora', sans-serif;
    font-weight: 800;
    font-size: clamp(0.85rem, 1.1vw, 1.1rem);
    color: var(--accent-cyan);
    flex-shrink: 0;
}

/* ============================================================
   PROJECT SPOTLIGHT
   ============================================================ */
.project-hero {
    display: flex;
    flex-direction: column;
    gap: clamp(0.75rem, 1.5vw, 1.5rem);
    overflow-y: auto;
    height: 100%;
}

.project-hero::-webkit-scrollbar { width: 5px; }
.project-hero::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 3px; }

.project-hero .pclient {
    color: var(--text-muted);
    font-size: clamp(0.8rem, 1vw, 1rem);
    margin-top: 4px;
}

.tag-row {
    display: flex;
    gap: 8px;
    margin: 0.625rem 0;
    flex-wrap: wrap;
}

.tag {
    padding: clamp(0.25rem, 0.5vw, 0.4rem) clamp(0.7rem, 1vw, 1rem);
    border-radius: 999px;
    font-size: clamp(0.65rem, 0.8vw, 0.75rem);
    font-weight: 600;
    letter-spacing: 0.5px;
    background: rgba(79,140,255,0.12);
    border: 1px solid rgba(79,140,255,0.3);
    color: var(--accent-blue);
    white-space: nowrap;
}
.tag.priority  { background: rgba(255,182,72,0.12); border-color: rgba(255,182,72,0.3); color: var(--accent-amber); }
.tag.hosting   { background: rgba(54,226,201,0.12); border-color: rgba(54,226,201,0.3); color: var(--accent-cyan); }
.tag.running   { background: rgba(54,226,201,0.08); border-color: rgba(54,226,201,0.25); color: var(--accent-cyan); }

.role-block { flex-shrink: 0; }
.role-block .role-label {
    font-size: clamp(0.65rem, 0.8vw, 0.75rem);
    letter-spacing: 2px;
    text-transform: uppercase;
    color: var(--text-muted);
    margin-bottom: 0.45rem;
}

.person {
    display: flex;
    align-items: center;
    gap: 0.7rem;
    margin-bottom: 0.5rem;
}

.avatar {
    width: clamp(2rem, 2.8vw, 2.6rem);
    height: clamp(2rem, 2.8vw, 2.6rem);
    border-radius: 50%;
    background: linear-gradient(135deg, var(--accent-blue), var(--accent-cyan));
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: 'Sora', sans-serif;
    font-weight: 700;
    font-size: clamp(0.75rem, 1vw, 1rem);
    color: #06101f;
    flex-shrink: 0;
}

.person .pinfo .pname-small { font-weight: 600; font-size: clamp(0.8rem, 1vw, 0.95rem); }
.person .pinfo .prole       { font-size: clamp(0.65rem, 0.8vw, 0.75rem); color: var(--text-muted); }

.team-grid { display: flex; flex-wrap: wrap; gap: 8px; }

.team-chip {
    display: flex;
    align-items: center;
    gap: 7px;
    background: rgba(255,255,255,0.04);
    border: 1px solid var(--line);
    padding: clamp(0.25rem, 0.4vw, 0.375rem) clamp(0.6rem, 0.9vw, 0.875rem) clamp(0.25rem, 0.4vw, 0.375rem) clamp(0.25rem, 0.4vw, 0.375rem);
    border-radius: 999px;
    font-size: clamp(0.7rem, 0.85vw, 0.82rem);
    font-weight: 500;
}

.team-chip .avatar {
    width: clamp(1.4rem, 2vw, 1.75rem);
    height: clamp(1.4rem, 2vw, 1.75rem);
    font-size: clamp(0.6rem, 0.75vw, 0.75rem);
}

/* ============================================================
   BRANCH STATS
   ============================================================ */
.branch-card {
    background: linear-gradient(160deg, rgba(54,226,201,0.10), rgba(255,255,255,0.02));
    border: 1px solid rgba(54,226,201,0.25);
    border-radius: 1.1rem;
    padding: clamp(1rem, 1.8vw, 1.5rem);
    box-shadow: 0 4px 20px rgba(54,226,201,0.08);
    flex: 1;
}

.branch-card .bname {
    font-family: 'Sora', sans-serif;
    font-size: clamp(1rem, 1.6vw, 1.5rem);
    font-weight: 800;
    margin-bottom: 3px;
}

.branch-card .btotal {
    font-size: clamp(0.7rem, 0.85vw, 0.85rem);
    color: var(--text-muted);
    margin-bottom: 0.875rem;
}

.bar-row {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 10px;
}

.bar-label {
    font-size: clamp(0.65rem, 0.8vw, 0.8rem);
    width: 4rem;
    color: var(--text-muted);
    flex-shrink: 0;
}

.bar-track {
    flex: 1;
    height: clamp(10px, 1.2vw, 14px);
    border-radius: 8px;
    background: rgba(255,255,255,0.06);
    overflow: hidden;
}

.bar-fill {
    height: 100%;
    border-radius: 8px;
    transition: width 1.4s cubic-bezier(.22,1,.36,1);
}

.bar-fill.online  { background: linear-gradient(90deg, var(--accent-cyan), var(--accent-blue)); }
.bar-fill.offline { background: linear-gradient(90deg, var(--text-muted), #5a6690); }

.bar-num {
    width: 2.2rem;
    text-align: right;
    font-weight: 700;
    font-family: 'JetBrains Mono', monospace;
    font-size: clamp(0.7rem, 0.85vw, 0.85rem);
    flex-shrink: 0;
}

/* ============================================================
   DONUT CHART
   ============================================================ */
.chart-wrap {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: clamp(1.5rem, 4vw, 3.5rem);
    flex: 1;
    flex-wrap: wrap;
    min-height: 0;
    padding: 0.5rem 0;
}

.donut {
    position: relative;
    width: clamp(9rem, 20vw, 16rem);
    height: clamp(9rem, 20vw, 16rem);
    flex-shrink: 0;
}

.donut svg {
    transform: rotate(-90deg);
    width: 100%; height: 100%;
}

.donut-center {
    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
}

.donut-center .big {
    font-family: 'Sora', sans-serif;
    font-size: clamp(1.6rem, 4vw, 3rem);
    font-weight: 800;
}

.donut-center .small {
    font-size: clamp(0.6rem, 0.8vw, 0.75rem);
    color: var(--text-muted);
    letter-spacing: 1px;
    text-transform: uppercase;
}

.legend {
    display: flex;
    flex-direction: column;
    gap: clamp(0.75rem, 1.5vw, 1.1rem);
}

.legend-item {
    display: flex;
    align-items: center;
    gap: 12px;
}

.legend-dot {
    width: 12px; height: 12px;
    border-radius: 4px;
    flex-shrink: 0;
}

.legend-item .val {
    font-family: 'Sora', sans-serif;
    font-size: clamp(1.2rem, 2.5vw, 1.8rem);
    font-weight: 800;
    margin-left: auto;
}

.legend-item .lbl {
    font-size: clamp(0.8rem, 1vw, 0.95rem);
    color: var(--text-muted);
    white-space: nowrap;
}

/* ============================================================
   ─── RESPONSIVE: LANDSCAPE — medium screens ───────────────
   ============================================================ */
@media (max-width: 1366px) and (orientation: landscape) {
    :root {
        --topbar-h: 5rem;
        --gap: 1.1rem;
        --slide-pad-v: 1.25rem;
        --slide-pad-h: 2rem;
    }
    .stat-grid { grid-template-columns: repeat(2, 1fr); }
    .client-grid { grid-template-columns: repeat(2, 1fr); }
    .cols-3 { gap: 1rem; }
}

@media (max-width: 900px) and (orientation: landscape) {
    :root {
        --topbar-h: 4.5rem;
        --gap: 0.875rem;
        --slide-pad-v: 1rem;
        --slide-pad-h: 1.25rem;
    }
    .stat-grid { grid-template-columns: repeat(2, 1fr); }
    .cols-2    { grid-template-columns: 1fr; }
    .client-grid { grid-template-columns: repeat(2, 1fr); }
    .cols-3    { grid-template-columns: 1fr 1fr; }
    .brand-text p { display: none; }
}

/* ============================================================
   ─── RESPONSIVE: PORTRAIT ORIENTATION ────────────────────
   Full layout reflow for vertical kiosk screens
   ============================================================ */
@media (orientation: portrait) {
    :root {
        --topbar-h: 4.75rem;
        --bottombar-h: 2rem;
        --gap: 0.875rem;
        --slide-pad-v: 1rem;
        --slide-pad-h: 1.25rem;
    }

    html { font-size: clamp(11px, 2.5vw, 16px); }

    .topbar {
        padding: 0 1.5rem;
        height: var(--topbar-h);
    }

    .brand-text p { display: none; }

    .live-pill span:last-child { display: none; } /* hide LIVE DATA text, keep dot */

    /* Stats: 2×2 grid portrait */
    .stat-grid { grid-template-columns: repeat(2, 1fr); gap: 0.75rem; }

    /* Collapse two-col layout to single col */
    .cols-2 {
        grid-template-columns: 1fr;
        flex: none;
    }

    /* Three col → single col in portrait */
    .cols-3 { grid-template-columns: 1fr; gap: 0.75rem; }

    /* Client grid: 2 col portrait */
    .client-grid { grid-template-columns: repeat(2, 1fr); gap: 0.75rem; }

    /* Chart area: stack vertically */
    .chart-wrap {
        flex-direction: column;
        gap: 1.25rem;
        flex: none;
        padding: 1rem 0;
    }

    .legend { flex-direction: row; flex-wrap: wrap; gap: 0.75rem; }
    .legend-item { flex-direction: column; align-items: flex-start; gap: 4px; min-width: 80px; }
    .legend-item .val { margin-left: 0; }

    .donut {
        width: clamp(9rem, 45vw, 13rem);
        height: clamp(9rem, 45vw, 13rem);
    }

    /* Branch cards: portrait stacked */
    .branch-stats-row {
        flex-direction: column !important;
    }

    /* Project spotlight: portrait */
    .cols-2.spotlight-cols {
        grid-template-columns: 1fr;
        grid-template-rows: auto auto;
    }

    .spotlight-counter {
        display: flex !important; 
    }

    .project-hero { height: auto; max-height: 60vh; }

    .slide-title { font-size: clamp(1.3rem, 5vw, 2rem); margin-bottom: 0.75rem; }

    /* Full-height helper: relax in portrait */
    .full-h { flex: none; min-height: 50vh; }

    .panel { min-height: auto; }
}

/* Very narrow portrait phones / narrow signage panels */
@media (orientation: portrait) and (max-width: 480px) {
    :root {
        --topbar-h: 4rem;
        --gap: 0.625rem;
        --slide-pad-v: 0.75rem;
        --slide-pad-h: 0.875rem;
    }
    .stat-grid { grid-template-columns: 1fr; }
    .client-grid { grid-template-columns: 1fr; }
    .brand-text h1 { font-size: 1.1rem; }
    .clock .time { font-size: 1rem; }
    .topbar-right { gap: 0.75rem; }
}

/* ============================================================
   SCROLLBAR STYLES
   ============================================================ */
.project-hero::-webkit-scrollbar { width: 5px; }
.project-hero::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.08); border-radius: 3px; }

/* ============================================================
   UTILITY
   ============================================================ */
.mt-auto { margin-top: auto; }
.text-muted { color: var(--text-muted); }

/* ============================================================
   PLACEMENT HIGHLIGHTS — ADVERTISEMENT BANNER STRIP
   Full-width glassmorphism footer visible on every slide.
   NOW: label panel on left, VERTICALLY stacked / vertically
   auto-scrolling student cards on the right — built to match
   a portrait/vertical kiosk screen instead of a landscape row.
   ============================================================ */

/* ── Container — bright celebratory amber gradient ── */
.spotlight-footer {
    position: fixed;
    bottom: 0; left: 0; right: 0;
    height: var(--spotlight-h);
    display: flex;
    align-items: stretch;
    overflow: hidden;
    z-index: 300;
    background: linear-gradient(135deg, rgba(255, 182, 72, 0.15) 0%, rgba(245, 158, 11, 0.08) 50%, rgba(20, 21, 43, 0.95) 100%);
    border-top: 1px solid rgba(255, 182, 72, 0.4);
    box-shadow: 0 12px 40px rgba(255, 182, 72, 0.15), inset 0 1px 0 rgba(255, 182, 72, 0.3);
    backdrop-filter: blur(16px);
}

/* Illuminated top border — warm glow sweep */
.spotlight-footer::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    background: linear-gradient(to right, #FFD580, #FF8A3D, #FFD580, #FF8A3D, #FFD580);
    background-size: 300% 100%;
    animation: banner-glow-slide 4s linear infinite;
    z-index: 1;
    box-shadow: 0 0 14px rgba(255,182,72,0.8), 0 0 30px rgba(255,138,61,0.5);
}

@keyframes banner-glow-slide {
    0%   { background-position: 0% center; }
    100% { background-position: 300% center; }
}

/* ── Left accent panel ── */
.sp-label-panel {
    width: 13rem;
    flex-shrink: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 0.35rem;
    padding: 0 1rem;
    background: rgba(0,0,0,0.12);
    border-right: 2px solid rgba(0,0,0,0.18);
    position: relative;
    z-index: 2;
}

.sp-label-panel::after {
    content: '';
    position: absolute;
    right: 0; top: 10%; bottom: 10%;
    width: 2px;
    background: linear-gradient(180deg, transparent, rgba(42,21,0,0.6), rgba(42,21,0,0.6), transparent);
    border-radius: 999px;
}

.spl-icon {
    font-size: 2rem;
    line-height: 1;
    filter: drop-shadow(0 0 14px rgba(255,220,50,0.9));
    animation: trophy-pulse 2.5s ease-in-out infinite;
}

@keyframes trophy-pulse {
    0%, 100% { filter: drop-shadow(0 0 10px rgba(255,220,50,0.8)) drop-shadow(0 0 3px rgba(255,180,0,0.6)); transform: scale(1); }
    50%       { filter: drop-shadow(0 0 26px rgba(255,220,50,1.0)) drop-shadow(0 0 10px rgba(255,180,0,0.9)); transform: scale(1.12); }
}

.spl-title {
    font-family: 'Sora', sans-serif;
    font-weight: 900;
    font-size: 0.56rem;
    letter-spacing: 2.5px;
    text-transform: uppercase;
    text-align: center;
    line-height: 1.5;
    color: #faf8f7ff;
    text-shadow: none;
    -webkit-text-fill-color: unset;
    filter: none;
}



/* ── Cards column (static mode: fill height, scroll mode: vertical ticker) ──
   Replaces the old horizontal .sp-cards-row layout. The container to the
   right of .sp-label-panel now stacks cards top-to-bottom and, when they
   don't all fit, loops them upward — same pattern as .vscroll-outer /
   .vscroll-track used elsewhere in this file, just scoped to this footer. */
/* ── Dedicated outer container for Placed Students Slider (Samsung Galaxy S8+ 360px & Kiosk) ── */
#placed-students-slider {
    width: 100%;
    height: auto;
    overflow: hidden;
    position: relative;
    display: flex;
    align-items: center;
    flex: 1;
    min-width: 0;
}

/* Flex wrapper track — Infinite horizontal smooth auto-scroll Right to Left */
#placed-students-slider .slider-track {
    display: flex;
    flex-direction: row;
    width: max-content;
    gap: 16px;
    will-change: transform;
    animation: slideRightToLeft var(--slider-duration, 20s) linear infinite;
}

#placed-students-slider .slider-track:hover,
#placed-students-slider .slider-track:active,
#placed-students-slider .slider-track:focus-within {
    animation-play-state: paused !important;
}

@keyframes slideRightToLeft {
    0%   { transform: translateX(0); }
    100% { transform: translateX(var(--slider-dist, -50%)); }
}

/* Individual student card taking full available width with fixed height & high-contrast vibrant glassmorphism */
.student-card {
    flex: 0 0 100%;
    width: 100%;
    height: 148px;
    max-height: 148px;
    box-sizing: border-box;
    padding: 8px 14px;
    border-radius: 16px;
    background: rgba(25, 33, 56, 0.85);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    border: 1px solid rgba(0, 229, 255, 0.3);
    display: flex;
    align-items: center;
    gap: 14px;
    flex-shrink: 0;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.4), 0 0 10px rgba(0, 229, 255, 0.1);
    position: relative;
    overflow: hidden;
    margin-top: 40px;
}

/* Student profile avatar (70px x 70px round image with accent glow border) */
.student-avatar,
.student-avatar-placeholder {
    width: 70px;
    height: 70px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid var(--accent-cyan);
    box-shadow: 0 0 12px rgba(54, 226, 201, 0.4), 0 0 20px rgba(54, 226, 201, 0.2);
    flex-shrink: 0;
}

.student-avatar-placeholder {
    background: linear-gradient(135deg, #1e3a8a, #0ea5e9);
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: 'Sora', sans-serif;
    font-weight: 800;
    font-size: 1.6rem;
    color: #ffffff;
    text-shadow: 0 2px 6px rgba(0, 0, 0, 0.4);
}

/* Details container on right */
.student-details {
    display: flex;
    flex-direction: column;
    gap: 4px;
    flex: 1;
    min-width: 0;
}

/* Student Name: Crisp #FFFFFF (font-size: 15px, font-weight: 600) */
.student-name {
    font-family: 'Sora', sans-serif;
    font-weight: 600;
    font-size: 15px;
    color: #FFFFFF;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    line-height: 1.25;
    letter-spacing: 0.3px;
}

/* Company Name: #A0AEC0 (font-size: 14px) with company logo icon */
.student-company {
    font-size: 14px;
    font-weight: 500;
    color: #A0AEC0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    display: flex;
    align-items: center;
    gap: 6px;
    line-height: 1.3;
}

/* Role / Package tag formatted cleanly as a pill badge at the bottom */
.student-badges-row {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-top: 4px;
    flex-wrap: nowrap;
    overflow: hidden;
}
@media (max-width: 600px){
.student-pill-badge {
    padding: 3px 7px;
    border-radius: 999px;
    font-size: 9px;
    font-weight: 700;
    white-space: nowrap;
    flex-shrink: 0;
    letter-spacing: 0.2px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
}
.student-pill-badge.role-badge {
    background: linear-gradient(90deg, #00c6ff, #0072ff);
    color: #ffffff;
    border: none;
    box-shadow: 0 0 10px rgba(0, 198, 255, 0.4);
}

.student-pill-badge.pkg-badge {
    background: linear-gradient(90deg, #059669, #10b981);
    border: 1px solid rgba(16, 185, 129, 0.6);
    color: #ffffff;
    box-shadow: 0 0 10px rgba(16, 185, 129, 0.4);
}

.student-pill-badge.branch-badge {
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid rgba(255, 255, 255, 0.18);
    color: #A0AEC0;
}

/* Hide legacy vertical list layout */
.sp-cards-row {
    display: none !important;
}

/* ── Individual student card — now a full-width horizontal row,
   stacked vertically one below another ── */
.sp-card {
    width: 100%;
    flex-shrink: 0;
    background: rgba(0,0,0,0.18);
    border: 1px solid rgba(255,255,255,0.18);
    border-radius: 0.9rem;
    padding: 0.55rem 0.9rem;
    display: flex;
    align-items: center;
    gap: 0.85rem;
    position: relative;
    overflow: hidden;
    cursor: default;
    transition: border-color 0.3s ease, box-shadow 0.3s ease, transform 0.3s ease;
    box-shadow:
        0 4px 20px rgba(0,0,0,0.35),
        inset 0 1px 0 rgba(255,255,255,0.08);
}

/* Top shimmer accent line */
.sp-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 2px;
    background: linear-gradient(90deg,
        transparent 0%,
        rgba(54,226,201,0.6) 40%,
        rgba(79,140,255,0.6) 60%,
        transparent 100%);
    opacity: 0;
    transition: opacity 0.35s ease;
}

/* Ambient glow orb inside card */
.sp-card::after {
    content: '';
    position: absolute;
    top: -20px; right: -20px;
    width: 80px; height: 80px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(79,140,255,0.12), transparent 70%);
    pointer-events: none;
}

.sp-card:hover {
    border-color: rgba(0,242,254,0.45);
    box-shadow:
        0 8px 36px rgba(0,242,254,0.20),
        0 2px 8px rgba(0,0,0,0.6),
        inset 0 0 25px rgba(79,172,254,0.06);
    transform: translateX(4px) scale(1.005);
}

.sp-card:hover::before { opacity: 1; }

/* ── Avatar ── */
.sp-photo {
    width: 56px;
    height: 56px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid rgba(79,172,254,0.6);
    box-shadow:
        0 0 0 3px rgba(79,172,254,0.15),
        0 0 12px rgba(79,172,254,0.4);
    flex-shrink: 0;
}

.sp-avatar {
    width: 56px;
    height: 56px;
    border-radius: 50%;
    background: linear-gradient(135deg, #1e3a8a, #0ea5e9);
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: 'Sora', sans-serif;
    font-weight: 800;
    font-size: 1.25rem;
    color: #ffffff;
    flex-shrink: 0;
    box-shadow:
        0 0 0 3px rgba(79,172,254,0.15),
        0 0 12px rgba(79,172,254,0.4);
    letter-spacing: -0.5px;
    text-shadow: 0 1px 4px rgba(0,0,0,0.5);
}

/* ── Info block ── */
.sp-info {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 0.2rem;
}

.sp-name {
    font-family: 'Sora', sans-serif;
    font-weight: 800;
    font-size: clamp(0.82rem, 1vw, 0.97rem);
    color: #ffffff;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    line-height: 1.2;
    letter-spacing: 0.3px;
    text-shadow: 0 0 20px rgba(255,255,255,0.15);
}

.sp-company {
    font-size: 0.78rem;
    font-weight: 700;
    color: var(--accent-amber);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    display: flex;
    align-items: center;
    gap: 4px;
    text-shadow: 0 0 10px rgba(255,182,72,0.3);
}

.sp-role {
    font-size: 0.68rem;
    color: rgba(186,210,250,0.85);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    letter-spacing: 0.2px;
}

/* ── Badges row ── */
.sp-badges {
    display: flex;
    align-items: center;
    gap: 5px;
    margin-top: 0.24rem;
    flex-wrap: nowrap;
}

.sp-badge-branch {
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.10);
    color: rgba(138,151,189,0.85);
    padding: 2px 8px;
    border-radius: 5px;
    font-size: 0.58rem;
    font-weight: 700;
    letter-spacing: 0.8px;
    text-transform: uppercase;
    white-space: nowrap;
    flex-shrink: 0;
}

/* CTC badge — vivid emerald solid pill with glow */
.sp-badge-pkg {
    padding: 3px 11px;
    border-radius: 999px;
    font-size: 0.65rem;
    font-weight: 800;
    white-space: nowrap;
    flex-shrink: 0;
    letter-spacing: 0.5px;
    background: linear-gradient(90deg, #059669, #10b981);
    border: 1px solid rgba(16,185,129,0.6);
    color: #ffffff;
    box-shadow:
        0 0 14px rgba(5,150,105,0.65),
        0 0 28px rgba(16,185,129,0.30),
        0 2px 6px rgba(0,0,0,0.35),
        inset 0 1px 0 rgba(255,255,255,0.18);
    position: relative;
    z-index: 0;
}

/* Remove the ::before pseudo we no longer need */
.sp-badge-pkg::before { display: none; }

/* ── Responsive ── */
@media (max-width: 1366px) and (orientation: landscape) {
    :root { --spotlight-h: 28vh; }
    .sp-label-panel { width: 11rem; }
    .sp-photo, .sp-avatar { width: 48px; height: 48px; font-size: 1.05rem; }
}
@media (orientation: portrait) {
    :root { --spotlight-h: 30vh; }
    .sp-label-panel { width: 6.5rem; }
    .spl-title { font-size: 0.5rem; letter-spacing: 1.5px; }
    .spl-icon { font-size: 1.6rem; }
    .sp-card { padding: 0.5rem 0.75rem; gap: 0.65rem; }
    .sp-photo, .sp-avatar { width: 44px; height: 44px; font-size: 1rem; }
}


/* ============================================================
   EXECUTIVE SEMINAR & OFFICIAL VISIT SLIDE
   Premium split-screen glassmorphism advertisement layout
   ============================================================ */

/* Outer wrapper: 40/60 split */
.exec-slide-wrap {
    display: grid;
    grid-template-columns: 40% 60%;
    gap: 0;
    height: 100%;
    overflow: hidden;
    border-radius: 1.5rem;
    border: 1px solid rgba(54, 226, 201, 0.3);
    box-shadow: 0 12px 40px rgba(54, 226, 201, 0.12);
    position: relative;
    background: linear-gradient(145deg, rgba(54, 226, 201, 0.15) 0%, rgba(15, 24, 48, 0.95) 60%);
    backdrop-filter: blur(16px);
}

.exec-slide-wrap.ad-client {
    background: linear-gradient(145deg, rgba(79, 140, 255, 0.18) 0%, rgba(15, 24, 48, 0.95) 60%);
    border: 1px solid rgba(79, 140, 255, 0.35);
    box-shadow: 0 12px 40px rgba(79, 140, 255, 0.12);
}

.exec-slide-wrap.ad-internal {
    background: linear-gradient(145deg, rgba(139, 92, 246, 0.18) 0%, rgba(15, 24, 48, 0.95) 60%);
    border: 1px solid rgba(139, 92, 246, 0.35);
    box-shadow: 0 12px 40px rgba(139, 92, 246, 0.12);
}

.exec-slide-wrap.ad-career {
    background: linear-gradient(145deg, rgba(255, 182, 72, 0.18) 0%, rgba(15, 24, 48, 0.95) 60%);
    border: 1px solid rgba(255, 182, 72, 0.35);
    box-shadow: 0 12px 40px rgba(255, 182, 72, 0.12);
}

.exec-slide-wrap.ad-general {
    background: linear-gradient(145deg, rgba(54, 226, 201, 0.15) 0%, rgba(15, 24, 48, 0.95) 60%);
    border: 1px solid rgba(54, 226, 201, 0.3);
    box-shadow: 0 12px 40px rgba(54, 226, 201, 0.12);
}

/* Animated top shimmer — warm white gleam */
.exec-slide-wrap::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    background: linear-gradient(90deg,
        transparent 0%,
        rgba(255,255,255,0.9) 30%,
        rgba(255,255,255,0.6) 60%,
        transparent 100%);
    background-size: 200% 100%;
    animation: exec-shimmer 3s linear infinite;
    z-index: 10;
    box-shadow: 0 0 14px rgba(255,255,255,0.6);
    border-radius: 1.5rem 1.5rem 0 0;
}

@keyframes exec-shimmer {
    0%   { background-position: 200% center; }
    100% { background-position: -200% center; }
}

/* LEFT: Executive Media Card — tightened padding so the ad image
   sits close to the frame with no large empty margins. */
.exec-media-side {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: clamp(0.5rem, 1vw, 1rem);
    background: rgba(0,0,0,0.18);
    border-right: 1px solid rgba(255,255,255,0.20);
    overflow: hidden;
    gap: 0.6rem;
}

/* Ambient glow behind media card */
.exec-media-side::before {
    content: '';
    position: absolute;
    top: 50%; left: 50%;
    transform: translate(-50%, -50%);
    width: 70%; height: 70%;
    border-radius: 50%;
    background: radial-gradient(circle,
        rgba(255,255,255,0.12) 0%,
        transparent 70%);
    filter: blur(40px);
    pointer-events: none;
    z-index: 0;
    animation: exec-glow-pulse 4s ease-in-out infinite;
}

@keyframes exec-glow-pulse {
    0%, 100% { opacity: 0.6; transform: translate(-50%, -50%) scale(1); }
    50%       { opacity: 1;   transform: translate(-50%, -50%) scale(1.15); }
}

/* Portrait frame — image now fills the frame edge-to-edge (object-fit: cover)
   with minimal internal padding, so no large empty margins appear. */
.exec-portrait-frame {
    position: relative;
    z-index: 2;
    width: 100%;
    flex: 1;
    max-height: calc(100% - 50px);
    border-radius: 20px;
    border: 1px solid rgba(255,255,255,0.35);
    box-shadow:
        0 0 0 4px rgba(255,255,255,0.10),
        0 0 30px rgba(255,255,255,0.15),
        0 20px 60px rgba(0, 0, 0, 0.5);
    overflow: hidden;
    background: rgba(0,0,0,0.30);
    transition: box-shadow 0.5s ease;
}

.exec-portrait-frame:hover {
    box-shadow:
        0 0 0 4px rgba(255,255,255,0.25),
        0 0 60px rgba(255,255,255,0.25),
        0 20px 60px rgba(0, 0, 0, 0.5);
}

.exec-portrait-frame img,
.exec-portrait-frame video {
    width: 100%; height: 100%;
    object-fit: cover;
    display: block;
    border-radius: 20px;
}

/* Media placeholder (no media) */
.exec-media-placeholder {
    width: 100%; height: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 1rem;
    background: rgba(0,0,0,0.15);
    border-radius: 20px;
}

.exec-media-placeholder .exec-icon {
    font-size: clamp(3rem, 8vw, 6rem);
    filter: drop-shadow(0 0 20px rgba(255,255,255,0.6));
    animation: exec-icon-float 3s ease-in-out infinite;
}

@keyframes exec-icon-float {
    0%, 100% { transform: translateY(0); }
    50%       { transform: translateY(-10px); }
}

/* Organisation logo badge below frame */
.exec-org-badge {
    position: relative;
    z-index: 2;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.35rem 1rem;
    border-radius: 999px;
    background: #ffffff;
    border: none;
    font-size: clamp(0.62rem, 0.8vw, 0.75rem);
    font-weight: 800;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    color: #1a1230;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 90%;
    box-shadow: 0 2px 10px rgba(0,0,0,0.25);
}

/* RIGHT: Event Details Panel */
.exec-content-side {
    display: flex;
    flex-direction: column;
    padding: clamp(1.75rem, 3.5vw, 3rem) clamp(1.5rem, 3vw, 2.5rem);
    gap: clamp(0.75rem, 1.5vw, 1.25rem);
    overflow: hidden;
    position: relative;
}

/* Decorative vertical accent line on left of content */
.exec-content-side::before {
    content: '';
    position: absolute;
    left: 0; top: 10%; bottom: 10%;
    width: 1px;
    background: linear-gradient(180deg,
        transparent 0%,
        rgba(255,255,255,0.5) 30%,
        rgba(255,255,255,0.7) 60%,
        transparent 100%);
    box-shadow: 0 0 8px rgba(255,255,255,0.3);
}

/* Category pill badge */
.exec-category-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: clamp(0.4rem, 0.7vw, 0.55rem) clamp(1rem, 1.5vw, 1.5rem);
    border-radius: 999px;
    font-size: clamp(0.68rem, 0.88vw, 0.82rem);
    font-weight: 800;
    letter-spacing: 2px;
    text-transform: uppercase;
    align-self: flex-start;
    white-space: nowrap;
    animation: exec-badge-glow 2.5s ease-in-out infinite;
}

/* White pill badges with dark accent text — bright celebratory style */
.exec-category-badge.badge-seminar,
.exec-category-badge.badge-cyan {
    background: #ffffff;
    border: none;
    color: #c73000;
    box-shadow: 0 4px 14px rgba(0,0,0,0.25);
}

.exec-category-badge.badge-visit,
.exec-category-badge.badge-gold {
    background: #ffffff;
    border: none;
    color: #5546cc;
    box-shadow: 0 4px 14px rgba(0,0,0,0.25);
}

.exec-category-badge.badge-lecture,
.exec-category-badge.badge-purple {
    background: #ffffff;
    border: none;
    color: #5546cc;
    box-shadow: 0 4px 14px rgba(0,0,0,0.25);
}

.exec-category-badge.badge-emerald {
    background: #ffffff;
    border: none;
    color: #065f46;
    box-shadow: 0 4px 14px rgba(0,0,0,0.25);
}

.exec-category-badge.badge-rose {
    background: #ffffff;
    border: none;
    color: #9b1239;
    box-shadow: 0 4px 14px rgba(0,0,0,0.25);
}

@keyframes exec-badge-glow {
    0%, 100% { box-shadow: 0 4px 14px rgba(0,0,0,0.20); }
    50%       { box-shadow: 0 6px 22px rgba(0,0,0,0.35); }
}

/* Event title headline */
.exec-event-title {
    font-family: 'Sora', sans-serif;
    font-size: clamp(1.4rem, 2.8vw, 2.4rem);
    font-weight: 800;
    color: #ffffff;
    line-height: 1.2;
    letter-spacing: -0.5px;
    text-shadow: 0 2px 20px rgba(0,0,0,0.5);
}

/* Speaker / Presenter card */
.exec-speaker-card {
    display: flex;
    align-items: center;
    gap: clamp(0.75rem, 1.2vw, 1rem);
    padding: clamp(0.75rem, 1.2vw, 1rem) clamp(1rem, 1.8vw, 1.5rem);
    border-radius: 1rem;
    background: rgba(255,255,255,0.18);
    border: 1px solid rgba(255,255,255,0.35);
    box-shadow: 0 4px 20px rgba(0,0,0,0.20);
    flex-shrink: 0;
}

.exec-speaker-avatar {
    width: clamp(2.5rem, 4vw, 3.5rem);
    height: clamp(2.5rem, 4vw, 3.5rem);
    border-radius: 50%;
    background: #ffffff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: 'Sora', sans-serif;
    font-weight: 800;
    font-size: clamp(0.9rem, 1.5vw, 1.2rem);
    color: #1a1230;
    flex-shrink: 0;
    box-shadow:
        0 0 0 3px rgba(255,255,255,0.25),
        0 0 15px rgba(0,0,0,0.2);
}

.exec-speaker-info { flex: 1; min-width: 0; }

.exec-speaker-name {
    font-family: 'Sora', sans-serif;
    font-weight: 800;
    font-size: clamp(0.85rem, 1.2vw, 1.1rem);
    color: #ffffff;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.exec-speaker-role {
    font-size: clamp(0.68rem, 0.85vw, 0.8rem);
    color: rgba(255,255,255,0.80);
    margin-top: 2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Event metadata glass badges grid */
.exec-meta-grid {
    display: flex;
    gap: clamp(0.5rem, 0.9vw, 0.75rem);
    flex-wrap: wrap;
    flex-shrink: 0;
}

.exec-meta-badge {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    padding: clamp(0.35rem, 0.6vw, 0.5rem) clamp(0.75rem, 1.2vw, 1.1rem);
    border-radius: 999px;
    background: #ffffff;
    border: none;
    font-size: clamp(0.68rem, 0.82vw, 0.8rem);
    font-weight: 700;
    color: #1a1230;
    white-space: nowrap;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.25);
    transition: box-shadow 0.3s ease;
}

.exec-meta-badge:hover {
    box-shadow: 0 4px 16px rgba(0,0,0,0.30);
}

.exec-meta-badge .exec-meta-icon {
    font-size: clamp(0.75rem, 0.95vw, 0.9rem);
}

.exec-meta-badge.badge-gold {
    background: #ffffff;
    color: #7c3400;
}

/* Brief description */
.exec-description {
    font-size: clamp(0.82rem, 1.05vw, 1rem);
    color: #CBD5E1;
    line-height: 1.7;
    flex: 1;
    overflow: hidden;
    display: -webkit-box;
    -webkit-line-clamp: 4;
    -webkit-box-orient: vertical;
    text-overflow: ellipsis;
}

/* Corner decoration for the exec slide */
.exec-corner-deco {
    position: absolute;
    bottom: -2rem;
    right: -2rem;
    width: clamp(8rem, 18vw, 14rem);
    height: clamp(8rem, 18vw, 14rem);
    border-radius: 50%;
    background: radial-gradient(circle,
        rgba(255,255,255,0.15) 0%,
        rgba(255,255,255,0.05) 50%,
        transparent 70%);
    filter: blur(30px);
    pointer-events: none;
}

/* Responsive portrait */
@media (orientation: portrait) {
    .exec-slide-wrap {
        grid-template-columns: 1fr;
        grid-template-rows: 45% 55%;
    }
    .exec-media-side {
        border-right: none;
        border-bottom: 1px solid rgba(255,255,255,0.20);
        padding: 0.5rem;
    }
    .exec-content-side {
        padding: 1rem 1.25rem;
    }
    .exec-event-title { font-size: clamp(1.1rem, 4vw, 1.6rem); }
}

@media (max-width: 900px) and (orientation: landscape) {
    .exec-slide-wrap {
        grid-template-columns: 35% 65%;
    }
    .exec-content-side { padding: 1.25rem 1.5rem; }
}
/* =========================================================
   DISPLAY BOARD - MOBILE PLACEMENT BANNER
   Samsung Galaxy S8+ : 360 x 740
   ========================================================= */

/* =========================================================
   PLACEMENT BANNER - MOBILE + TABLET
   Samsung S8+ + iPad
   ========================================================= */

@media (max-width: 1024px) {

    /* =====================================================
       1. MAIN BANNER
       Approximately 30% of screen height
       ===================================================== */

    .spotlight-footer {

        width: 100%;
        left: 0;
        right: 0;
        bottom: 0;

        height: 30vh;

        /* Keep it usable on smaller devices */
        min-height: 200px;

        /* Prevent it becoming ridiculously tall on iPad */
        max-height: 300px;

        display: flex;
        flex-direction: column;

        padding: 6px 8px 8px;

        box-sizing: border-box;

        overflow: hidden;

        background:
            radial-gradient(
                circle at 50% 0%,
                rgba(255, 166, 64, 0.18),
                transparent 55%
            ),
            linear-gradient(
                180deg,
                rgba(35, 29, 58, 0.98),
                rgba(14, 21, 45, 0.99)
            );

        border-top: 1px solid rgba(255, 177, 74, 0.55);

        box-shadow:
            0 -8px 30px rgba(255, 159, 67, 0.12),
            inset 0 1px 0 rgba(255, 255, 255, 0.06);
    }


    /* =====================================================
       2. CAREER SUCCESS STORIES HEADER
       ===================================================== */

    .sp-label-panel {

        width: 100%;

        height: 38px;
        min-height: 38px;

        display: flex;
        flex-direction: row;

        align-items: center;
        justify-content: center;

        gap: 7px;

        padding: 5px 8px;

        box-sizing: border-box;

        border-radius: 10px;

        background:
            linear-gradient(
                90deg,
                rgba(255, 173, 70, 0.10),
                rgba(255, 255, 255, 0.04),
                rgba(255, 173, 70, 0.10)
            );

        border: 1px solid rgba(255, 183, 83, 0.16);

        box-shadow:
            0 4px 15px rgba(0, 0, 0, 0.20);
    }


    .spl-icon {

        font-size: 16px;

        line-height: 1;
    }


    .spl-title {

        font-size: 9px;

        line-height: 1.05;

        font-weight: 800;

        text-transform: uppercase;

        letter-spacing: 0.8px;

        text-align: center;

        white-space: nowrap;
    }


   

    /* =====================================================
       3. SLIDER AREA
       ===================================================== */

    #placed-students-slider {

        width: 100%;

        height: calc(100% - 43px);

        margin-top: 5px;

        overflow: hidden;

        box-sizing: border-box;

        border-radius: 11px;
    }


    #placed-students-slider .slider-track {

        display: flex;

        align-items: stretch;

        gap: 16px;

        height: 100%;

        width: max-content;
    }


    /* =====================================================
       4. STUDENT CARD
       ===================================================== */

    #placed-students-slider .student-card {

        height: 100%;

        box-sizing: border-box;

        padding: 12px 13px;

        border-radius: 13px;

        overflow: hidden;

        /*
         * Remove yellow dotted/debug border
         */
        border: 1px solid rgba(72, 223, 255, 0.22) !important;

        border-style: solid !important;

        outline: none !important;

        /*
         * Clean card background
         */
        background:
            linear-gradient(
                145deg,
                rgba(24, 54, 76, 0.96),
                rgba(21, 30, 57, 0.98)
            );

        box-shadow:
            0 6px 20px rgba(0, 0, 0, 0.28),
            inset 0 1px 0 rgba(255, 255, 255, 0.06);

        position: relative;
    }


    /* Small premium cyan line on top of card */

    #placed-students-slider .student-card::before {

        content: '';

        position: absolute;

        top: 0;
        left: 0;
        right: 0;

        height: 2px;

        background: linear-gradient(
            90deg,
            #22d3ee,
            #60a5fa,
            #34d399
        );

        opacity: 0.8;
    }


    /* =====================================================
       5. STUDENT NAME
       ===================================================== */

    #placed-students-slider .student-name {

        font-size: 15px;

        line-height: 1.2;

        font-weight: 800;

        margin-bottom: 3px;
    }


    /* =====================================================
       6. COMPANY
       ===================================================== */

    #placed-students-slider .student-company {

        font-size: 10px;

        line-height: 1.2;

        opacity: 0.85;
    }


    /* =====================================================
       7. BADGES
       ===================================================== */

    .student-badges-row {

        display: flex;

        align-items: center;

        gap: 5px;

        margin-top: 7px;

        width: 100%;

        overflow: hidden;
    }


    .student-pill-badge {

        padding: 4px 8px;

        border-radius: 999px;

        font-size: 9px;

        line-height: 1;

        font-weight: 700;

        letter-spacing: 0.15px;

        white-space: nowrap;

        overflow: hidden;

        text-overflow: ellipsis;

        flex-shrink: 1;

        max-width: 48%;

        box-sizing: border-box;
    }


    /* Role */

    .student-pill-badge.role-badge {

        background:
            linear-gradient(
                90deg,
                #0891b2,
                #2563eb
            );

        color: #fff;

        border: none;

        box-shadow:
            0 3px 10px rgba(37, 99, 235, 0.28);
    }


    /* Package */

    .student-pill-badge.pkg-badge {

        background:
            linear-gradient(
                90deg,
                #059669,
                #10b981
            );

        color: #fff;

        border: none;

        box-shadow:
            0 3px 10px rgba(16, 185, 129, 0.25);
    }


    /* Branch */

    .student-pill-badge.branch-badge {

        background: rgba(255, 255, 255, 0.07);

        border: 1px solid rgba(255, 255, 255, 0.14);

        color: #cbd5e1;
    }


    /* =====================================================
       8. REMOVE ALL DEBUG / DASHED BORDER
       ===================================================== */

    #placed-students-slider .student-card,
    #placed-students-slider .student-card:hover,
    #placed-students-slider .student-card:focus,
    #placed-students-slider .student-card:active {

        outline: none !important;

        border-style: solid !important;

        border-width: 1px !important;
    }


    /* Prevent long content from stretching layout */

    #placed-students-slider .student-card * {

        box-sizing: border-box;

        max-width: 100%;
    }
}


/* =========================================================
   TABLET / iPAD
   601px - 1024px
   ========================================================= */

@media (min-width: 601px) and (max-width: 1024px) {

    /*
     * Header slightly bigger on iPad
     */
    .sp-label-panel {

        height: 42px;
        min-height: 42px;

    }

    .spl-title {
        font-size: 11px;
    }

   

    /*
     * More breathing room inside card
     */
    #placed-students-slider .student-card {

        padding: 14px 16px;

        border-radius: 14px;
    }


    /*
     * iPad card text
     */
    #placed-students-slider .student-name {

        font-size: 16px;
    }

    #placed-students-slider .student-company {

        font-size: 11px;
    }
}


/* =========================================================
   SAMSUNG S8+ / SMALL MOBILE
   0 - 600px
   ========================================================= */

@media (max-width: 600px) {

    .spl-title {
        font-size: 9px;
    }

   

    #placed-students-slider .student-card {

        padding: 10px 11px;

        border-radius: 11px;
    }

    #placed-students-slider .student-name {

        font-size: 14px;
    }

    #placed-students-slider .student-company {

        font-size: 10px;
    }
}
/* ============================================================
   PLACEMENT BANNER -- LED DISPLAY v2 VERTICAL CARD REDESIGN
   Primary target: iPad mini (601-1024px)
   Card layout: TOP 40% square image | BOTTOM 60% stacked info
   ============================================================ */

/* 1. Remove old 40px top margin */
.student-card { margin-top: 0 !important; }

/* 2. Footer padding */
.spotlight-footer {
    padding: 4px 6px 4px 0 !important;
    align-items: stretch !important;
}

/* 3. Desktop header */
.sp-label-panel { gap: 0.3rem !important; padding: 0 0.65rem !important; }
.spl-icon { font-size: 1.5rem !important; }
.spl-title { font-size: 0.65rem !important; letter-spacing: 1.5px !important; line-height: 1.4 !important; }

/* 4. Slider speed - 1.6x slower */
#placed-students-slider .slider-track {
    animation-duration: calc(var(--slider-duration, 30s) * 1.6) !important;
}

/* 5. Remove ALL debug/dashed/yellow borders globally */
.student-card,
.student-card:hover,
.student-card:focus,
.student-card:active,
.student-card:focus-within {
    outline: none !important;
    border-style: solid !important;
}

/* =====================================================================
   iPAD MINI (601px - 1024px) - PRIMARY TARGET
   VERTICAL CARD: 35% image (top, full-width square) | 65% info (bottom)
   Vertical/portrait display screen layout
   ===================================================================== */
@media (min-width: 601px) and (max-width: 1024px) {

    /* Banner — taller so bigger text fits comfortably */
    .spotlight-footer {
        height: 42vh !important;
        min-height: 260px !important;
        max-height: 380px !important;
        flex-direction: column !important;
        padding: 0 !important;
        overflow: hidden !important;
        align-items: stretch !important;
    }

    /* Header strip — taller for 16px text */
    .sp-label-panel {
        width: 100% !important;
        height: 44px !important;
        min-height: 44px !important;
        flex: 0 0 44px !important;
        flex-direction: row !important;
        justify-content: center !important;
        align-items: center !important;
        gap: 10px !important;
        padding: 0 14px !important;
        border-radius: 0 !important;
        border-right: none !important;
        border-bottom: 1px solid rgba(255,183,83,0.30) !important;
        background: linear-gradient(90deg, rgba(255,173,70,0.14), rgba(255,255,255,0.05), rgba(255,173,70,0.14)) !important;
        box-shadow: 0 3px 14px rgba(0,0,0,0.25) !important;
        position: relative !important;
        z-index: 2 !important;
    }
    .sp-label-panel::after { display: none !important; }
    .spl-icon { font-size: 20px !important; line-height: 1 !important; }
    .spl-title {
        font-size: 16px !important;
        letter-spacing: 1.2px !important;
        line-height: 1.2 !important;
        white-space: nowrap !important;
        font-weight: 900 !important;
    }

    /* Slider area — accounts for 44px header */
    #placed-students-slider {
        width: 100% !important;
        flex: 1 !important;
        height: calc(100% - 44px) !important;
        overflow: hidden !important;
        display: flex !important;
        align-items: stretch !important;
        padding: 6px 8px !important;
        box-sizing: border-box !important;
        margin: 0 !important;
    }

    #placed-students-slider .slider-track {
        display: flex !important;
        flex-direction: row !important;
        height: 100% !important;
        align-items: stretch !important;
        gap: 10px !important;
        width: max-content !important;
    }

    /* =================================================================
       HORIZONTAL STUDENT CARD — SIDE-BY-SIDE LAYOUT
       LEFT 35%: tall square photo  |  RIGHT 65%: stacked info (column)
       ================================================================= */
    .student-card {
        display: flex !important;
        flex-direction: row !important;       /* ← horizontal side-by-side */
        align-items: stretch !important;
        justify-content: flex-start !important;

        height: 100% !important;
        max-height: none !important;
        min-height: 0 !important;

        padding: 0 !important;
        gap: 0 !important;
        border-radius: 14px !important;
        overflow: hidden !important;
        flex-shrink: 0 !important;
        align-self: stretch !important;
        margin-top: 0 !important;
        box-sizing: border-box !important;

        background: linear-gradient(135deg, rgba(14,28,62,0.98) 0%, rgba(8,14,36,0.99) 100%) !important;
        border: 1px solid rgba(54,226,201,0.30) !important;
        border-style: solid !important;
        border-width: 1px !important;
        outline: none !important;

        box-shadow:
            0 8px 30px rgba(0,0,0,0.55),
            0 0 0 1px rgba(54,226,201,0.06),
            inset 0 1px 0 rgba(255,255,255,0.04) !important;
        position: relative !important;
        transition: border-color 0.3s ease, box-shadow 0.3s ease !important;
    }

    /* Rainbow shimmer accent — left vertical edge (matches horizontal layout) */
    .student-card::before {
        content: '' !important;
        position: absolute !important;
        top: 0 !important; bottom: 0 !important; left: 0 !important;
        width: 3px !important;
        height: 100% !important;
        background: linear-gradient(180deg, #22d3ee, #818cf8, #34d399, #fbbf24) !important;
        opacity: 0.9 !important;
        z-index: 5 !important;
        pointer-events: none !important;
        border-radius: 14px 0 0 14px !important;
    }

    .student-card:hover {
        border-color: rgba(54,226,201,0.5) !important;
        box-shadow:
            0 12px 40px rgba(0,0,0,0.65),
            0 0 20px rgba(54,226,201,0.12),
            inset 0 1px 0 rgba(255,255,255,0.06) !important;
    }
    .student-card:hover,
    .student-card:focus,
    .student-card:active,
    .student-card:focus-within {
        outline: none !important;
        border-style: solid !important;
        border-width: 1px !important;
    }

    /* ------- IMAGE: LEFT 35% — full card height, square-ish cover ------- */
    .student-avatar,
    .student-avatar-placeholder {
        /* Horizontal layout: image takes 35% of card WIDTH, full HEIGHT */
        width: 35% !important;
        flex: 0 0 35% !important;   /* fixed 35% in row flex */
        height: 100% !important;
        min-width: 0 !important;
        max-width: none !important;
        min-height: 0 !important;
        max-height: none !important;

        /* Left-side radius matches card: rounded only on left corners */
        border-radius: 13px 0 0 13px !important;
        border: none !important;
        box-shadow: none !important;
        flex-shrink: 0 !important;
        align-self: stretch !important;

        /* Cover crop — fills tall left column without distortion */
        object-fit: cover !important;
        object-position: center top !important;
        display: block !important;
    }

    /* Placeholder initial letter — fills left column with gradient */
    .student-avatar-placeholder {
        background: linear-gradient(160deg, #0c1f56 0%, #1d3a8a 50%, #0284c7 100%) !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        font-family: 'Sora', sans-serif !important;
        font-weight: 900 !important;
        font-size: 3.5rem !important;
        color: rgba(255,255,255,0.92) !important;
        text-shadow: 0 3px 16px rgba(0,0,0,0.65) !important;
        letter-spacing: -2px !important;
        box-shadow:
            inset 0 0 60px rgba(54,226,201,0.10),
            inset 4px 0 14px rgba(0,0,0,0.30) !important;
    }

    /* ------- INFO: RIGHT 65% — stacked vertically inside right column ------- */
    .student-details {
        flex: 1 1 0 !important;        /* takes all remaining width = 65% */
        display: flex !important;
        flex-direction: column !important;
        justify-content: center !important;  /* vertically centre the text block */
        align-items: flex-start !important;
        gap: 0 !important;
        padding: 14px 16px 12px 18px !important;
        min-width: 0 !important;
        overflow: hidden !important;
        box-sizing: border-box !important;
        position: relative !important;
        z-index: 2 !important;

        /* Subtle left separator line between image and text */
        border-left: 1px solid rgba(54,226,201,0.12) !important;
    }

    /* Name — 20px as requested */
    .student-name {
        font-family: 'Sora', sans-serif !important;
        font-size: 20px !important;
        font-weight: 900 !important;
        color: #ffffff !important;
        white-space: nowrap !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
        max-width: 100% !important;
        line-height: 1.2 !important;
        letter-spacing: -0.4px !important;
        margin-bottom: 5px !important;
        text-shadow: 0 2px 10px rgba(0,0,0,0.55) !important;
        display: block !important;
    }

    /* Company — 18px as requested */
    .student-company {
        font-size: 18px !important;
        font-weight: 700 !important;
        color: #fbbf24 !important;
        white-space: nowrap !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
        line-height: 1.3 !important;
        margin-bottom: 4px !important;
        display: flex !important;
        align-items: center !important;
        gap: 5px !important;
        text-shadow: 0 0 14px rgba(251,191,36,0.30) !important;
    }

    /* Badges row - horizontal wrap */
    .student-badges-row {
        display: flex !important;
        flex-direction: row !important;
        flex-wrap: wrap !important;
        align-items: flex-start !important;
        gap: 4px 5px !important;
        margin-top: 5px !important;
        width: 100% !important;
        overflow: hidden !important;
    }

    /* Badge base — 16px as requested (role, package, location) */
    .student-pill-badge {
        padding: 5px 13px !important;
        border-radius: 999px !important;
        font-size: 16px !important;
        font-weight: 700 !important;
        white-space: nowrap !important;
        flex-shrink: 0 !important;
        letter-spacing: 0.1px !important;
        display: inline-flex !important;
        align-items: center !important;
        gap: 5px !important;
        line-height: 1 !important;
    }

    .student-pill-badge.role-badge {
        background: linear-gradient(90deg, #0891b2, #2563eb) !important;
        color: #fff !important;
        border: none !important;
        box-shadow: 0 2px 8px rgba(37,99,235,0.35) !important;
    }

    .student-pill-badge.pkg-badge {
        background: linear-gradient(90deg, #059669, #10b981) !important;
        color: #fff !important;
        border: none !important;
        box-shadow: 0 2px 8px rgba(16,185,129,0.35) !important;
    }

    .student-pill-badge.branch-badge {
        background: rgba(255,255,255,0.08) !important;
        border: 1px solid rgba(255,255,255,0.18) !important;
        color: #cbd5e1 !important;
    }

} /* end iPad mini media query */

/* =====================================================================
   SAMSUNG GALAXY S8+ / PORTRAIT <= 600px
   ===================================================================== */
@media (max-width: 600px) {

    .spotlight-footer {
        height: 30vh !important;
        min-height: 185px !important;
        max-height: 260px !important;
        padding: 0 !important;
        flex-direction: column !important;
        overflow: hidden !important;
        align-items: stretch !important;
    }

    .sp-label-panel {
        width: 100% !important;
        height: 28px !important;
        min-height: 28px !important;
        flex: 0 0 28px !important;
        flex-direction: row !important;
        justify-content: center !important;
        align-items: center !important;
        gap: 6px !important;
        padding: 0 10px !important;
        border-radius: 0 !important;
        border-right: none !important;
        border-bottom: 1px solid rgba(255,183,83,0.22) !important;
        background: linear-gradient(90deg,rgba(255,173,70,0.10),rgba(255,255,255,0.04),rgba(255,173,70,0.10)) !important;
    }
    .sp-label-panel::after { display: none !important; }
    .spl-icon { font-size: 13px !important; }
    .spl-title { font-size: 9px !important; letter-spacing: 0.8px !important; white-space: nowrap !important; font-weight: 900 !important; }

    #placed-students-slider {
        width: 100% !important;
        flex: 1 !important;
        height: calc(100% - 28px) !important;
        overflow: hidden !important;
        align-items: stretch !important;
        padding: 5px 6px !important;
        box-sizing: border-box !important;
        margin: 0 !important;
    }
    #placed-students-slider .slider-track {
        height: 100% !important;
        align-items: stretch !important;
        gap: 8px !important;
    }

    /* Horizontal card — same row layout as iPad, adapted for small screen */
    .student-card {
        display: flex !important;
        flex-direction: row !important;
        align-items: stretch !important;
        height: 100% !important;
        max-height: none !important;
        min-height: 0 !important;
        padding: 0 !important;
        gap: 0 !important;
        border-radius: 12px !important;
        overflow: hidden !important;
        margin-top: 0 !important;
        align-self: stretch !important;
        background: linear-gradient(135deg,rgba(14,28,62,0.98),rgba(8,14,36,0.99)) !important;
        border: 1px solid rgba(54,226,201,0.28) !important;
        border-style: solid !important;
        outline: none !important;
        box-shadow: 0 6px 22px rgba(0,0,0,0.5),inset 0 1px 0 rgba(255,255,255,0.04) !important;
        position: relative !important;
    }
    /* Shimmer — left vertical edge */
    .student-card::before {
        content: '' !important;
        position: absolute !important;
        top: 0 !important; bottom: 0 !important; left: 0 !important;
        width: 3px !important; height: 100% !important;
        background: linear-gradient(180deg,#22d3ee,#818cf8,#34d399,#fbbf24) !important;
        opacity: 0.8 !important;
        z-index: 5 !important;
        border-radius: 12px 0 0 12px !important;
    }
    .student-card:hover,
    .student-card:focus,
    .student-card:active,
    .student-card:focus-within { outline: none !important; border-style: solid !important; }

    /* Left image: 35% width, full height */
    .student-avatar,
    .student-avatar-placeholder {
        width: 35% !important;
        flex: 0 0 35% !important;
        height: 100% !important;
        min-width: 0 !important;
        border-radius: 11px 0 0 11px !important;
        border: none !important;
        box-shadow: none !important;
        object-fit: cover !important;
        object-position: center top !important;
        display: block !important;
        align-self: stretch !important;
    }
    .student-avatar-placeholder {
        background: linear-gradient(160deg,#0c1f56,#1d3a8a,#0284c7) !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        font-size: 2rem !important;
        font-weight: 900 !important;
        color: rgba(255,255,255,0.9) !important;
        box-shadow: inset 4px 0 14px rgba(0,0,0,0.25) !important;
    }

    /* Right info: 65% — vertical stack, centred */
    .student-details {
        flex: 1 1 0 !important;
        display: flex !important;
        flex-direction: column !important;
        justify-content: center !important;
        gap: 0 !important;
        padding: 10px 12px 8px 14px !important;
        overflow: hidden !important;
        border-left: 1px solid rgba(54,226,201,0.10) !important;
        min-width: 0 !important;
        box-sizing: border-box !important;
    }

    .student-name { font-size: 14px !important; font-weight: 900 !important; color: #fff !important; white-space: nowrap !important; overflow: hidden !important; text-overflow: ellipsis !important; margin-bottom: 3px !important; line-height: 1.2 !important; display: block !important; }
    .student-company { font-size: 12px !important; font-weight: 700 !important; color: #fbbf24 !important; white-space: nowrap !important; overflow: hidden !important; text-overflow: ellipsis !important; margin-bottom: 2px !important; }

    .student-badges-row { display: flex !important; flex-wrap: wrap !important; gap: 3px 4px !important; margin-top: 4px !important; overflow: hidden !important; }
    .student-pill-badge { padding: 3px 8px !important; font-size: 9px !important; font-weight: 700 !important; border-radius: 999px !important; white-space: nowrap !important; flex-shrink: 0 !important; display: inline-flex !important; align-items: center !important; gap: 3px !important; }
    .student-pill-badge.role-badge { background: linear-gradient(90deg,#0891b2,#2563eb) !important; color: #fff !important; border: none !important; }
    .student-pill-badge.pkg-badge { background: linear-gradient(90deg,#059669,#10b981) !important; color: #fff !important; border: none !important; }
    .student-pill-badge.branch-badge { background: rgba(255,255,255,0.08) !important; border: 1px solid rgba(255,255,255,0.18) !important; color: #cbd5e1 !important; }
}
/* END LED PLACEMENT BANNER v2 */
</style>
</head>
<body>


<!-- ========== TOP BAR ========== -->
<div class="bg-orb-1"></div>
<div class="bg-orb-2"></div>
<div class="topbar">
    <div class="brand">
        <img src="..\images\logos\logo2.png" alt="SETTribe Logo" class="brand-logo">
        <div class="brand-text">
           
            <p>Live Operations Display</p>
        </div>
    </div>
    <div class="topbar-right">
        <div class="live-pill">
            <span class="live-dot"></span>
            <span>LIVE DATA</span>
        </div>
        <div class="clock">
            <div class="time" id="clockTime">--:--:--</div>
            <div class="date" id="clockDate">Loading...</div>
        </div>
    </div>
</div>

<!-- ========== SLIDE STAGE ========== -->
<div class="stage" id="stage"></div>

<!-- ========== PROGRESS + DOTS ========== -->
<div class="progress-track"><div class="progress-fill" id="progressFill"></div></div>
<div class="dots" id="dots"></div>

<script>
const DATA = <?php echo $dataJson; ?>;
</script>
<script>
/* ================================================================
   CLOCK
   ================================================================ */
function tickClock() {
    const now = new Date();
    document.getElementById('clockTime').textContent =
        now.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    document.getElementById('clockDate').textContent =
        now.toLocaleDateString('en-IN', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
}
tickClock();
setInterval(tickClock, 1000);

/* ================================================================
   HELPERS
   ================================================================ */
function initials(name) {
    if (!name) return '?';
    const parts = name.trim().split(/\s+/);
    return (parts[0][0] + (parts[1] ? parts[1][0] : '')).toUpperCase();
}

function donutSvg(percent, color, size = 220, stroke = 22) {
    const r = (size / 2) - stroke;
    const c = 2 * Math.PI * r;
    const offset = c - (percent / 100) * c;
    return `<svg width="${size}" height="${size}" viewBox="0 0 ${size} ${size}">
        <circle cx="${size/2}" cy="${size/2}" r="${r}" fill="none" stroke="rgba(255,255,255,0.06)" stroke-width="${stroke}"/>
        <circle cx="${size/2}" cy="${size/2}" r="${r}" fill="none" stroke="${color}" stroke-width="${stroke}"
            stroke-dasharray="${c}" stroke-dashoffset="${offset}" stroke-linecap="round"
            style="transition:stroke-dashoffset 1.4s cubic-bezier(.22,1,.36,1);"/>
    </svg>`;
}

/* ================================================================
   DATA
   ================================================================ */
const proj          = DATA.project_counts  || { running: 0, past: 0, total: 0 };
const intern        = DATA.intern_stats    || { total_interns: 0, online_count: 0, offline_count: 0 };
const branches      = DATA.branch_stats   || { kinwat: { cnt:0, online_cnt:0, offline_cnt:0 }, pune: { cnt:0, online_cnt:0, offline_cnt:0 } };
const activeProjects = DATA.active_projects || [];
const pastProjects   = DATA.past_projects   || [];
const clients        = DATA.clients         || [];
const activeSignupCount = DATA.active_signup_count || 0;
const adsMain = DATA.adsMain || [];
const adsHeader = DATA.adsHeader || [];
const adsFooter = DATA.adsFooter || [];
const announcements = DATA.announcements || [];
const placedStudents = DATA.placed_students || [];

function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

/* Helper to resolve advertisement media file paths correctly relative to pages/ */
function resolveAdMediaPath(filePath) {
    if (!filePath) return '';
    filePath = String(filePath).trim();
    if (!filePath) return '';
    if (filePath.startsWith('http://') || filePath.startsWith('https://')) return filePath;
    if (filePath.startsWith('uploads/')) return filePath;
    if (filePath.startsWith('../pages/')) return filePath.replace('../pages/', '');
    if (filePath.startsWith('pages/')) return filePath.replace('pages/', '');
    if (filePath.startsWith('../')) return filePath.replace('../', '');
    return filePath;
}

/* ================================================================
   VERTICAL AUTO-SCROLL BUILDER
   Wraps content list in a seamless looping container.
   ================================================================ */
function buildVScroll(innerHtml, itemCount, pixelsPerItem = 80, pauseMs = 0) {
    const totalH = itemCount * pixelsPerItem;
    const durationS = Math.max(12, totalH / 35); // fallback values
    return `
    <div class="vscroll-outer" id="vs-${Math.random().toString(36).substr(2,6)}"
         data-duration="${durationS}"
         data-dist="${totalH}">
        <div class="vscroll-track">
            <div class="vscroll-clone">${innerHtml}</div>
            <div class="vscroll-clone">${innerHtml}</div>
        </div>
    </div>`;
}

/* ================================================================
   PROJECT CARD HTML SNIPPET (used in list slides)
   ================================================================ */
function projectCardHtml(p) {
    return `<div class="project-card">
        <div class="pc-left">
            <div class="pc-name">${p.projectName}</div>
            <div class="pc-client">${p.client || 'Internal'} &middot; Leader: ${p.leader} &middot; ${p.team_count} member${p.team_count !== 1 ? 's' : ''}</div>
        </div>
        <div class="pc-right">${p.hostingType || 'Internal'}</div>
    </div>`;
}

/* ================================================================
   SLIDE 1: Overview
   ================================================================ */
function slideOverview() {
    const onlinePct = intern.total_interns > 0 ? Math.round((intern.online_count / intern.total_interns) * 100) : 0;
    const marqueeItems = activeProjects.concat(activeProjects).map(p =>
        `<div class="marquee-item"><span class="mdot"></span>${p.projectName}
         <span style="color:var(--text-muted);font-weight:400;">— ${p.client || 'Internal'}</span></div>`
    ).join('');

    return `
    <div class="slide-eyebrow" style="margin-bottom: 0.2rem;">Organization Pulse</div>
    <div class="slide-title" style="margin-bottom: 0.5rem;">SETTribe at a Glance</div>
    <div class="stat-grid" style="margin-bottom: 0.5rem; gap: 0.75rem;">
        <div class="stat-card accent-hero" style="padding: 0.75rem 1rem;">
            <div class="label">Running Projects</div>
            <div class="value">${proj.running ?? 0}</div>
            <div class="sub">Currently active</div>
        </div>
        <div class="stat-card accent-blue" style="padding: 0.75rem 1rem;">
            <div class="label">Past Projects</div>
            <div class="value">${proj.past ?? 0}</div>
            <div class="sub">Successfully delivered</div>
        </div>
        <div class="stat-card accent-amber" style="padding: 0.75rem 1rem;">
            <div class="label">Total Interns</div>
            <div class="value">${intern.total_interns ?? 0}</div>
            <div class="sub">${onlinePct}% currently online</div>
        </div>
        <div class="stat-card accent-rose" style="padding: 0.75rem 1rem;">
            <div class="label">Active Clients</div>
            <div class="value">${clients.length}</div>
            <div class="sub">Organizations we serve</div>
        </div>
    </div>
    <div class="panel full-h" style="padding: 0.75rem 1rem;">
        <h3 style="margin-bottom: 0.5rem; font-size: 0.9rem;">Active Project Roster</h3>
        <div class="marquee-wrap" style="padding: 0.3rem 0;">
            <div class="marquee">${marqueeItems}</div>
        </div>
        <div class="cols-3" style="margin-top: 0.5rem; flex: 1; min-height: 0; gap: 0.75rem;">
            <div class="branch-card" style="padding: 0.5rem; display:flex;flex-direction:column;align-items:center;justify-content:center;">
                <div class="bname" style="color:var(--accent-cyan);font-size:clamp(1.5rem,3vw,2.5rem);">${proj.total ?? 0}</div>
                <div class="btotal" style="font-size: 0.7rem; margin-bottom: 0;">Total Projects Ever</div>
            </div>
            <div class="branch-card" style="padding: 0.5rem; display:flex;flex-direction:column;align-items:center;justify-content:center;">
                <div class="bname" style="color:var(--accent-blue);font-size:clamp(1.5rem,3vw,2.5rem);">${branches.kinwat.cnt}</div>
                <div class="btotal" style="font-size: 0.7rem; margin-bottom: 0;">Kinwat Branch Interns</div>
            </div>
            <div class="branch-card" style="padding: 0.5rem; display:flex;flex-direction:column;align-items:center;justify-content:center;">
                <div class="bname" style="color:var(--accent-amber);font-size:clamp(1.5rem,3vw,2.5rem);">${branches.pune.cnt}</div>
                <div class="btotal" style="font-size: 0.7rem; margin-bottom: 0;">Pune Branch Interns</div>
            </div>
        </div>
    </div>`;
}

/* ================================================================
   SLIDE 2: Clients (vertical auto-scroll if many)
   ================================================================ */
function slideClients() {
    const clientCardsHtml = clients.map(c => `
        <div class="client-card">
            <div class="client-icon">${initials(c.client)}</div>
            <div class="cname">${c.client}</div>
            <div class="cmeta">${c.project_count} active project${c.project_count > 1 ? 's' : ''}</div>
        </div>
    `).join('');

    const needsScroll = clients.length > 8;

    return `
    <div class="slide-eyebrow">Trusted Partnerships</div>
    <div class="slide-title">Our Clients</div>
    <div class="panel full-h">
        <h3>Clients with Active Engagements</h3>
        ${needsScroll
            ? buildVScroll(`<div class="client-grid">${clientCardsHtml}</div>`, clients.length, 90)
            : `<div class="client-grid" style="overflow-y:auto;flex:1;">${clientCardsHtml}</div>`
        }
    </div>`;
}

/* ================================================================
   SLIDE 3: Intern Stats
   ================================================================ */
function slideInternStats() {
    const total = intern.total_interns || 1;
    const onlinePct = Math.round((intern.online_count / total) * 100);
    return `
    <div class="slide-eyebrow">Workforce Status</div>
    <div class="slide-title">Intern Activity Overview</div>
    <div class="panel full-h">
        <h3>Online and Offline Distribution</h3>
        <div class="chart-wrap">
            <div class="donut">
                ${donutSvg(onlinePct, 'var(--accent-cyan)')}
                <div class="donut-center">
                    <div class="big">${intern.total_interns}</div>
                    <div class="small">Total</div>
                </div>
            </div>
            <div class="legend">
                <div class="legend-item">
                    <div class="legend-dot" style="background:var(--accent-cyan);"></div>
                    <div class="lbl">Online Now</div>
                    <div class="val" style="color:var(--accent-cyan);">${intern.online_count}</div>
                </div>
                <div class="legend-item">
                    <div class="legend-dot" style="background:rgba(255,255,255,0.15);"></div>
                    <div class="lbl">Offline</div>
                    <div class="val" style="color:var(--text-muted);">${intern.offline_count}</div>
                </div>
                <div class="legend-item">
                    <div class="legend-dot" style="background:var(--accent-amber);"></div>
                    <div class="lbl">Online Rate</div>
                    <div class="val" style="color:var(--accent-amber);">${onlinePct}%</div>
                </div>
            </div>
        </div>
    </div>`;
}

/* ================================================================
   SLIDE 4: Branch Stats
   ================================================================ */
function slideBranchStats() {
    const k = branches.kinwat, p = branches.pune;
    const kMax = Math.max(k.cnt, 1), pMax = Math.max(p.cnt, 1);
    return `
    <div class="slide-eyebrow">Branch Network</div>
    <div class="slide-title">Kinwat and Pune — Intern Distribution</div>
    <div class="panel full-h">
        <h3>Branch-wise Online / Offline Breakdown</h3>
        <div class="branch-stats-row" style="display:flex;gap:var(--gap);flex:1;align-items:stretch;min-height:0;">
            <div class="branch-card" style="display:flex;flex-direction:column;justify-content:center;">
                <div class="bname" style="color:var(--accent-cyan);">Kinwat Branch</div>
                <div class="btotal">${k.cnt} total intern${k.cnt !== 1 ? 's' : ''}</div>
                <div class="bar-row">
                    <div class="bar-label">Online</div>
                    <div class="bar-track"><div class="bar-fill online" style="width:${(k.online_cnt/kMax)*100}%;"></div></div>
                    <div class="bar-num">${k.online_cnt}</div>
                </div>
                <div class="bar-row">
                    <div class="bar-label">Offline</div>
                    <div class="bar-track"><div class="bar-fill offline" style="width:${(k.offline_cnt/kMax)*100}%;"></div></div>
                    <div class="bar-num">${k.offline_cnt}</div>
                </div>
            </div>
            <div class="branch-card" style="display:flex;flex-direction:column;justify-content:center;">
                <div class="bname" style="color:var(--accent-amber);">Pune Branch</div>
                <div class="btotal">${p.cnt} total intern${p.cnt !== 1 ? 's' : ''}</div>
                <div class="bar-row">
                    <div class="bar-label">Online</div>
                    <div class="bar-track"><div class="bar-fill online" style="width:${(p.online_cnt/pMax)*100}%;"></div></div>
                    <div class="bar-num">${p.online_cnt}</div>
                </div>
                <div class="bar-row">
                    <div class="bar-label">Offline</div>
                    <div class="bar-track"><div class="bar-fill offline" style="width:${(p.offline_cnt/pMax)*100}%;"></div></div>
                    <div class="bar-num">${p.offline_cnt}</div>
                </div>
            </div>
            <div class="branch-card" style="text-align:center;justify-content:center;display:flex;flex-direction:column;align-items:center;">
                <div class="bname" style="font-size:clamp(2.5rem,7vw,5rem);color:var(--accent-rose);line-height:1;">${activeSignupCount}</div>
                <div class="btotal" style="margin-bottom:1.5rem;">Combined Branch Strength</div>
                <div style="font-size:clamp(0.75rem,0.95vw,0.9rem);color:var(--text-muted);line-height:2;">
                    Total Online: <strong style="color:var(--accent-cyan);">${k.online_cnt + p.online_cnt}</strong><br>
                    Total Offline: <strong style="color:var(--text-primary);">${k.offline_cnt + p.offline_cnt}</strong>
                </div>
            </div>
        </div>
    </div>`;
}

/* ================================================================
   SLIDE 5: Active Projects List (vertical auto-scroll)
   ================================================================ */
function slideActiveList() {
    const cardsHtml = `<div class="project-list">${activeProjects.map(projectCardHtml).join('')}</div>`;
    return `
    <div class="slide-eyebrow">Current Workload</div>
    <div class="slide-title">All Active Projects (${activeProjects.length})</div>
    <div class="panel full-h">
        <h3>Running Projects Overview</h3>
        ${buildVScroll(cardsHtml, activeProjects.length, 85)}
    </div>`;
}

/* ================================================================
   SLIDE 6+: Project Spotlight (one per active project)
   ================================================================ */
function slideProjectSpotlight(p) {
    const leaderInitial = initials(p.leader);
    const ownerInitial  = initials(p.owner);
    const team = p.team || [];

    const rightPanel = `
    <div class="panel spotlight-counter" style="display:flex;flex-direction:column;gap:clamp(0.6rem,1.2vw,1rem);">

        <!-- Count hero card -->
        <div style="
            flex:1; min-height: clamp(7rem, 14vw, 11rem);
            background:rgba(54,226,201,0.05);
            border:1px solid rgba(54,226,201,0.2);
            border-radius:1.1rem;
            display:flex; flex-direction:column;
            align-items:center; justify-content:center;
            padding:1rem; gap:0.4rem;
            position:relative; overflow:hidden;">
            <div style="position:absolute;top:-50px;right:-50px;width:200px;height:200px;border-radius:50%;
                background:radial-gradient(circle,rgba(54,226,201,0.1),transparent 70%);pointer-events:none;"></div>

            <div style="
                font-family:'Sora',sans-serif;
                font-size:clamp(2.6rem,8vw,5.5rem);
                font-weight:800; line-height:1;
                color:var(--accent-cyan); letter-spacing:-2px;">
                ${p.team_count}
            </div>
            <div style="font-size:clamp(0.6rem,0.8vw,0.72rem);letter-spacing:2px;text-transform:uppercase;color:var(--text-muted);text-align:center;">
                Team Member${p.team_count !== 1 ? 's' : ''} Allocated
            </div>
        </div>

        <!-- Mini stats: ID + Priority side by side -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:clamp(0.5rem,0.9vw,0.75rem);">
            <div style="background:rgba(79,140,255,0.06);border:1px solid rgba(79,140,255,0.18);border-radius:0.875rem;padding:clamp(0.6rem,1.2vw,0.9rem) clamp(0.75rem,1.3vw,1rem);">
                <div style="font-size:clamp(0.58rem,0.72vw,0.68rem);letter-spacing:1.5px;text-transform:uppercase;color:var(--text-muted);margin-bottom:4px;">Project ID</div>
                <div style="font-family:'Sora',sans-serif;font-size:clamp(1rem,2vw,1.4rem);font-weight:800;color:var(--accent-blue);">#${p.projectId}</div>
            </div>
            <div style="background:rgba(255,182,72,0.06);border:1px solid rgba(255,182,72,0.18);border-radius:0.875rem;padding:clamp(0.6rem,1.2vw,0.9rem) clamp(0.75rem,1.3vw,1rem);">
                <div style="font-size:clamp(0.58rem,0.72vw,0.68rem);letter-spacing:1.5px;text-transform:uppercase;color:var(--text-muted);margin-bottom:4px;">Priority</div>
                <div style="font-family:'Sora',sans-serif;font-size:clamp(1rem,2vw,1.4rem);font-weight:800;color:var(--accent-amber);">${p.priority || '—'}</div>
            </div>
        </div>

        <!-- Hosting type -->
        <div style="background:rgba(255,255,255,0.03);border:1px solid var(--line);border-radius:0.875rem;padding:clamp(0.6rem,1.2vw,0.9rem) clamp(0.75rem,1.3vw,1rem);display:flex;align-items:center;gap:0.75rem;">
            <div style="width:clamp(1.8rem,2.8vw,2.2rem);height:clamp(1.8rem,2.8vw,2.2rem);border-radius:0.6rem;background:rgba(54,226,201,0.1);border:1px solid rgba(54,226,201,0.22);display:flex;align-items:center;justify-content:center;font-size:clamp(0.8rem,1.2vw,1rem);flex-shrink:0;">🖥️</div>
            <div>
                <div style="font-size:clamp(0.58rem,0.72vw,0.68rem);letter-spacing:1.5px;text-transform:uppercase;color:var(--text-muted);margin-bottom:2px;">Hosting</div>
                <div style="font-size:clamp(0.8rem,1.1vw,0.95rem);font-weight:700;">${p.hostingType || 'Internal'}</div>
            </div>
        </div>

        <!-- Deadline (always shown; fallback if none) -->
        <div style="background:rgba(255,182,72,0.06);border:1px solid rgba(255,182,72,0.2);border-radius:0.875rem;padding:clamp(0.6rem,1.2vw,0.9rem) clamp(0.75rem,1.3vw,1rem);display:flex;align-items:center;gap:0.75rem;">
            <div style="width:clamp(1.8rem,2.8vw,2.2rem);height:clamp(1.8rem,2.8vw,2.2rem);border-radius:0.6rem;background:rgba(255,182,72,0.12);border:1px solid rgba(255,182,72,0.28);display:flex;align-items:center;justify-content:center;font-size:clamp(0.8rem,1.2vw,1rem);flex-shrink:0;">📅</div>
            <div>
                <div style="font-size:clamp(0.58rem,0.72vw,0.68rem);letter-spacing:1.5px;text-transform:uppercase;color:var(--accent-amber);margin-bottom:2px;">Deadline</div>
                <div style="font-size:clamp(0.8rem,1.1vw,0.95rem);font-weight:700;">
                    ${p.deadLine
                        ? new Date(p.deadLine).toLocaleDateString('en-IN', { day:'numeric', month:'short', year:'numeric' })
                        : 'Not set'}
                </div>
            </div>
        </div>

    </div>`;

    return `
    <div class="slide-eyebrow">Project Spotlight</div>
    <div class="slide-title">${p.projectName}</div>
    <div class="cols-2 spotlight-cols" style="flex:1;min-height:0;">

        <!-- LEFT: project info -->
        <div class="panel" style="overflow:hidden;display:flex;flex-direction:column;gap:clamp(0.6rem,1.2vw,1rem);">
            <div>
                <div class="pclient">Client: <strong style="color:var(--text-primary);">${p.client || 'Internal Project'}</strong></div>
                <div class="tag-row">
                    <span class="tag">Project ID #${p.projectId}</span>
                    ${p.hostingType ? `<span class="tag hosting">${p.hostingType} Hosted</span>` : ''}
                    ${p.priority    ? `<span class="tag priority">Priority ${p.priority}</span>` : ''}
                    <span class="tag running">● Running</span>
                </div>
            </div>
            <div class="role-block">
                <div class="role-label">Project Owner</div>
                <div class="person">
                    <div class="avatar">${ownerInitial}</div>
                    <div class="pinfo">
                        <div class="pname-small">${p.owner}</div>
                        <div class="prole">Project Owner</div>
                    </div>
                </div>
            </div>
            <div class="role-block">
                <div class="role-label">Project Leader</div>
                <div class="person">
                    <div class="avatar" ${p.leader==='Unassigned' ? 'style="background:linear-gradient(135deg,#8a97bd,#4a5270);"' : ''}>${leaderInitial}</div>
                    <div class="pinfo">
                        <div class="pname-small">${p.leader}</div>
                        <div class="prole">${team.length===0 && p.leader!=='Unassigned' ? 'Sole Allocated Member' : 'Team Lead'}</div>
                    </div>
                </div>
            </div>
            ${team.length > 0 ? `
            <div class="role-block" style="flex:1;overflow:hidden;">
                <div class="role-label">Team Members (${team.length})</div>
                <div class="team-grid" style="overflow-y:auto;max-height:100%;">
                    ${team.map(t => `
                        <div class="team-chip">
                            <div class="avatar">${initials(t.fullName)}</div>
                            ${t.fullName}
                        </div>
                    `).join('')}
                </div>
            </div>` : `
            <div style="flex:1;display:flex;align-items:center;justify-content:center;">
                <div style="text-align:center;color:var(--text-muted);">
                    <div style="font-size:clamp(1.5rem,3vw,2.2rem);margin-bottom:0.5rem;opacity:0.4;">👤</div>
                    <div style="font-size:clamp(0.7rem,0.9vw,0.85rem);">No team members allocated yet</div>
                </div>
            </div>`}
        </div>

        <!-- RIGHT: stats panel -->
        ${rightPanel}
    </div>`;
}
/* ================================================================
   SLIDE LAST: Past Projects (vertical auto-scroll)
   ================================================================ */
function slidePastProjects() {
    const cardsHtml = `<div class="project-list">${pastProjects.map(p => `
        <div class="project-card">
            <div class="pc-left">
                <div class="pc-name">${p.projectName}</div>
                <div class="pc-client">${p.client || 'Internal'}</div>
            </div>
            <div class="pc-right">${p.modificationDate ? new Date(p.modificationDate).toLocaleDateString('en-IN', { day:'numeric', month:'short', year:'numeric' }) : ''}</div>
        </div>
    `).join('')}</div>`;

    return `
    <div class="slide-eyebrow">Track Record</div>
    <div class="slide-title">Completed Projects</div>
    <div class="panel full-h">
        <h3>Recently Delivered</h3>
        ${buildVScroll(cardsHtml, pastProjects.length, 85)}
    </div>`;
}

/* ================================================================
   SLIDE: Advertisements (Premium Bento Collage) - Main Content
   ================================================================ */
function slideAdvertisementsGroup(groupItems) {
    if (!groupItems || groupItems.length === 0) return '';
    
    let mediaHtml = '';
    
    let gridCols = 'repeat(3, 1fr)';
    let rowHeight = 'minmax(250px, 35vh)';
    if (groupItems.length === 1) {
        gridCols = '1fr';
        rowHeight = 'minmax(450px, 65vh)';
    }
    else if (groupItems.length === 2 || groupItems.length === 4) {
        gridCols = 'repeat(2, 1fr)';
        rowHeight = 'minmax(350px, 45vh)';
    }

    groupItems.forEach((ad, idx) => {
        let mediaPath = resolveAdMediaPath(ad.file_path);
        let mediaTag = '';
        if (mediaPath) {
            mediaTag = ad.media_type === 'image' 
                ? `<img src="${mediaPath}" alt="Ad" style="width: 100%; height: 100%; object-fit: contain; border-radius: 12px; display: block; margin: auto;">`
                : `<video src="${mediaPath}" style="width: 100%; height: 100%; object-fit: contain; border-radius: 12px; display: block; margin: auto;" autoplay loop muted></video>`;
        } else {
            mediaTag = `<div style="padding: 2rem; text-align: center; color: #fff; width: 100%; height: 100%; display: flex; flex-direction: column; align-items: center; justify-content: center;">
                <div style="font-size: 3rem; margin-bottom: 0.5rem;">📢</div>
                <div style="font-family: 'Sora', sans-serif; font-weight: 800; font-size: 1.5rem; color: #00f2fe; margin-bottom: 0.5rem;">${escapeHtml(ad.title)}</div>
                ${ad.other_info ? `<div style="font-size: 1rem; color: #cbd5e1; line-height: 1.6; white-space: pre-wrap;">${escapeHtml(ad.other_info)}</div>` : ''}
            </div>`;
        }
            
        let overlay = (mediaPath && (ad.title || ad.other_info)) ? `
            <div style="position: absolute; bottom: 0; left: 0; right: 0; padding: 20px; background: linear-gradient(to top, rgba(7, 11, 22, 0.98) 10%, rgba(7, 11, 22, 0.6) 70%, transparent); border-bottom-left-radius: 16px; border-bottom-right-radius: 16px; backdrop-filter: blur(4px);">
                ${ad.title ? `<div style="font-family: 'Sora', sans-serif; font-weight: 700; font-size: 1.25rem; color: #fff; text-shadow: 0 2px 10px rgba(0,0,0,1); margin-bottom: 6px;">${escapeHtml(ad.title)}</div>` : ''}
                ${ad.other_info ? `<div style="font-size: 0.95rem; color: rgba(255,255,255,0.9); text-shadow: 0 2px 5px rgba(0,0,0,1); white-space: pre-wrap;">${escapeHtml(ad.other_info)}</div>` : ''}
            </div>` : '';

        let gridSpan = 'grid-column: span 1; grid-row: span 1;';
        if (groupItems.length === 3 && idx === 0) {
            gridSpan = 'grid-column: span 2; grid-row: span 2;';
        } else if (groupItems.length > 4 && idx % 5 === 0) {
            gridSpan = 'grid-column: span 2; grid-row: span 2;';
        }

        mediaHtml += `
        <div class="ad-card-premium" style="position: relative; background: rgba(0,0,0,0.4); border-radius: 16px; border: 1px solid rgba(255,255,255,0.08); overflow: hidden; box-shadow: 0 15px 40px rgba(0,0,0,0.6), inset 0 0 25px rgba(255,255,255,0.03); display: flex; align-items: center; justify-content: center; ${gridSpan} transition: transform 0.4s ease, box-shadow 0.4s ease;">
            ${mediaTag}
            ${overlay}
        </div>`;
    });

    let bentoGridHtml = `
        <style>
            .ad-card-premium:hover {
                transform: translateY(-5px) scale(1.01);
                box-shadow: 0 20px 50px rgba(54, 226, 201, 0.2), inset 0 0 30px rgba(54, 226, 201, 0.05) !important;
                border-color: rgba(54, 226, 201, 0.3) !important;
            }
        </style>
        <div style="display: grid; grid-template-columns: ${gridCols}; grid-auto-rows: ${rowHeight}; grid-auto-flow: dense; gap: 20px; padding: 10px 0 20px 0;">
            ${mediaHtml}
        </div>
    `;

    let needsScroll = groupItems.length > 3;
    let contentWrapper = needsScroll 
        ? buildVScroll(bentoGridHtml, Math.ceil(groupItems.length / 2), 400)
        : `<div style="height:100%; overflow:hidden;">${bentoGridHtml}</div>`;

    return `
    <div class="slide-eyebrow">Advertisement</div>
    <div class="slide-title" style="display:none;"></div>
    <div class="ad-slide-marker panel full-h" style="padding: 0; background: transparent; border: none; box-shadow: none;">
        <div style="flex: 1; overflow: hidden; position: relative;">
            ${contentWrapper}
        </div>
    </div>`;
}

/* ================================================================
   SLIDE: HR Announcements
   ================================================================ */
function slideAnnouncements() {
    if (!announcements || announcements.length === 0) return '';

    let cardsHtml = announcements.map(a => {
        let pColor  = a.priority === 'urgent' ? '#ff6b8b' : (a.priority === 'important' ? '#ffb648' : '#36e2c9');
        let pBg     = a.priority === 'urgent' ? 'rgba(255,107,139,0.15)' : (a.priority === 'important' ? 'rgba(255,182,72,0.15)' : 'rgba(54,226,201,0.12)');
        let pBorder = a.priority === 'urgent' ? 'rgba(255,107,139,0.4)' : (a.priority === 'important' ? 'rgba(255,182,72,0.4)' : 'rgba(54,226,201,0.3)');
        let pBadge  = a.priority ? a.priority.toUpperCase() : 'NORMAL';
        let pIcon   = a.priority === 'urgent' ? '⚡' : (a.priority === 'important' ? '⚠️' : '📢');

        let d = new Date(a.created_date);
        let dateStr = d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });

        return `
        <div style="background: linear-gradient(145deg, rgba(15,24,48,0.85), rgba(19,31,62,0.9)); border: 1px solid ${pBorder}; border-radius: 1.25rem; padding: 1.5rem; display: flex; flex-direction: column; gap: 0.875rem; box-shadow: 0 10px 30px rgba(0,0,0,0.4); position: relative; overflow: hidden; backdrop-filter: blur(10px);">
            <div style="position: absolute; top:0; left:0; width: 5px; height: 100%; background: ${pColor};"></div>
            <div style="display: flex; align-items: center; justify-content: space-between;">
                <span style="background: ${pBg}; color: ${pColor}; border: 1px solid ${pBorder}; padding: 3px 12px; border-radius: 999px; font-size: 0.75rem; font-weight: 800; letter-spacing: 1px;">
                    ${pIcon} ${pBadge}
                </span>
                <span style="font-size: 0.75rem; color: var(--text-muted); font-family: 'JetBrains Mono', monospace;">
                    ${dateStr}
                </span>
            </div>
            <h3 style="font-family: 'Sora', sans-serif; font-weight: 800; font-size: 1.25rem; color: var(--text-primary); margin: 0; line-height: 1.3;">
                ${escapeHtml(a.title)}
            </h3>
            <p style="font-size: 0.9rem; color: #c8d0e7; line-height: 1.6; margin: 0; white-space: pre-wrap; display: -webkit-box; -webkit-line-clamp: 4; -webkit-box-orient: vertical; overflow: hidden;">
                ${escapeHtml(a.description || '')}
            </p>
        </div>`;
    }).join('');

    let needsScroll = announcements.length > 4;
    let gridCols = announcements.length === 1 ? '1fr' : 'repeat(auto-fit, minmax(340px, 1fr))';

    let contentInner = `
        <div style="display: grid; grid-template-columns: ${gridCols}; gap: 1.25rem; padding: 0.5rem 0 1.5rem 0;">
            ${cardsHtml}
        </div>`;

    let contentWrapper = needsScroll
        ? buildVScroll(contentInner, Math.ceil(announcements.length / 2), 450)
        : `<div style="height:100%; overflow:hidden;">${contentInner}</div>`;

    return `
    <div class="slide-eyebrow">Official Communications</div>
    <div class="slide-title">HR Announcements &amp; Notices</div>
    <div class="panel full-h" style="padding: 0; background: transparent; border: none; box-shadow: none;">
        <div style="flex: 1; overflow: hidden; position: relative; margin-top: 10px;">
            ${contentWrapper}
        </div>
    </div>`;
}

/* ================================================================
   SLIDE: Placed Students & Success Stories
   ================================================================ */
function slidePlacedStudents() {
    if (!placedStudents || placedStudents.length === 0) return '';

    let cardsHtml = placedStudents.map(s => {
        let photoHtml = s.photo_path
            ? `<img src="../pages/${escapeHtml(s.photo_path)}" style="width: 72px; height: 72px; border-radius: 50%; object-fit: cover; border: 3px solid var(--accent-cyan); box-shadow: 0 0 15px rgba(54,226,201,0.3); flex-shrink: 0;">`
            : `<div style="width: 72px; height: 72px; border-radius: 50%; background: linear-gradient(135deg, var(--accent-blue), var(--accent-cyan)); display: flex; align-items: center; justify-content: center; color: #fff; font-size: 1.75rem; font-weight: 800; font-family: 'Sora', sans-serif; box-shadow: 0 0 15px rgba(79,140,255,0.3); flex-shrink: 0;">${(s.student_name || '?')[0].toUpperCase()}</div>`;

        let pkgBadge = s.package_ctc
            ? `<span style="background: linear-gradient(135deg, rgba(54,226,201,0.15), rgba(79,140,255,0.15)); border: 1px solid rgba(54,226,201,0.4); color: var(--accent-cyan); padding: 4px 14px; border-radius: 999px; font-weight: 800; font-size: 0.85rem; font-family: 'Sora', sans-serif;">💰 ${escapeHtml(s.package_ctc)}</span>`
            : '';

        let branchBadge = s.branch
            ? `<span style="background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.1); color: var(--text-muted); padding: 2px 10px; border-radius: 6px; font-size: 0.75rem;">${escapeHtml(s.branch)}</span>`
            : '';

        return `
        <div style="background: linear-gradient(145deg, rgba(15,24,48,0.85), rgba(19,31,62,0.9)); border: 1px solid rgba(255,255,255,0.09); border-radius: 1.25rem; padding: 1.5rem; display: flex; flex-direction: column; justify-content: space-between; gap: 1rem; box-shadow: 0 10px 30px rgba(0,0,0,0.4); backdrop-filter: blur(10px);">
            <div style="display: flex; align-items: center; gap: 1.25rem;">
                ${photoHtml}
                <div style="flex: 1; min-width: 0;">
                    <h3 style="font-family: 'Sora', sans-serif; font-weight: 800; font-size: 1.2rem; color: var(--text-primary); margin: 0 0 4px 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                        ${escapeHtml(s.student_name)}
                    </h3>
                    <div style="font-size: 0.9rem; font-weight: 700; color: var(--accent-amber); display: flex; align-items: center; gap: 6px;">
                        🏢 ${escapeHtml(s.company_name)}
                    </div>
                    ${s.role_offered ? `<div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 2px;">💼 ${escapeHtml(s.role_offered)}</div>` : ''}
                </div>
            </div>
            <div style="display: flex; align-items: center; justify-content: space-between; border-top: 1px solid rgba(255,255,255,0.06); padding-top: 0.875rem; margin-top: 0.25rem;">
                ${branchBadge}
                ${pkgBadge}
            </div>
        </div>`;
    }).join('');

    let needsScroll = placedStudents.length > 6;

    let contentInner = `
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1.25rem; padding: 0.5rem 0 1.5rem 0;">
            ${cardsHtml}
        </div>`;

    let contentWrapper = needsScroll
        ? buildVScroll(contentInner, Math.ceil(placedStudents.length / 3), 450)
        : `<div style="height:100%; overflow:hidden;">${contentInner}</div>`;

    return `
    <div class="slide-eyebrow">Career Milestones</div>
    <div class="slide-title">Placed Students &amp; Success Stories</div>
    <div class="panel full-h" style="padding: 0; background: transparent; border: none; box-shadow: none;">
        <div style="flex: 1; overflow: hidden; position: relative; margin-top: 10px;">
            ${contentWrapper}
        </div>
    </div>`;
}

/* ================================================================
   HIGH-END DIGITAL SIGNAGE ADVERTISEMENT CARD RENDERER
   Renders advertisements as premium split-screen signage cards.
   Full dynamic data display: Title, Description, Dates/Times,
   Venue/Location, Speaker/Host details, Badges, and Status.
   Zero distorted images & zero dark bottom overlays!
   ================================================================ */
function parseAdMeta(ad) {
    const titleRaw = (ad.title || '').trim();
    const infoRaw  = (ad.other_info || ad.description || '').trim();

    // 1. Detect Category & Tag
    let tag = '';
    let cleanTitle = titleRaw;
    let icon = '📢';
    let label = 'OFFICIAL ADVERTISEMENT';
    let cls = 'badge-cyan';

    const matchTag = titleRaw.match(/^\[([^\]]+)\]\s*(.*)$/);
    if (matchTag) {
        tag = matchTag[1].toUpperCase().trim();
        cleanTitle = matchTag[2].trim();
    }

    const tUpper = (tag || cleanTitle || infoRaw).toUpperCase();
    if (tUpper.includes('SEMINAR') || tUpper.includes('GUEST LECTURE') || tUpper.includes('LECTURE') || tUpper.includes('WEBINAR')) {
        icon = '🎙️'; label = tag ? tag : 'GUEST LECTURE & SEMINAR'; cls = 'badge-cyan';
    } else if (tUpper.includes('VISIT') || tUpper.includes('OFFICIAL') || tUpper.includes('TOUR') || tUpper.includes('INSPECTION')) {
        icon = '🏛️'; label = tag ? tag : 'OFFICIAL VISIT'; cls = 'badge-gold';
    } else if (tUpper.includes('INDUSTRIAL') || tUpper.includes('WORKSHOP') || tUpper.includes('BOOTCAMP') || tUpper.includes('HACKATHON')) {
        icon = '🚀'; label = tag ? tag : 'TECHNICAL WORKSHOP'; cls = 'badge-gold';
    } else if (tUpper.includes('MEETING') || tUpper.includes('HIGH-PROFILE') || tUpper.includes('CONFERENCE') || tUpper.includes('SUMMIT')) {
        icon = '💼'; label = tag ? tag : 'HIGH-PROFILE MEETING'; cls = 'badge-purple';
    } else if (tUpper.includes('PLACEMENT') || tUpper.includes('RECRUITMENT') || tUpper.includes('DRIVE') || tUpper.includes('CAREER')) {
        icon = '🏆'; label = tag ? tag : 'PLACEMENT DRIVE'; cls = 'badge-emerald';
    } else if (tUpper.includes('NOTICE') || tUpper.includes('URGENT') || tUpper.includes('IMPORTANT') || tUpper.includes('ANNOUNCEMENT')) {
        icon = '⚡'; label = tag ? tag : 'IMPORTANT NOTICE'; cls = 'badge-rose';
    }

    if (!cleanTitle) cleanTitle = titleRaw || 'Official Event & Notice';

    // 2. Parse Other Info / Structured & Loose Metadata
    const meta = { speaker: '', role: '', date: '', time: '', venue: '', desc: '', org: '' };

    if (infoRaw.includes('::')) {
        infoRaw.split('|').forEach(part => {
            const parts = part.split('::');
            if (parts.length >= 2) {
                const k = (parts[0] || '').trim().toLowerCase();
                const v = parts.slice(1).join('::').trim();
                if (k === 'speaker') meta.speaker = v;
                else if (k === 'role' || k === 'designation') meta.role = v;
                else if (k === 'date') meta.date = v;
                else if (k === 'time') meta.time = v;
                else if (k === 'venue' || k === 'location') meta.venue = v;
                else if (k === 'desc' || k === 'description' || k === 'details' || k === 'info') meta.desc = v;
                else if (k === 'org' || k === 'organization' || k === 'host' || k === 'institution') meta.org = v;
            }
        });
    }

    if (!meta.desc) {
        meta.desc = infoRaw;
    }

    // 3. Fallback Date Formatting from DB fields (start_date / end_date / created_at)
    if (!meta.date) {
        if (ad.start_date && ad.end_date) {
            const d1 = new Date(ad.start_date);
            const d2 = new Date(ad.end_date);
            const fmt1 = !isNaN(d1) ? d1.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }) : ad.start_date;
            const fmt2 = !isNaN(d2) ? d2.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }) : ad.end_date;
            meta.date = (fmt1 === fmt2) ? fmt1 : `${fmt1} – ${fmt2}`;
        } else if (ad.start_date) {
            const d = new Date(ad.start_date);
            meta.date = !isNaN(d) ? d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }) : ad.start_date;
        } else if (ad.created_at) {
            const d = new Date(ad.created_at);
            if (!isNaN(d)) meta.date = 'Posted: ' + d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });
        }
    }

    // 4. Fallback Venue / Location
    if (!meta.venue) {
        if (ad.display_location && ad.display_location !== 'Main Display Center') {
            meta.venue = ad.display_location;
        }
    }

    let adCategoryClass = 'ad-general';
    if (tUpper.includes('CLIENT') || tUpper.includes('CUSTOMER') || tUpper.includes('PARTNER')) {
        adCategoryClass = 'ad-client';
    } else if (tUpper.includes('INTERNAL') || tUpper.includes('PROJECT') || tUpper.includes('SYSTEM') || tUpper.includes('DEVELOPMENT') || tUpper.includes('MEETING')) {
        adCategoryClass = 'ad-internal';
    } else if (tUpper.includes('CAREER') || tUpper.includes('PLACEMENT') || tUpper.includes('WORKSHOP') || tUpper.includes('SUCCESS') || tUpper.includes('SEMINAR') || tUpper.includes('LECTURE') || tUpper.includes('TRAINING') || tUpper.includes('BOOTCAMP')) {
        adCategoryClass = 'ad-career';
    }

    return {
        cleanTitle,
        icon,
        label,
        cls,
        meta,
        adCategoryClass,
        raw: ad
    };
}

function renderAdSignageCard(ad) {
    if (!ad) return '';

    const parsed = parseAdMeta(ad);
    const meta = parsed.meta;
    const mediaPath = resolveAdMediaPath(ad.file_path);

    // ── Left Side: Media Frame (45% split) ───────────────────
    let mediaInner = '';
    if (mediaPath) {
        if (ad.media_type === 'video') {
            mediaInner = `
                <video src="${escapeHtml(mediaPath)}" autoplay loop muted playsinline style="width:100%;height:100%;object-fit:cover;border-radius:18px;display:block;margin:auto;"></video>`;
        } else {
            mediaInner = `
                <img src="${escapeHtml(mediaPath)}" alt="${escapeHtml(parsed.cleanTitle)}" style="width:100%;height:100%;object-fit:cover;border-radius:18px;display:block;margin:auto;">`;
        }
    } else {
        mediaInner = `
            <div class="exec-media-placeholder">
                <div class="exec-icon">${parsed.icon}</div>
                <div style="font-family:'Sora',sans-serif;font-weight:700;font-size:clamp(0.8rem,1.1vw,1rem);color:rgba(0,242,254,0.7);letter-spacing:2px;text-transform:uppercase;text-align:center;padding:0 1rem;">
                    ${escapeHtml(parsed.label)}
                </div>
            </div>`;
    }

    const orgBadge = meta.org
        ? `<div class="exec-org-badge">🏢 ${escapeHtml(meta.org)}</div>`
        : (meta.venue ? `<div class="exec-org-badge">📍 ${escapeHtml(meta.venue)}</div>` : '');

    // ── Right Side: Content Details Panel (55% split) ─────────
    const speakerInitial = meta.speaker ? meta.speaker.trim()[0].toUpperCase() : parsed.icon;
    const speakerCard = (meta.speaker || meta.role) ? `
        <div class="exec-speaker-card">
            <div class="exec-speaker-avatar">${speakerInitial}</div>
            <div class="exec-speaker-info">
                <div class="exec-speaker-name">${escapeHtml(meta.speaker || 'Director / Guest Official')}</div>
                <div class="exec-speaker-role">${escapeHtml(meta.role || 'Event Host / Leader')}</div>
            </div>
        </div>` : '';

    // Metadata Glass Badges Grid
    let metaBadges = '';
    if (meta.date) {
        metaBadges += `<div class="exec-meta-badge badge-gold"><span class="exec-meta-icon">📅</span>${escapeHtml(meta.date)}</div>`;
    }
    if (meta.time) {
        metaBadges += `<div class="exec-meta-badge"><span class="exec-meta-icon">🕐</span>${escapeHtml(meta.time)}</div>`;
    }
    if (meta.venue) {
        metaBadges += `<div class="exec-meta-badge"><span class="exec-meta-icon">📍</span>${escapeHtml(meta.venue)}</div>`;
    }
    metaBadges += `<div class="exec-meta-badge" style="background:rgba(54,226,201,0.08);border-color:rgba(54,226,201,0.25);color:var(--accent-cyan);"><span class="exec-meta-icon">●</span>ACTIVE DISPLAY</div>`;

    // Detailed Description Block
    const descHtml = meta.desc
        ? `<div class="exec-description" style="white-space: pre-wrap; overflow-y: auto; max-height: 220px; color: #CBD5E1; line-height: 1.65; font-size: clamp(0.88rem, 1.1vw, 1.05rem); padding-right: 8px; margin-top: 0.25rem;">${escapeHtml(meta.desc)}</div>`
        : '';

    return `
    <div class="exec-slide-wrap ad-slide-marker ${escapeHtml(parsed.adCategoryClass)}">

        <!-- LEFT: Media Frame (45%) -->
        <div class="exec-media-side" style="padding: clamp(0.5rem, 1vw, 1rem); background: rgba(0, 0, 0, 0.25);">
            <div class="exec-portrait-frame" style="background: rgba(0,0,0,0.35); padding: 0; border-radius: 20px; display: flex; align-items: center; justify-content: center;">
                ${mediaInner}
            </div>
            ${orgBadge}
        </div>

        <!-- RIGHT: Information Panel (55%) -->
        <div class="exec-content-side" style="padding: clamp(1.5rem, 3vw, 2.5rem); gap: clamp(0.6rem, 1.2vw, 1rem);">
            <div class="exec-corner-deco"></div>

            <!-- Glowing Category Badge -->
            <div class="exec-category-badge ${escapeHtml(parsed.cls)}">
                ${parsed.icon} ${escapeHtml(parsed.label)}
            </div>

            <!-- Headline Title -->
            <div class="exec-event-title" style="font-size: clamp(1.4rem, 2.6vw, 2.2rem); font-weight: 800; color: #ffffff; line-height: 1.25;">
                ${escapeHtml(parsed.cleanTitle)}
            </div>

            <!-- Speaker / Host Highlight Card -->
            ${speakerCard}

            <!-- Metadata Badges Grid -->
            <div class="exec-meta-grid">${metaBadges}</div>

            <!-- Detailed Description / Explanation -->
            ${descHtml}
        </div>
    </div>`;
}

function slideExecutiveSeminar(ad) {
    return renderAdSignageCard(ad);
}

function slideAdvertisementsGroup(groupItems) {
    if (!groupItems || groupItems.length === 0) return '';
    return groupItems.map(ad => renderAdSignageCard(ad)).join('');
}

/* ================================================================
   FOOTER (TICKER + MEDIA) RENDERER
   ================================================================ */
function renderAdsFooterTicker() {
    if (!adsFooter || adsFooter.length === 0) {
        document.documentElement.style.setProperty('--bottombar-h', '0px');
        return;
    }
    
    // Adjust stage and UI elements for the 110px tall footer ticker
    document.documentElement.style.setProperty('--bottombar-h', '110px');
    
    let tickerHtml = '';
    
    adsFooter.forEach(groupItems => {
        groupItems.forEach(ad => {
            let itemContent = '';
            let mediaPath = resolveAdMediaPath(ad.file_path);
            
            if (mediaPath) {
                if (ad.media_type === 'image') {
                    itemContent += `<img src="${mediaPath}" style="height: 80px; object-fit: contain; border-radius: 8px; border: 1px solid rgba(255,255,255,0.15); box-shadow: 0 4px 15px rgba(0,0,0,0.8), 0 0 20px rgba(255,255,255,0.05);">`;
                } else if (ad.media_type === 'video') {
                    itemContent += `<video src="${mediaPath}" style="height: 80px; object-fit: contain; border-radius: 8px; border: 1px solid rgba(255,255,255,0.15); box-shadow: 0 4px 15px rgba(0,0,0,0.8), 0 0 20px rgba(255,255,255,0.05);" autoplay loop muted></video>`;
                }
            }
            if (ad.title || ad.other_info) {
                let text = ad.title || '';
                if (ad.other_info) text += text ? ` — ${ad.other_info}` : ad.other_info;
                let dot = mediaPath ? '' : `<span class="mdot" style="width:20px;height:20px;border-radius:50%;background:var(--accent-cyan);box-shadow: 0 0 12px var(--accent-cyan);display:inline-block;animation:pulse 2s infinite;"></span>`;
                itemContent += `<span style="display:inline-flex; align-items:center; gap:20px; text-shadow: 0 0 15px rgba(54, 226, 201, 0.6);">${dot} ${escapeHtml(text)}</span>`;
            }
            
            if (itemContent) {
                tickerHtml += `<span style="margin-right:150px; display:inline-flex; align-items:center; gap: 24px;">${itemContent}</span>`;
            }
        });
    });
    
    const style = document.createElement('style');
    style.innerHTML = `
        .progress-track { bottom: 110px !important; }
        .dots { display: none !important; }
        .footer-ticker-container {
            position: fixed; 
            bottom: 0; 
            left: 0; 
            right: 0; 
            height: 110px; 
            background: linear-gradient(90deg, rgba(7,11,22,0.9), rgba(15,24,48,0.95), rgba(7,11,22,0.9));
            backdrop-filter: blur(15px);
            border-top: 1px solid rgba(54, 226, 201, 0.15); 
            z-index: 9999; 
            display: flex; 
            align-items: center; 
            overflow: hidden; 
            color: #fff; 
            font-family: 'Sora', sans-serif;
            font-weight: 700; 
            font-size: 2rem; 
            letter-spacing: 2px;
            text-transform: uppercase;
            box-shadow: 0 -10px 40px rgba(0,0,0,0.8), inset 0 1px 0 rgba(255,255,255,0.05);
        }
        .footer-ticker-container::before {
            content: '';
            position: absolute;
            left: 0; top: 0; bottom: 0; width: 150px;
            background: linear-gradient(to right, rgba(7,11,22,0.9) 10%, transparent);
            z-index: 2;
            pointer-events: none;
        }
        .footer-ticker-container::after {
            content: '';
            position: absolute;
            right: 0; top: 0; bottom: 0; width: 150px;
            background: linear-gradient(to left, rgba(7,11,22,0.9) 10%, transparent);
            z-index: 2;
            pointer-events: none;
        }
    `;
    document.head.appendChild(style);
    
    const footerContainer = document.createElement('div');
    footerContainer.className = 'footer-ticker-container';
    footerContainer.style.display = 'none';
    
    footerContainer.innerHTML = `
        <div style="flex:1; overflow:hidden; white-space:nowrap; position:relative;">
            <div style="animation:scroll-left 40s linear infinite; display:inline-block; padding-left: 100%;">
                ${tickerHtml}
                ${tickerHtml}
            </div>
        </div>
    `;
    document.body.appendChild(footerContainer);
}

/* ================================================================
   HEADER ADS RENDERER
   ================================================================ */
function renderAdsHeader() {
    if (!adsHeader || adsHeader.length === 0) return;
    
    let mediaHtml = '';
    
    adsHeader.forEach(groupItems => {
        groupItems.forEach(ad => {
            let mediaPath = resolveAdMediaPath(ad.file_path);
            if (mediaPath) {
                if (ad.media_type === 'image') {
                    mediaHtml += `<img src="${mediaPath}" style="height: 50px; object-fit: contain; margin-left: 15px; border-radius: 6px; box-shadow: 0 2px 10px rgba(0,0,0,0.5);">`;
                } else if (ad.media_type === 'video') {
                    mediaHtml += `<video src="${mediaPath}" style="height: 50px; object-fit: contain; margin-left: 15px; border-radius: 6px; box-shadow: 0 2px 10px rgba(0,0,0,0.5);" autoplay loop muted></video>`;
                }
            }
            if (ad.title || ad.other_info) {
                let text = ad.title || '';
                if (ad.other_info) text += text ? ` — ${ad.other_info}` : ad.other_info;
                mediaHtml += `<span style="margin-left: 15px; padding: 6px 16px; background: rgba(54, 226, 201, 0.1); border: 1px solid rgba(54, 226, 201, 0.3); border-radius: 30px; font-weight:700; color:var(--accent-cyan); font-family: 'Sora', sans-serif; letter-spacing: 1px; box-shadow: 0 0 15px rgba(54, 226, 201, 0.2);">${escapeHtml(text)}</span>`;
            }
        });
    });
    
    if(mediaHtml) {
        // Inject into topbar
        let topbar = document.querySelector('.topbar');
        if(topbar) {
            let adContainer = document.createElement('div');
            adContainer.className = 'header-ads-container';
            adContainer.style.display = 'none';
            adContainer.style.alignItems = 'center';
            adContainer.style.marginLeft = 'auto'; // push to right
            adContainer.innerHTML = mediaHtml;
            topbar.appendChild(adContainer);
        }
    }
}

// Render Header & Footer Ads
renderAdsFooterTicker();
renderAdsHeader();

/* ================================================================
   PLACEMENT HIGHLIGHTS — ADVERTISEMENT BANNER BUILDER
   Uses DATA.placed_students (PHP-embedded JSON, no AJAX needed).
   NOW: vertical layout — static stacked cards when they all fit
   in the footer height, or a seamless vertical scroll-up ticker
   when they overflow. Matches the same visual pattern used by
   buildVScroll() elsewhere in this file, but scoped to this
   footer's own height/track instead of the main slide stage.
   ================================================================ */
function buildPlacedSpotlightFooter() {
    if (!placedStudents || placedStudents.length === 0) {
        document.documentElement.style.setProperty('--spotlight-h', '0px');
        return;
    }

    /* ── Card HTML builder ─────────────────────────────────── */
    function spCardHtml(s) {
        const initial  = (s.student_name || '?')[0].toUpperCase();
        const photoEl  = s.photo_path
            ? `<img class="student-avatar" src="../pages/${escapeHtml(s.photo_path)}" alt="${escapeHtml(s.student_name)}">`
            : `<div class="student-avatar-placeholder">${initial}</div>`;

        let pillBadges = '';
        if (s.role_offered) {
            pillBadges += `<span class="student-pill-badge role-badge">&#x1F4BC; ${escapeHtml(s.role_offered)}</span>`;
        }
        if (s.package_ctc) {
            pillBadges += `<span class="student-pill-badge pkg-badge">&#x1F4B0; ${escapeHtml(s.package_ctc)}</span>`;
        }
        if (s.branch) {
            pillBadges += `<span class="student-pill-badge branch-badge">&#x1F4CD; ${escapeHtml(s.branch)}</span>`;
        }

        return `
        <div class="student-card">
            ${photoEl}
            <div class="student-details">
                <div class="student-name">${escapeHtml(s.student_name)}</div>
                <div class="student-company">&#x1F3E2; ${escapeHtml(s.company_name)}</div>
                <div class="student-badges-row">${pillBadges}</div>
            </div>
        </div>`;
    }

    /* ── Build footer element ──────────────────────────────── */
    const footer = document.createElement('div');
    footer.className = 'spotlight-footer';
    footer.id        = 'placedSpotlightFooter';

    const labelHtml = `
        <div class="sp-label-panel">
            <span class="spl-icon">&#x1F3C6;</span>
            <span class="spl-title">Career<br>Success Stories</span>
        </div>`;

    const cardsHtml = placedStudents.map(s => spCardHtml(s)).join('');

    footer.innerHTML = `
        ${labelHtml}
        <div id="placed-students-slider">
            <div class="slider-track" id="sliderTrack">
                ${cardsHtml}
                ${cardsHtml}
            </div>
        </div>`;

    document.body.appendChild(footer);

    /* ── Configure horizontal slider loop distance and card widths ── */
    requestAnimationFrame(() => {
        const slider = document.getElementById('placed-students-slider');
        const track  = document.getElementById('sliderTrack');
        if (!slider || !track) return;

       const containerW = slider.clientWidth || 360;
       const CARD_GAP = 16;

const isTablet =
    window.innerWidth > 600 &&
    window.innerWidth <= 1024;
/*
 * Samsung/mobile:
 * Full-width card
 *
 * iPad/tablet:
 * Card uses only ~68% of available width
 * so there is no unnecessary empty space.
 */
const cardW = isTablet
    ? Math.min(containerW * 0.68, 380)
    : containerW;

const cards = track.querySelectorAll('.student-card');

cards.forEach(card => {
    card.style.width = cardW + 'px';
    card.style.flex = `0 0 ${cardW}px`;
});

      const half = (cards.length / 2) * (cardW + CARD_GAP);
      const durationS = Math.max(14, (cards.length / 2) * 7); // ~3.5 sec per card for smooth sliding

        track.style.setProperty('--slider-dist', `-${half}px`);
        track.style.setProperty('--slider-duration', `${durationS}s`);

        // Mobile touch listeners to pause auto-scroll on touch
        slider.addEventListener('touchstart', () => { track.style.animationPlayState = 'paused'; }, { passive: true });
        slider.addEventListener('touchend', () => { track.style.animationPlayState = 'running'; }, { passive: true });
        slider.addEventListener('touchcancel', () => { track.style.animationPlayState = 'running'; }, { passive: true });
    });

    /* ── Stack with ads ticker if present ─────────────────── */
    const adsTicker = document.querySelector('.footer-ticker-container');
    if (adsTicker) {
        const spH  = getComputedStyle(document.documentElement)
                         .getPropertyValue('--spotlight-h').trim();
        adsTicker.style.bottom = spH;
        const adsH = parseInt(adsTicker.style.height || '110', 10);
        document.documentElement.style.setProperty('--bottombar-h', adsH + 'px');
        const ps = document.createElement('style');
        ps.innerHTML = `.progress-track { bottom: calc(var(--spotlight-h) + ${adsH}px) !important; }`;
        document.head.appendChild(ps);
    } else {
        const ps = document.createElement('style');
        ps.innerHTML = `.progress-track { bottom: var(--spotlight-h) !important; }`;
        document.head.appendChild(ps);
        const dotsEl = document.getElementById('dots');
        if (dotsEl) dotsEl.style.display = 'none';
    }
}

buildPlacedSpotlightFooter();

/* ================================================================
   ASSEMBLE SLIDE DECK
   ================================================================ */
const slides = [];
slides.push(slideOverview());
slides.push(slideClients());
slides.push(slideInternStats());
slides.push(slideBranchStats());

// Announcements slide (if active board announcements exist)
if (announcements && announcements.length > 0) {
    const annSlideHtml = slideAnnouncements();
    if (annSlideHtml) slides.push(annSlideHtml);
}

// NOTE: Placed Students are now rendered as a persistent global footer ticker
// (see buildPlacedSpotlightFooter below) — no longer a standalone slide.

// Add each main ad group as a separate slide
// Rendered using the high-end split-screen digital signage card layout
adsMain.forEach(groupItems => {
    if (!groupItems || groupItems.length === 0) return;
    groupItems.forEach(ad => {
        const signageHtml = renderAdSignageCard(ad);
        if (signageHtml) slides.push(signageHtml);
    });
});

slides.push(slideActiveList());
activeProjects.slice(0, 8).forEach(p => slides.push(slideProjectSpotlight(p)));
slides.push(slidePastProjects());

/* ================================================================
   RENDER INTO DOM
   ================================================================ */
const stage          = document.getElementById('stage');
const dotsContainer  = document.getElementById('dots');

slides.forEach((html, i) => {
    const div = document.createElement('div');
    div.className = 'slide' + (i === 0 ? ' active' : '');
    div.innerHTML = html;
    stage.appendChild(div);

    const dot = document.createElement('div');
    dot.className = 'dot' + (i === 0 ? ' active' : '');
    dotsContainer.appendChild(dot);
});

/* ================================================================
   VERTICAL SCROLL — start/stop animations per active slide
   ================================================================ */
function activateVScroll(slideEl) {
    const outer = slideEl.querySelector('.vscroll-outer');
    if (!outer) return;

    const track = outer.querySelector('.vscroll-track');
    if (!track) return;

    let totalH = outer.getAttribute('data-dist') || 500;
    
    // Measure actual rendered height for precise smooth loop
    const clone = track.querySelector('.vscroll-clone');
    if (clone && clone.offsetHeight > 0) {
        totalH = clone.offsetHeight;
    }
    
    const durationS = Math.max(12, totalH / 35); // 35px per sec

    track.style.setProperty('--scroll-dist', `-${totalH}px`);
    track.style.animation = `scroll-up ${durationS}s linear infinite`;
    
    return durationS * 1000;
}

function deactivateVScroll(slideEl) {
    const track = slideEl.querySelector('.vscroll-track');
    if (track) track.style.animation = 'none';
}

/* ================================================================
   SLIDE ROTATION
   ================================================================ */
const SLIDE_DURATION = 10000; // default 10 seconds per slide
let currentSlide = 0;
const slideEls   = document.querySelectorAll('.slide');
const dotEls     = document.querySelectorAll('.dot');
const progressFill = document.getElementById('progressFill');
let slideTimeout;

function scheduleNextSlide(duration) {
    clearTimeout(slideTimeout);
    restartProgress(duration);
    slideTimeout = setTimeout(() => goToSlide(currentSlide + 1), duration);
}

function goToSlide(index) {
    deactivateVScroll(slideEls[currentSlide]);
    slideEls[currentSlide].classList.remove('active');
    dotEls[currentSlide].classList.remove('active');

    currentSlide = index % slideEls.length;

    slideEls[currentSlide].classList.add('active');
    dotEls[currentSlide].classList.add('active');
    
    let scrollTime = activateVScroll(slideEls[currentSlide]);
    
    // Show header and footer ads on all slides
    let ft = document.querySelector('.footer-ticker-container');
    let ht = document.querySelector('.header-ads-container');
    if (ft) ft.style.display = 'flex';
    if (ht) ht.style.display = 'flex';

    // Only wait for scroll completion on Advertisement slides
    let isAdSlide = slideEls[currentSlide].querySelector('.ad-slide-marker');
    let nextDuration = isAdSlide ? Math.max(SLIDE_DURATION, scrollTime || SLIDE_DURATION) : SLIDE_DURATION;
    scheduleNextSlide(nextDuration);
}

// Check initial slide on load
let ftInit = document.querySelector('.footer-ticker-container');
let htInit = document.querySelector('.header-ads-container');
if (ftInit) ftInit.style.display = 'flex';
if (htInit) htInit.style.display = 'flex';

function restartProgress(duration) {
    progressFill.style.transition = 'none';
    progressFill.style.width = '0%';
    requestAnimationFrame(() => requestAnimationFrame(() => {
        progressFill.style.transition = `width ${duration}ms linear`;
        progressFill.style.width = '100%';
    }));
}

// Start first slide
let initialScrollTime = activateVScroll(slideEls[0]);
let initialDuration = Math.max(SLIDE_DURATION, initialScrollTime || SLIDE_DURATION);
scheduleNextSlide(initialDuration);

/* ================================================================
   AUTO-REFRESH every 5 minutes
   ================================================================ */
setTimeout(() => location.reload(), 5 * 60 * 1000);
</script>
</body>
</html>
