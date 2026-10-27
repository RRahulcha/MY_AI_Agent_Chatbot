<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once('../include/db_config.php');

$userRole = isset($_SESSION['userRole']) ? strtolower($_SESSION['userRole']) : 'user';

$protocol    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host        = $_SERVER['HTTP_HOST'];
$partialsDir = str_replace('\\', '/', realpath(__DIR__));
$docRoot     = str_replace('\\', '/', rtrim($_SERVER['DOCUMENT_ROOT'], '/'));
$partialsUrl = $protocol . '://' . $host . str_replace($docRoot, '', $partialsDir);
$ajaxUrl = $protocol . '://' . $host . str_replace($docRoot, '', str_replace('\\', '/', realpath(__DIR__ . '/../ajax')));


/* ── Auto cleanup: notification expiry (runs once per 24h per session) ── */
if (!empty($_SESSION['userId'])) {
    $cleanupLastRun = $_SESSION['notif_cleanup_last_run'] ?? 0;

    if ((time() - $cleanupLastRun) > 86400) {
        try {
            $cleanupOk = true;

            if (!$con->query("DELETE FROM notifications WHERE (title IN ('No Active Task','Hourly Task Check') OR (title LIKE 'Hello%' AND message = 'Welcome to SETTribe!')) AND created_at < NOW() - INTERVAL 7 DAY"))   $cleanupOk = false;
            if (!$con->query("DELETE FROM notifications WHERE title NOT IN ('No Active Task','Hourly Task Check') AND created_at < NOW() - INTERVAL 60 DAY")) $cleanupOk = false;
            if (!$con->query("DELETE FROM push_only_log WHERE sent_at < NOW() - INTERVAL 1 DAY"))                                                            $cleanupOk = false;

            if ($cleanupOk) $_SESSION['notif_cleanup_last_run'] = time();
        } catch (Exception $e) {
            // Silently fail — never break the page
        }
    }
}

$nav_userId   = $_SESSION['userId'] ?? '';
$nav_today    = date('Y-m-d');
$nav_userIdEs = mysqli_real_escape_string($con, $nav_userId);

// Cache key and expiry time
$cacheKey = 'nav_cache_' . $nav_userId;
$cacheTimeKey = 'nav_cache_time_' . $nav_userId;
$cacheExpiry = 5; // 5 seconds

// Check if cache exists and is still valid
$useCache = isset($_SESSION[$cacheKey]) && 
            isset($_SESSION[$cacheTimeKey]) && 
            (time() - $_SESSION[$cacheTimeKey]) < $cacheExpiry;

if ($useCache) {
    // ✅ USE CACHED DATA (0 database queries)
    $cache = $_SESSION[$cacheKey];
    $nav_hasPunchedIn = $cache['hasPunchedIn'];
    $nav_expectedPunchIn = $cache['expectedPunchIn'];
    $nav_expectedPunchOut = $cache['expectedPunchOut'] ?? '18:00:00';
    $nav_unreadNotifCount = $cache['unreadNotifCount'];
    $fullName = $cache['fullName'];
    $profilePhoto = $cache['profilePhoto'];
} else {
    // ✅ FETCH FRESH DATA - Optimized to 2 queries instead of 4
    
    /* ────────────────────────────────────────────────────────────
       QUERY 1: Combined punch-in check + user profile data
       (Replaces 3 separate queries with 1 optimized query)
    ──────────────────────────────────────────────────────────── */
    $nav_combined_query = "
        SELECT 
            s.expectedPunchIn,
            s.expectedPunchOut,
            s.fullname,
            s.profilePhoto,
            (SELECT COUNT(*) 
             FROM attendance 
             WHERE userId = '$nav_userIdEs' 
               AND date = '$nav_today' 
               AND punchInTime != '00:00:00' 
               AND punchInTime IS NOT NULL 
             LIMIT 1) AS regular_punch,
            (SELECT COUNT(*) 
             FROM tourAttendance 
             WHERE userId = '$nav_userIdEs' 
               AND date = '$nav_today' 
               AND punchInTime != '00:00:00' 
               AND punchInTime IS NOT NULL 
             LIMIT 1) AS tour_punch
        FROM signup s
        WHERE s.userId = '$nav_userIdEs'
        LIMIT 1
    ";
    
    $nav_combined_result = mysqli_query($con, $nav_combined_query);
    
    if ($nav_combined_result && $nav_combined_row = mysqli_fetch_assoc($nav_combined_result)) {
        $nav_hasPunchedIn = ($nav_combined_row['regular_punch'] > 0) || ($nav_combined_row['tour_punch'] > 0);
        $nav_expectedPunchIn = $nav_combined_row['expectedPunchIn'] ?? '10:00:00';
        $nav_expectedPunchOut = $nav_combined_row['expectedPunchOut'] ?? '18:00:00';
        $fullName = (!empty($nav_combined_row['fullname'])) ? $nav_combined_row['fullname'] : "Intern";
        $profilePhoto = $nav_combined_row['profilePhoto'] ?? '';
    } else {
        // Fallback defaults if query fails
        $nav_hasPunchedIn = false;
        $nav_expectedPunchIn = '10:00:00';
        $nav_expectedPunchOut = '18:00:00';
        $fullName = "Intern";
        $profilePhoto = '';
    }
    
    /* ────────────────────────────────────────────────────────────
       QUERY 2: Unread notification count
    ──────────────────────────────────────────────────────────── */
    $nav_unreadNotifCount = 0;
    if (!empty($nav_userId)) {
        $nav_notifQ = mysqli_query($con,
            "SELECT COUNT(*) AS cnt
             FROM   notifications
             WHERE  userId  = '$nav_userIdEs'
               AND  is_read = 0"
        );
        if ($nav_notifQ) {
            $nav_notifRow = mysqli_fetch_assoc($nav_notifQ);
            $nav_unreadNotifCount = (int)($nav_notifRow['cnt'] ?? 0);
        }
    }
    
    // ✅ STORE IN SESSION CACHE
    $_SESSION[$cacheKey] = [
        'hasPunchedIn' => $nav_hasPunchedIn,
        'expectedPunchIn' => $nav_expectedPunchIn,
        'expectedPunchOut' => $nav_expectedPunchOut,
        'unreadNotifCount' => $nav_unreadNotifCount,
        'fullName' => $fullName,
        'profilePhoto' => $profilePhoto
    ];
    $_SESSION[$cacheTimeKey] = time();
}

if (empty($profilePhoto) || !file_exists(__DIR__ . '/../profile-photos/' . $profilePhoto)) {
    $profilePhoto = 'default.png';
}

// Extract first name for mobile view
$firstName = explode(' ', $fullName)[0];
$userRoleDisplay = ucwords(str_replace('_', ' ', $userRole));

// Compute dashboard URL
$userDashboardUrl = 'jrEmpDashboard.php';
switch ($userRole) {
    case 'admin':
        $userDashboardUrl = 'adminDashboard.php';
        break;
    case 'hr':
        $userDashboardUrl = 'hrDashboard.php';
        break;
    case 'senior_intern':
        $userDashboardUrl = 'srEmpDashboard.php';
        break;
    case 'resourcehead':
    case 'resource_head':
        $userDashboardUrl = 'resourceHeadDashboard.php';
        break;
    case 'receptionist':
        $userDashboardUrl = 'receptionistDashboard.php';
        break;
    case 'md':
        $userDashboardUrl = 'mdDashboard.php';
        break;
    default:
        $userDashboardUrl = 'jrEmpDashboard.php';
        break;
}
?>
<!-- Instant Theme Applier (Light / Dark Mode Only) -->
<script>
(function() {
    try {
        var t = localStorage.getItem('ams_theme_mode');
        if (t === 'dark') {
            document.documentElement.classList.add('dark-theme', 'sidebar-dark');
            if (document.body) document.body.classList.add('dark-theme', 'sidebar-dark');
        } else {
            document.documentElement.classList.remove('dark-theme', 'sidebar-dark');
            if (document.body) document.body.classList.remove('dark-theme', 'sidebar-dark');
        }
    } catch(e) {}
})();
</script>
<link rel="stylesheet" href="../css/ams-theme.css?v=8">

<style>
@import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap');

/* ══════════════════════════════════════════════════════
   NAVBAR THEME TOGGLE (LIGHT / DARK ONLY)
══════════════════════════════════════════════════════ */
/* Permanently remove floating settings button */
#settings-trigger, .theme-setting-wrapper, .settings-panel {
    display: none !important;
    visibility: hidden !important;
    pointer-events: none !important;
}

/* Redesigned 1-Click Theme Toggle Button */
.nav-theme-item {
    display: flex;
    align-items: center;
}
.nav-theme-toggle-btn {
    width: 38px !important;
    height: 38px !important;
    border-radius: 10px !important;
    background: #f8fafc !important;
    border: 1px solid #e2e8f0 !important;
    color: #2563eb !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    cursor: pointer !important;
    padding: 0 !important;
    margin: 0 !important;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1) !important;
    position: relative !important;
}
.nav-theme-toggle-btn:hover {
    background: #eff6ff !important;
    border-color: #bfdbfe !important;
    color: #1d4ed8 !important;
    transform: translateY(-1px);
    box-shadow: 0 4px 10px rgba(37, 99, 235, 0.15) !important;
}
.nav-theme-toggle-btn i {
    font-size: 15px !important;
    transition: transform 0.35s cubic-bezier(0.4, 0, 0.2, 1), color 0.2s ease !important;
}
.nav-theme-toggle-btn:active i {
    transform: rotate(90deg) scale(0.85);
}

/* Theme Toggle Button in Dark Mode */
body.dark-theme .nav-theme-toggle-btn {
    background: #1e293b !important;
    border-color: #334155 !important;
    color: #fbbf24 !important;
}
body.dark-theme .nav-theme-toggle-btn:hover {
    background: #334155 !important;
    border-color: #475569 !important;
    color: #fde047 !important;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3) !important;
}

/* ── Comprehensive Dark Mode for Website (Black, White, Blue Palette) ── */
body.dark-theme,
body.dark-theme .container-scroller,
body.dark-theme .page-body-wrapper,
body.dark-theme .main-panel,
body.dark-theme .content-wrapper {
    background: #0b1120 !important;
    background-color: #0b1120 !important;
    color: #f1f5f9 !important;
}

body.dark-theme .card {
    background: #111827 !important;
    background-color: #111827 !important;
    background-image: none !important;
    color: #f1f5f9 !important;
    border: 1px solid #1f2937 !important;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.35) !important;
}
body.dark-theme .card-title,
body.dark-theme h1,
body.dark-theme h2,
body.dark-theme h3,
body.dark-theme h4,
body.dark-theme h5,
body.dark-theme h6 {
    color: #f8fafc !important;
}
body.dark-theme p,
body.dark-theme span:not(.badge),
body.dark-theme label {
    color: #cbd5e1;
}
body.dark-theme .table {
    color: #e2e8f0 !important;
}
body.dark-theme .table thead th {
    background-color: #1e293b !important;
    color: #94a3b8 !important;
    border-bottom: 2px solid #334155 !important;
    border-top: none !important;
}
body.dark-theme .table td {
    border-top: 1px solid #334155 !important;
    border-bottom: 1px solid #334155 !important;
    color: #e2e8f0 !important;
}
body.dark-theme .table-striped tbody tr:nth-of-type(odd) {
    background-color: rgba(255, 255, 255, 0.02) !important;
}
body.dark-theme .table-hover tbody tr:hover {
    background-color: rgba(255, 255, 255, 0.04) !important;
}
body.dark-theme .form-control,
body.dark-theme select.form-control,
body.dark-theme textarea.form-control {
    background-color: #0f172a !important;
    border: 1px solid #334155 !important;
    color: #f8fafc !important;
}
body.dark-theme .form-control:focus {
    border-color: #6366f1 !important;
    box-shadow: 0 0 0 0.2rem rgba(99, 102, 241, 0.25) !important;
}
body.dark-theme .navbar {
    background: rgba(15, 23, 42, 0.98) !important;
    border-bottom: 1px solid #334155 !important;
}
body.dark-theme .navbar .navbar-brand-wrapper {
    background: #0f172a !important;
    border-right: 1px solid #334155 !important;
}
body.dark-theme .navbar .nav-link,
body.dark-theme .navbar .nav-icon-btn {
    color: #94a3b8 !important;
}
body.dark-theme .navbar .nav-icon-btn:hover {
    color: #f8fafc !important;
    background: #1e293b !important;
}
body.dark-theme .navbar-toggler {
    color: #94a3b8 !important;
}
body.dark-theme .sidebar {
    background: #0f172a !important;
    border-right: 1px solid #1e293b !important;
}
body.dark-theme .sidebar .nav .nav-item .nav-link {
    color: #cbd5e1 !important;
}
body.dark-theme .sidebar .nav .nav-item .nav-link i.menu-icon {
    color: #94a3b8 !important;
}
body.dark-theme .sidebar .nav .nav-item.active > .nav-link {
    background: #4B49AC !important;
    color: #ffffff !important;
}
body.dark-theme .footer {
    background: #0f172a !important;
    border-top: 1px solid #1e293b !important;
    color: #64748b !important;
}
body.dark-theme .modal-content {
    background-color: #1e293b !important;
    border: 1px solid #334155 !important;
    color: #f1f5f9 !important;
}
body.dark-theme .modal-header,
body.dark-theme .modal-footer {
    border-color: #334155 !important;
}
body.dark-theme .dropdown-menu:not(.ams-chat-window) {
    background-color: #1e293b !important;
    border: 1px solid #334155 !important;
    color: #f1f5f9 !important;
}
body.dark-theme .dropdown-item {
    color: #cbd5e1 !important;
}
body.dark-theme .dropdown-item:hover {
    background-color: #334155 !important;
    color: #ffffff !important;
}

/* Navbar Component Dark Styles */
body.dark-theme .nav-greeting-card {
    background: #1e293b !important;
    border-color: #334155 !important;
}
body.dark-theme .nav-greeting-card:hover {
    background: #334155 !important;
    border-color: #475569 !important;
}
body.dark-theme .nav-greeting-card .greet-title {
    color: #f8fafc !important;
}
body.dark-theme .sidebarBtn {
    background: #1e293b !important;
    border-color: #334155 !important;
    color: #cbd5e1 !important;
}
body.dark-theme .sidebarBtn:hover {
    background: #334155 !important;
    border-color: #475569 !important;
    color: #f8fafc !important;
}
body.dark-theme .nav-icon-btn {
    background: #1e293b !important;
    border-color: #334155 !important;
    color: #cbd5e1 !important;
}
body.dark-theme .nav-icon-btn svg {
    stroke: #cbd5e1 !important;
}
body.dark-theme .nav-icon-btn:hover {
    background: #334155 !important;
    border-color: #475569 !important;
    color: #f8fafc !important;
}
body.dark-theme .nav-profile-trigger-btn {
    background: #1e293b !important;
    border-color: #334155 !important;
}
body.dark-theme .nav-profile-trigger-btn:hover {
    background: #334155 !important;
    border-color: #475569 !important;
}
body.dark-theme .nav-profile-chevron {
    color: #94a3b8 !important;
}
body.dark-theme .nav-dropdown-header {
    background: #0f172a !important;
    border-bottom: 1px solid #334155 !important;
}
body.dark-theme .nav-dd-name {
    color: #f8fafc !important;
}
body.dark-theme .nav-popup {
    background: #1e293b !important;
    border-color: #334155 !important;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5) !important;
}
body.dark-theme .np-header {
    background: #0f172a !important;
    border-bottom-color: #334155 !important;
}
body.dark-theme .np-title {
    color: #f8fafc !important;
}
body.dark-theme .np-footer {
    background: #0f172a !important;
    border-top-color: #334155 !important;
}
body.dark-theme .tp-card {
    background: #0f172a !important;
    border-color: #334155 !important;
}
body.dark-theme .tp-task-name {
    color: #f8fafc !important;
}
body.dark-theme .lv-notif-card {
    background: #1e293b !important;
    border-bottom-color: #334155 !important;
}
body.dark-theme .lv-notif-card.unread {
    background: #1e293b !important;
}
body.dark-theme .lv-notif-title {
    color: #f8fafc !important;
}
body.dark-theme .lv-notif-msg {
    color: #cbd5e1 !important;
}

/* ══════════════════════════════════════
   BACKDROP OVERLAY
══════════════════════════════════════ */
#navBackdrop {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 999998;
    background: rgba(15, 23, 42, 0.45);
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
    animation: navBackdropFade 0.2s ease;
}
@keyframes navBackdropFade {
    from { opacity: 0; }
    to   { opacity: 1; }
}

/* ══════════════════════════════════════
   NAVBAR ROOT & REALIGNMENT
══════════════════════════════════════ */
.navbar {
    min-height: 60px !important;
    height: 60px !important;
    padding: 0 !important;
    margin: 0 !important;
    background: rgba(255, 255, 255, 0.98) !important;
    backdrop-filter: blur(14px) saturate(180%) !important;
    -webkit-backdrop-filter: blur(14px) saturate(180%) !important;
    border-bottom: 1px solid #e2e8f0 !important;
    box-shadow: 0 2px 12px -2px rgba(15, 23, 42, 0.05), 0 1px 3px rgba(15, 23, 42, 0.02) !important;
    font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif !important;
    z-index: 1030 !important;
    display: flex !important;
    flex-direction: row !important;
    align-items: center !important;
    box-sizing: border-box !important;
}

/* BRAND WRAPPER (Aligned to match sidebar exactly) */
.navbar .navbar-brand-wrapper {
    width: 235px !important;
    height: 60px !important;
    background: #ffffff !important;
    border-right: 1px solid #eef2f6 !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    padding: 0 16px !important;
    margin: 0 !important;
    flex-shrink: 0 !important;
    transition: width 0.25s ease !important;
    box-sizing: border-box !important;
}
.navbar .navbar-brand-wrapper .brand-logo {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    margin: 0 !important;
    padding: 0 !important;
    text-decoration: none !important;
}
.navbar .navbar-brand-wrapper .brand-logo img {
    height: 36px !important;
    max-width: 170px !important;
    width: auto !important;
    object-fit: contain !important;
    transition: transform 0.2s ease !important;
}
.navbar .navbar-brand-wrapper .brand-logo:hover img {
    transform: scale(1.02) !important;
}
.navbar .navbar-brand-wrapper .brand-logo-mini {
    display: none !important;
    align-items: center !important;
    justify-content: center !important;
    margin: 0 !important;
    padding: 0 !important;
    text-decoration: none !important;
}
.navbar .navbar-brand-wrapper .brand-logo-mini img {
    height: 32px !important;
    max-width: 42px !important;
    width: auto !important;
    object-fit: contain !important;
}

/* Collapsed sidebar brand state */
body.sidebar-icon-only .navbar .navbar-brand-wrapper,
.sidebar-icon-only .navbar .navbar-brand-wrapper {
    width: 70px !important;
    padding: 0 6px !important;
}
body.sidebar-icon-only .navbar .navbar-brand-wrapper .brand-logo,
.sidebar-icon-only .navbar .navbar-brand-wrapper .brand-logo {
    display: none !important;
}
body.sidebar-icon-only .navbar .navbar-brand-wrapper .brand-logo-mini,
.sidebar-icon-only .navbar .navbar-brand-wrapper .brand-logo-mini {
    display: flex !important;
}

/* MENU WRAPPER */
.navbar .navbar-menu-wrapper {
    width: calc(100% - 235px) !important;
    height: 60px !important;
    background: transparent !important;
    padding: 0 18px !important;
    margin: 0 !important;
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    transition: width 0.25s ease !important;
    box-sizing: border-box !important;
}
body.sidebar-icon-only .navbar .navbar-menu-wrapper,
.sidebar-icon-only .navbar .navbar-menu-wrapper {
    width: calc(100% - 70px) !important;
}
body.sidebar-icon-only .sidebarBtn .icon-menu {
    transform: rotate(180deg);
}

/* Mobile Sidebar Backdrop */
#mobileSidebarBackdrop {
    display: none;
    position: fixed;
    top: 60px;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(15, 23, 42, 0.45);
    backdrop-filter: blur(2px);
    -webkit-backdrop-filter: blur(2px);
    z-index: 1025;
    animation: navBackdropFade 0.2s ease;
}
#mobileSidebarBackdrop.active {
    display: block;
}

/* LEFT CLUSTER: Toggler + User Greeting */
.nav-left-cluster {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    flex-shrink: 0 !important;
}

/* Sidebar toggle button */
.sidebarBtn {
    width: 36px !important;
    height: 36px !important;
    border-radius: 10px !important;
    background: #f8fafc !important;
    border: 1px solid #e2e8f0 !important;
    color: #475569 !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    cursor: pointer !important;
    padding: 0 !important;
    margin: 0 !important;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1) !important;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.02) !important;
    flex-shrink: 0 !important;
}
.sidebarBtn:hover {
    background: #eff6ff !important;
    border-color: #bfdbfe !important;
    color: #2563eb !important;
    transform: translateY(-1px) !important;
    box-shadow: 0 4px 10px rgba(37, 99, 235, 0.12) !important;
}
.sidebarBtn .icon-menu {
    font-size: 14px !important;
    line-height: 1 !important;
}

/* User Greeting & Status Card */
.nav-greeting-card {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 4px 12px;
    background: #f8fafc;
    border: 1px solid #eef2f6;
    border-radius: 10px;
    transition: all 0.2s ease;
    white-space: nowrap;
}
.nav-greeting-card:hover {
    background: #f1f5f9;
    border-color: #e2e8f0;
}
.nav-greeting-card .greet-content {
    display: flex;
    flex-direction: column;
    text-align: left;
}
.nav-greeting-card .greet-title {
    font-size: 12.5px;
    font-weight: 700;
    color: #1e293b;
    line-height: 1.25;
}
.nav-greeting-card .greet-sub {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 10.5px;
    font-weight: 600;
    margin-top: 1px;
}
.status-beacon-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    display: inline-block;
    flex-shrink: 0;
}
.status-beacon-dot.punched {
    background: #10b981;
    box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.2);
    animation: beaconPunched 2s infinite ease-in-out;
}
.status-beacon-dot.pending {
    background: #f59e0b;
    box-shadow: 0 0 0 2px rgba(245, 158, 11, 0.2);
    animation: beaconPending 2s infinite ease-in-out;
}
@keyframes beaconPunched {
    0%, 100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.4); }
    50% { box-shadow: 0 0 0 5px rgba(16, 185, 129, 0); }
}
@keyframes beaconPending {
    0%, 100% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.4); }
    50% { box-shadow: 0 0 0 5px rgba(245, 158, 11, 0); }
}

/* RIGHT CLUSTER */
.navbar-nav-right {
    display: flex !important;
    flex-direction: row !important;
    align-items: center !important;
    gap: 8px !important;
    margin: 0 !important;
    padding: 0 !important;
    list-style: none !important;
    flex-wrap: nowrap !important;
}
.navbar-nav-right .nav-item {
    display: inline-flex !important;
    align-items: center !important;
    margin: 0 !important;
    padding: 0 !important;
    position: relative;
    flex-shrink: 0 !important;
}

/* ══════════════════════════════════════
   LIVE FACE PUNCH BUTTON
══════════════════════════════════════ */
.nav-face-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 0 13px;
    height: 36px;
    border-radius: 99px;
    background: linear-gradient(135deg, #4338ca 0%, #2563eb 60%, #3b82f6 100%);
    color: #ffffff !important;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.2px;
    text-decoration: none !important;
    border: none;
    box-shadow: 0 2px 10px rgba(37, 99, 235, 0.28);
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
    white-space: nowrap;
    cursor: pointer;
    flex-shrink: 0;
}
.nav-face-btn:hover {
    color: #ffffff !important;
    transform: translateY(-1.5px);
    box-shadow: 0 5px 16px rgba(37, 99, 235, 0.42);
}
.nav-face-btn:active {
    transform: translateY(0);
}
.nav-face-btn i {
    font-size: 13px;
    color: #ffffff;
    animation: cameraPulse 2s infinite ease-in-out;
}
@keyframes cameraPulse {
    0%, 100% { transform: scale(1); opacity: 1; }
    50% { transform: scale(1.15); opacity: 0.92; }
}
.nav-face-btn-tag {
    font-size: 8.5px;
    padding: 1px 6px;
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.24);
    color: #ffffff;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.4px;
}

/* ══════════════════════════════════════
   ACTION ICON BUTTONS
══════════════════════════════════════ */
.nav-icon-btn,
.navbar .navbar-menu-wrapper .navbar-toggler.nav-icon-btn {
    position: relative;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 36px !important;
    height: 36px !important;
    border: 1px solid #e2e8f0 !important;
    background: #f8fafc !important;
    border-radius: 10px !important;
    cursor: pointer !important;
    padding: 0 !important;
    margin: 0 !important;
    outline: none !important;
    box-shadow: 0 1px 2px rgba(0,0,0,0.02) !important;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1) !important;
    flex-shrink: 0 !important;
    text-decoration: none !important;
    box-sizing: border-box !important;
}
.nav-icon-btn:hover,
.navbar .navbar-menu-wrapper .navbar-toggler.nav-icon-btn:hover {
    background: #ffffff !important;
    border-color: #cbd5e1 !important;
    transform: translateY(-1.5px) !important;
    box-shadow: 0 4px 10px rgba(15, 23, 42, 0.08) !important;
}
.nav-icon-btn svg, .nav-icon-btn i {
    pointer-events: none;
}
.nav-icon-btn i {
    font-size: 16px;
    color: #475569;
    line-height: 1;
    transition: color 0.15s;
}
.nav-icon-btn:hover i {
    color: #2563eb;
}
.nav-icon-btn:hover svg {
    stroke: #2563eb !important;
}

.nav-icon-badge {
    position: absolute;
    top: -4px;
    right: -4px;
    min-width: 17px;
    height: 17px;
    background: #ef4444 !important;
    color: #ffffff !important;
    border-radius: 99px;
    font-size: 9px;
    font-weight: 700;
    line-height: 15px;
    text-align: center;
    pointer-events: none;
    padding: 0 4px;
    border: 2px solid #ffffff;
    z-index: 5;
    box-shadow: 0 2px 5px rgba(239, 68, 68, 0.35);
}
.nav-icon-badge.green {
    background: #10b981 !important;
    box-shadow: 0 2px 5px rgba(16, 185, 129, 0.35);
}

/* ══════════════════════════════════════
   USER PROFILE TRIGGER & DROPDOWN
══════════════════════════════════════ */
.nav-profile-trigger-btn {
    display: inline-flex !important;
    align-items: center !important;
    gap: 6px !important;
    padding: 2px 3px !important;
    border-radius: 99px !important;
    border: 1px solid transparent !important;
    background: transparent !important;
    cursor: pointer !important;
    text-decoration: none !important;
    transition: all 0.2s ease !important;
}
.nav-profile-trigger-btn:hover {
    background: #f8fafc !important;
    border-color: #e2e8f0 !important;
}
.nav-profile-avatar-wrap {
    position: relative;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    flex-shrink: 0;
}
.nav-profile-avatar-img {
    width: 36px !important;
    height: 36px !important;
    border-radius: 50% !important;
    object-fit: cover !important;
    border: 2px solid #ffffff !important;
    box-shadow: 0 2px 6px rgba(0,0,0,0.08) !important;
}
.nav-ring-punched {
    box-shadow: 0 0 0 2px #fff, 0 0 0 3.5px #10b981 !important;
}
.nav-ring-pending {
    box-shadow: 0 0 0 2px #fff, 0 0 0 3.5px #f59e0b !important;
}
.nav-profile-chevron {
    font-size: 10px !important;
    color: #94a3b8 !important;
    transition: transform 0.2s ease, color 0.2s ease !important;
}
.nav-profile-trigger-btn:hover .nav-profile-chevron {
    color: #2563eb !important;
    transform: translateY(1px) !important;
}

/* Dropdown Menu Card */
.navbar-dropdown {
    position: absolute !important;
    top: calc(100% + 8px) !important;
    right: 0 !important;
    left: auto !important;
    width: 290px !important;
    background: #ffffff !important;
    border: 1px solid #e2e8f0 !important;
    border-radius: 16px !important;
    padding: 0 !important;
    box-shadow: 0 20px 45px -10px rgba(15, 23, 42, 0.16), 0 4px 12px rgba(15, 23, 42, 0.05) !important;
    overflow: hidden !important;
    display: none !important;
    animation: popIn 0.2s cubic-bezier(0.16, 1, 0.3, 1) !important;
    margin-top: 0 !important;
    z-index: 1050 !important;
}
.navbar-dropdown.show {
    display: block !important;
}
.nav-dropdown-header {
    padding: 15px;
    background: linear-gradient(135deg, #f8fafc 0%, #eff6ff 100%);
    border-bottom: 1px solid #f1f5f9;
    display: flex;
    align-items: center;
    gap: 12px;
}
.nav-dd-avatar {
    width: 42px;
    height: 42px;
    border-radius: 50%;
    border: 2px solid #ffffff;
    box-shadow: 0 4px 10px rgba(0,0,0,0.06);
    object-fit: cover;
    flex-shrink: 0;
}
.nav-dd-info {
    flex: 1;
    min-width: 0;
    text-align: left;
}
.nav-dd-name {
    font-size: 13px;
    font-weight: 700;
    color: #0f172a;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    margin-bottom: 2px;
    line-height: 1.25;
}
.nav-dd-role-badge {
    display: inline-block;
    font-size: 10px;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 20px;
    background: #dbeafe;
    color: #1e40af;
    text-transform: capitalize;
}
.nav-dropdown-menu-list {
    padding: 8px;
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.nav-dropdown-link {
    display: flex !important;
    align-items: center !important;
    gap: 11px !important;
    padding: 8px 12px !important;
    border-radius: 10px !important;
    font-size: 13px !important;
    font-weight: 600 !important;
    color: #334155 !important;
    text-decoration: none !important;
    transition: all 0.15s ease !important;
    background: transparent !important;
    border: none !important;
    width: 100% !important;
    cursor: pointer !important;
}
.nav-dropdown-link:hover {
    background: #f1f5f9 !important;
    color: #0f172a !important;
    transform: translateX(3px) !important;
}
.nav-dropdown-link i, .nav-dropdown-link svg {
    font-size: 15px !important;
    width: 18px !important;
    text-align: center !important;
    color: #64748b !important;
    transition: color 0.15s ease !important;
}
.nav-dropdown-link:hover i, .nav-dropdown-link:hover svg {
    color: #2563eb !important;
}
.nav-dropdown-link.logout-btn-link {
    color: #ef4444 !important;
}
.nav-dropdown-link.logout-btn-link:hover {
    background: #fef2f2 !important;
    color: #dc2626 !important;
}
.nav-dropdown-link.logout-btn-link i {
    color: #ef4444 !important;
}
.nav-dropdown-divider {
    height: 1px;
    background: #f1f5f9;
    margin: 4px 8px;
}

/* ══════════════════════════════════════
   SHARED POPUP BASE
══════════════════════════════════════ */
.nav-popup {
    display: none;
    position: fixed;
    top: 66px;
    z-index: 999999;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    box-shadow: 0 16px 36px rgba(15,23,42,0.12), 0 3px 8px rgba(0,0,0,0.04);
    overflow: hidden;
    animation: popIn 0.2s cubic-bezier(0.16, 1, 0.3, 1);
}
@keyframes popIn {
    from { opacity: 0; transform: translateY(-8px); }
    to   { opacity: 1; transform: translateY(0); }
}

/* Desktop popup anchor positions */
#leaveNotifPopup {
    right: 20px;
    left: auto;
    width: 340px;
}
#taskPopup {
    right: 68px;
    left: auto;
    width: 330px;
}

/* HEADER */
.nav-popup-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 12px 14px;
    border-bottom: 1px solid #f1f5f9;
    background: #ffffff;
}
.nav-popup-header-left { display: flex; align-items: center; gap: 10px; }
.nav-popup-icon-wrap {
    width: 32px; height: 32px; border-radius: 10px;
    background: #eff6ff;
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.nav-popup-title { font-size: 13px; font-weight: 700; color: #0f172a; margin: 0; line-height: 1.2; }
.nav-popup-subtitle { font-size: 10.5px; color: #94a3b8; margin: 2px 0 0; }
.nav-popup-count {
    font-size: 10px; background: #2563eb; color: #ffffff;
    padding: 1px 7px; border-radius: 20px; font-weight: 700;
    margin-left: 5px; vertical-align: middle;
}
.nav-popup-close {
    width: 26px; height: 26px; border-radius: 8px;
    border: 1px solid #e2e8f0; background: #f8fafc;
    color: #94a3b8; font-size: 12px; line-height: 1;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; padding: 0; font-family: inherit;
    transition: all 0.15s ease;
}
.nav-popup-close:hover { background: #fee2e2; border-color: #fca5a5; color: #ef4444; }

/* BODY */
.nav-popup-body {
    max-height: 300px; overflow-y: auto;
    padding: 10px; background: #f8fafc;
}
.nav-popup-body::-webkit-scrollbar { width: 4px; }
.nav-popup-body::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }

/* FOOTER */
.nav-popup-footer { padding: 9px 12px; border-top: 1px solid #f1f5f9; background: #ffffff; }
.nav-popup-footer a {
    display: flex; align-items: center; justify-content: center; gap: 7px;
    width: 100%; padding: 8px 12px; border: none; border-radius: 10px;
    font-size: 12.5px; font-weight: 600; color: #ffffff;
    background: #2563eb;
    cursor: pointer; text-decoration: none;
    transition: background 0.15s;
}
.nav-popup-footer a:hover { background: #1d4ed8; color: #ffffff; text-decoration: none; }

/* ══════════════════════════════════════
   POPUP NOTIFICATION CARDS
══════════════════════════════════════ */
.lv-notif-card {
    background: #ffffff;
    border: 1px solid #e8edf5;
    border-radius: 11px;
    padding: 10px 12px;
    margin-bottom: 7px;
    transition: border-color 0.15s, opacity 0.2s;
    position: relative;
}
.lv-notif-card:last-child { margin-bottom: 0; }
.lv-notif-card:hover { border-color: #bfdbfe; }
.lv-notif-card.unread { background: #eff6ff; border-color: #bfdbfe; }
.lv-notif-card.deleting { opacity: 0.5; pointer-events: none; }

.lv-notif-dot {
    display: inline-block; width: 7px; height: 7px;
    background: #2563eb; border-radius: 50%;
    margin-right: 5px; flex-shrink: 0; margin-top: 3px;
}
.lv-notif-title { font-size: 12px; font-weight: 700; color: #0f172a; margin-bottom: 3px; line-height: 1.4; }
.lv-notif-message { font-size: 11px; color: #475569; line-height: 1.5; }
.lv-notif-time { font-size: 10px; color: #94a3b8; margin-top: 5px; }
.lv-notif-markread {
    border: none; background: none; color: #2563eb;
    font-size: 10px; font-weight: 600; cursor: pointer;
    padding: 0; margin-top: 5px; display: inline-block;
    font-family: inherit;
}
.lv-notif-markread:hover { text-decoration: underline; }

/* Delete button */
.lv-notif-delete {
    position: absolute; top: 8px; right: 8px;
    width: 20px; height: 20px; border-radius: 5px;
    border: 1px solid #e2e8f0; background: #f8fafc;
    color: #94a3b8; font-size: 11px; line-height: 1;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; padding: 0; font-family: inherit;
    transition: all 0.12s;
    opacity: 0;
}
.lv-notif-card:hover .lv-notif-delete { opacity: 1; }
.lv-notif-delete:hover { background: #fef2f2; border-color: #fecaca; color: #ef4444; }

/* Punch warning inside task popup */
.tp-punch-warning {
    background: #fff8e1; border: 1px solid #ffc107; border-radius: 10px;
    padding: 10px 12px; font-size: 11px; color: #856404;
    display: flex; align-items: flex-start; gap: 8px; line-height: 1.6; margin-bottom: 6px;
}
.tp-punch-warning svg { flex-shrink: 0; margin-top: 1px; }

/* TASK FILTER PILLS */
.tp-filter-row {
    display: flex; align-items: center; gap: 6px; padding: 8px 10px;
    border-bottom: 1px solid #f1f5f9; overflow: hidden;
}
.tp-filter-pill {
    display: inline-flex; align-items: center; gap: 4px; flex: 1; min-width: 0;
    justify-content: center; padding: 4px 6px; border-radius: 20px;
    font-size: 10px; font-weight: 700; border: 1px solid #e2e8f0;
    background: #f8fafc; color: #64748b; cursor: pointer; font-family: inherit;
    transition: all 0.15s; white-space: nowrap;
}
.tp-fp-dot { width: 5px; height: 5px; border-radius: 50%; background: #94a3b8; flex-shrink: 0; }
.tp-fp-num {
    font-size: 10px; font-weight: 700; padding: 0 4px; border-radius: 20px; line-height: 1.7;
    background: rgba(0,0,0,0.07); min-width: 14px; text-align: center;
}
.tp-filter-pill.tp-active-run { background: #f0fdf4; color: #166634; border-color: #bbf7d0; }
.tp-filter-pill.tp-active-run .tp-fp-dot { background: #16a34a; }
.tp-filter-pill.tp-active-run .tp-fp-num { background: #bbf7d0; color: #166534; }
.tp-filter-pill.tp-active-pau { background: #fffbeb; color: #92400e; border-color: #fde68a; }
.tp-filter-pill.tp-active-pau .tp-fp-dot { background: #d97706; }
.tp-filter-pill.tp-active-pau .tp-fp-num { background: #fde68a; color: #92400e; }
.tp-filter-pill.tp-active-end { background: #fef2f2; color: #991b1b; border-color: #fecaca; }
.tp-filter-pill.tp-active-end .tp-fp-dot { background: #ef4444; }
.tp-filter-pill.tp-active-end .tp-fp-num { background: #fecaca; color: #991b1b; }

/* TASK POPUP CARDS */
.tp-card {
    background: #ffffff; border: 1px solid #e8edf5;
    border-radius: 11px; padding: 10px 12px; margin-bottom: 6px; transition: border-color 0.15s;
}
.tp-card:last-child { margin-bottom: 0; }
.tp-card:hover { border-color: #bfdbfe; }
.tp-card-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 7px; margin-bottom: 2px; }
.tp-task-name {
    font-size: 12px; font-weight: 700; color: #0f172a; line-height: 1.4;
    overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;
}
.tp-badge { font-size: 9px; font-weight: 700; padding: 2px 7px; border-radius: 20px; white-space: nowrap; flex-shrink: 0; line-height: 1.7; }
.tp-badge-dev      { background: #eff6ff; color: #2563eb; }
.tp-badge-training { background: #fff7ed; color: #b45309; }
.tp-badge-design   { background: #fdf4ff; color: #9333ea; }
.tp-badge-other    { background: #f1f5f9; color: #475569; }
.tp-project {
    font-size: 10px; color: #94a3b8; display: flex; align-items: center; gap: 4px; margin-bottom: 8px;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.tp-project-dot { width: 3px; height: 3px; border-radius: 50%; background: #cbd5e1; flex-shrink: 0; }
.tp-card-bottom { display: flex; align-items: center; justify-content: space-between; gap: 5px; }
.tp-pill { display: inline-flex; align-items: center; gap: 4px; font-size: 10px; font-weight: 600; padding: 2px 8px; border-radius: 20px; }
.tp-pill-dot { width: 4px; height: 4px; border-radius: 50%; flex-shrink: 0; }
.tp-pill-running { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
.tp-pill-running .tp-pill-dot { background: #16a34a; }
.tp-pill-paused  { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
.tp-pill-paused .tp-pill-dot  { background: #d97706; }
.tp-pill-ended   { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.tp-pill-ended .tp-pill-dot   { background: #ef4444; }
.tp-actions { display: flex; gap: 4px; }
.tp-btn {
    display: inline-flex; align-items: center; gap: 3px; padding: 3px 8px;
    font-size: 10px; font-weight: 600; border-radius: 6px; border: 1px solid #e2e8f0;
    background: transparent; cursor: pointer; white-space: nowrap; font-family: inherit;
    line-height: 1.6; color: #374151; transition: all 0.12s;
}
.tp-btn:hover { background: #f8fafc; }
.tp-btn:active { transform: scale(0.97); }
.tp-btn:disabled { opacity: 0.4; cursor: not-allowed; }
.tp-btn-resume { border-color: #93c5fd; color: #2563eb; }
.tp-btn-resume:hover { background: #eff6ff; }
.tp-btn-pause  { border-color: #fde68a; color: #92400e; }
.tp-btn-pause:hover  { background: #fffbeb; }
.tp-btn-done   { border-color: #bbf7d0; color: #15803d; }
.tp-btn-done:hover   { background: #f0fdf4; }

/* Skeleton */
.tp-skeleton {
    background: linear-gradient(90deg,#f1f5f9 25%,#e8edf5 50%,#f1f5f9 75%);
    background-size: 200% 100%; animation: tp-shimmer 1.2s infinite; border-radius: 4px; margin-bottom: 5px;
}
@keyframes tp-shimmer { 0%{background-position:200% 0} 100%{background-position:-200% 0} }
.tp-empty { text-align: center; padding: 24px 16px; color: #94a3b8; font-size: 12px; line-height: 1.6; }

/* ══════════════════════════════════════
   PRECISION RESPONSIVE BREAKPOINTS
══════════════════════════════════════ */

/* Laptops & Medium Desktops (992px to 1199px) */
@media (max-width: 1199px) {
    .nav-face-btn .nav-face-btn-tag { display: none !important; }
    .nav-greeting-card .greet-sub { display: none !important; }
    .nav-greeting-card { padding: 4px 8px !important; }
}

/* Tablets & Mobile Devices (< 992px) */
@media (max-width: 991px) {
    .navbar {
        height: 60px !important;
        min-height: 60px !important;
    }
    .navbar .navbar-brand-wrapper {
        width: auto !important;
        min-width: 140px !important;
        max-width: 190px !important;
        height: 60px !important;
        padding: 0 14px !important;
        display: flex !important;
        align-items: center !important;
        justify-content: flex-start !important;
        border-right: none !important;
    }
    .navbar .navbar-brand-wrapper .brand-logo {
        display: inline-flex !important;
        align-items: center !important;
    }
    .navbar .navbar-brand-wrapper .brand-logo img {
        height: 32px !important;
        max-width: 155px !important;
        width: auto !important;
        object-fit: contain !important;
    }
    .navbar .navbar-brand-wrapper .brand-logo-mini {
        display: none !important;
    }
    .navbar .navbar-menu-wrapper {
        width: auto !important;
        flex: 1 1 auto !important;
        height: 60px !important;
        padding: 0 10px !important;
    }
    /* Hide sidebar button from navbar completely - now attached cleanly to sidebar */
    .sidebarBtn { 
        display: none !important; 
    }
    .nav-greeting-card { display: none !important; }

    /* Mobile offcanvas sidebar - slides smoothly from left */
    .sidebar-offcanvas {
        position: fixed !important;
        max-height: calc(100vh - 60px) !important;
        height: calc(100vh - 60px) !important;
        top: 60px !important;
        bottom: 0 !important;
        left: -260px !important;
        right: auto !important;
        width: 260px !important;
        z-index: 1035 !important;
        background: #ffffff !important;
        box-shadow: 4px 0 24px rgba(15, 23, 42, 0.15) !important;
        transition: left 0.25s cubic-bezier(0.4, 0, 0.2, 1) !important;
        overflow-y: auto !important;
    }
    .sidebar-offcanvas.active {
        left: 0 !important;
    }

    /* Compact Face Punch on tablet */
    .nav-face-btn {
        padding: 0 11px !important;
        height: 36px !important;
    }
    .nav-face-btn .nav-face-btn-tag {
        display: none !important;
    }

    .nav-profile-chevron {
        display: none !important;
    }

    /* Popups fit viewport width cleanly */
    .nav-popup {
        width: calc(100vw - 20px) !important;
        max-width: 420px !important;
        left: 10px !important;
        right: 10px !important;
        top: 66px !important;
        margin: 0 auto !important;
        transform: none !important;
    }
}

/* Phones (< 768px) */
@media (max-width: 767px) {
    .navbar .navbar-menu-wrapper {
        padding: 0 10px !important;
    }
    .navbar-nav-right {
        gap: 6px !important;
    }
    /* Compact circular Face Punch button with camera icon */
    .nav-face-btn {
        width: 36px !important;
        height: 36px !important;
        padding: 0 !important;
        border-radius: 50% !important;
        justify-content: center !important;
    }
    .nav-face-btn .nav-face-btn-text,
    .nav-face-btn .nav-face-btn-tag {
        display: none !important;
    }
    .nav-face-btn i {
        font-size: 14px !important;
        margin: 0 !important;
    }
}

/* Small Phones (< 576px) */
@media (max-width: 575px) {
    .navbar .navbar-brand-wrapper {
        width: auto !important;
        min-width: 120px !important;
        max-width: 155px !important;
        padding: 0 8px !important;
    }
    .navbar .navbar-brand-wrapper .brand-logo {
        display: inline-flex !important;
    }
    .navbar .navbar-brand-wrapper .brand-logo img {
        height: 28px !important;
        max-width: 135px !important;
    }
    .navbar .navbar-brand-wrapper .brand-logo-mini {
        display: none !important;
    }
    .navbar .navbar-menu-wrapper {
        width: auto !important;
        flex: 1 1 auto !important;
        padding: 0 6px !important;
    }
    .navbar-nav-right {
        gap: 5px !important;
    }
    .nav-icon-btn,
    .navbar .navbar-menu-wrapper .navbar-toggler.nav-icon-btn {
        width: 34px !important;
        height: 34px !important;
        border-radius: 9px !important;
    }
    .nav-face-btn {
        width: 34px !important;
        height: 34px !important;
    }
    .nav-face-btn i {
        font-size: 13px !important;
    }
    .nav-profile-avatar-wrap,
    .nav-profile-avatar-img {
        width: 32px !important;
        height: 32px !important;
    }
    .nav-item-android {
        display: none !important; /* Always accessible inside Profile dropdown */
    }
    .navbar-dropdown {
        width: min(290px, calc(100vw - 20px)) !important;
        right: 0 !important;
    }
    .nav-popup {
        width: calc(100vw - 16px) !important;
        left: 8px !important;
        right: 8px !important;
        top: 64px !important;
    }
}
</style>

<nav class="navbar col-lg-12 col-12 p-0 fixed-top d-flex flex-row">
    <!-- Brand Logo Area (Exact Alignment with Sidebar) -->
    <div class="navbar-brand-wrapper">
        <a class="brand-logo" href="<?= htmlspecialchars($userDashboardUrl) ?>">
            <img src="../images/logos/SETTribe-logo.png" alt="SETTribe Logo" />
        </a>
        <a class="brand-logo-mini" href="<?= htmlspecialchars($userDashboardUrl) ?>">
            <img src="../images/logos/SETTribe-logo.png" alt="SETTribe Logo" />
        </a>
    </div>

    <!-- Main Navigation Content Wrapper -->
    <div class="navbar-menu-wrapper">
        <!-- Left Cluster: Greeting card -->
        <div class="nav-left-cluster">
            <!-- Desktop Greeting & Attendance Status Badge -->
            <div class="nav-greeting-card">
                <div class="greet-content">
                    <div class="greet-title">
                        Hello, <span style="color:#2563eb;"><?= htmlspecialchars($firstName); ?></span>
                    </div>
                    <div class="greet-sub" style="<?= $nav_hasPunchedIn ? 'color:#059669;' : 'color:#d97706;' ?>">
                        <span class="status-beacon-dot <?= $nav_hasPunchedIn ? 'punched' : 'pending' ?>"></span>
                        <span><?= $nav_hasPunchedIn ? 'Punched In' : 'Punch-In Pending' ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Cluster: Action Buttons -->
        <ul class="navbar-nav navbar-nav-right">

            <?php
            $runningCount = 0;
            $runQuery = mysqli_query($con, "SELECT COUNT(*) AS total FROM TaskEntryNew WHERE userId='{$_SESSION['userId']}' AND status='Running'");
            if ($runQuery) { $runningCount = (int)mysqli_fetch_assoc($runQuery)['total']; }

            $activeCount = 0;
            $activeQuery = mysqli_query($con, "SELECT COUNT(*) AS total FROM TaskEntryNew WHERE userId='{$_SESSION['userId']}' AND status IN ('Running','Paused')");
            if ($activeQuery) { $activeCount = (int)mysqli_fetch_assoc($activeQuery)['total']; }

            if (in_array($userRole, ['junior_intern', 'senior_intern', 'resourceallocator']) && strtolower(trim($_SESSION['designation'] ?? '')) !== 'receptionist') { ?>

                <!-- Active Tasks Popup Trigger -->
                <li class="nav-item">
                    <button class="nav-icon-btn" id="taskPopupToggle" title="Active Tasks" type="button">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"
                             fill="none" stroke="#475569" stroke-width="2"
                             stroke-linecap="round" stroke-linejoin="round">
                            <path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/>
                            <rect x="9" y="3" width="6" height="4" rx="1.5"/>
                            <polyline points="9 12 11 14 15 10"/>
                            <line x1="9" y1="17" x2="15" y2="17"/>
                        </svg>
                        <span class="nav-icon-badge green" id="taskBadgeNav"
                              style="<?= $activeCount > 0 ? '' : 'display:none;' ?>">
                            <?= $activeCount > 0 ? $activeCount : '' ?>
                        </span>
                    </button>
                </li>

            <?php } ?> 

            <!-- Notifications Bell Trigger -->
            <li class="nav-item">
                <button class="nav-icon-btn" id="leaveNotifToggle" title="Notifications" type="button">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"
                         fill="none" stroke="#475569" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                        <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                        <line x1="12" y1="2" x2="12" y2="4"/>
                    </svg>
                    <span class="nav-icon-badge" id="leaveNotifBadge"
                          style="<?= $nav_unreadNotifCount > 0 ? '' : 'display:none;' ?>">
                        <?= $nav_unreadNotifCount > 0 ? ($nav_unreadNotifCount > 99 ? '99+' : $nav_unreadNotifCount) : '' ?>
                    </span>
                </button>
            </li>

            <!-- Android App APK Download Button -->
            <li class="nav-item nav-item-android">
                <a href="../apks/AMS%20-%20APK.apk" download class="nav-icon-btn" title="Download Android App (APK)">
                    <i class="fab fa-android" style="font-size: 1.2rem; color: #10b981;"></i>
                </a>
            </li>

            <!-- Navbar Theme Toggle Button (Light / Dark Mode Only) -->
            <li class="nav-item nav-theme-item">
                <button class="nav-icon-btn nav-theme-toggle-btn" id="navThemeToggleBtn" title="Toggle Light / Dark Mode" type="button" aria-label="Toggle Theme Mode">
                    <i class="fa-solid fa-moon" id="navThemeCurrentIcon"></i>
                </button>
            </li>

            <!-- User Profile Dropdown -->
            <li class="nav-item nav-profile dropdown">
                <a class="nav-profile-trigger-btn dropdown-toggle" href="#" id="profileDropdown" data-toggle="dropdown" aria-expanded="false">
                    <div class="nav-profile-avatar-wrap">
                        <img src="../profile-photos/<?php echo (!empty($profilePhoto) ? htmlspecialchars($profilePhoto) : 'default.png'); ?>" 
                             alt="profile" 
                             class="nav-profile-avatar-img <?= $nav_hasPunchedIn ? 'nav-ring-punched' : 'nav-ring-pending' ?>"
                             onerror="this.src='../profile-photos/default.png'" />
                    </div>
                    <i class="fas fa-chevron-down nav-profile-chevron d-none d-xl-inline"></i>
                </a>
                
                <div class="dropdown-menu dropdown-menu-right navbar-dropdown" aria-labelledby="profileDropdown">
                    <!-- Dropdown Header Banner -->
                    <div class="nav-dropdown-header">
                        <img src="../profile-photos/<?php echo (!empty($profilePhoto) ? htmlspecialchars($profilePhoto) : 'default.png'); ?>" 
                             alt="profile" 
                             class="nav-dd-avatar" 
                             onerror="this.src='../profile-photos/default.png'" />
                        <div class="nav-dd-info">
                            <div class="nav-dd-name" title="<?= htmlspecialchars($fullName) ?>">
                                <?= htmlspecialchars($fullName) ?>
                            </div>
                            <span class="nav-dd-role-badge">
                                <?= htmlspecialchars($userRoleDisplay) ?>
                            </span>
                        </div>
                    </div>

                    <!-- Dropdown Nav Links -->
                    <div class="nav-dropdown-menu-list">
                        <a href="editProfile.php" class="nav-dropdown-link">
                            <i class="fa-regular fa-user"></i>
                            <span>Edit Profile</span>
                        </a>

                        <a href="qrCodeScanner_copy.php" class="nav-dropdown-link">
                            <i class="fa-solid fa-qrcode text-primary"></i>
                            <span>My Attendance QR</span>
                        </a>

                        <a href="face_verification.php" class="nav-dropdown-link">
                            <i class="fa-solid fa-id-card-clip text-info"></i>
                            <span>3D Face Verification</span>
                        </a>

                        <a href="<?= htmlspecialchars($userDashboardUrl) ?>" class="nav-dropdown-link">
                            <i class="fa-solid fa-gauge-high"></i>
                            <span>My Dashboard</span>
                        </a>

                        <a href="changePassword.php" class="nav-dropdown-link">
                            <i class="fa-solid fa-lock"></i>
                            <span>Change Password</span>
                        </a>

                        <a href="../apks/AMS%20-%20APK.apk" download class="nav-dropdown-link d-md-none">
                            <i class="fab fa-android text-success"></i>
                            <span>Download Android App</span>
                        </a>

                        <div class="nav-dropdown-divider"></div>

                        <button type="button" class="nav-dropdown-link logout-btn logout-btn-link">
                            <i class="ti-power-off"></i>
                            <span>Logout</span>
                        </button>
                    </div>
                </div>
            </li>
        </ul>
    </div>
</nav>

<div id="navBackdrop"></div>
<div id="mobileSidebarBackdrop"></div>

<!-- ══════════════════════════════════════
     TASK POPUP
══════════════════════════════════════ -->
<div class="nav-popup" id="taskPopup">
    <div class="nav-popup-header">
        <div class="nav-popup-header-left">
            <div class="nav-popup-icon-wrap">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none"
                     stroke="#2563eb" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/>
                    <rect x="9" y="3" width="6" height="4" rx="1.5"/>
                    <polyline points="9 12 11 14 15 10"/>
                    <line x1="9" y1="17" x2="15" y2="17"/>
                </svg>
            </div>
            <div>
                <div class="nav-popup-title">
                    Active Tasks
                    <span class="nav-popup-count" id="tpHeadCount"><?= $activeCount ?></span>
                </div>
                <div class="nav-popup-subtitle">Running &amp; paused tasks</div>
            </div>
        </div>
        <button class="nav-popup-close" id="taskPopupClose">&#x2715;</button>
    </div>

    <div class="tp-filter-row">
        <button class="tp-filter-pill tp-active-run" data-f="Running" onclick="window._tpFilter('Running',this)">
            <span class="tp-fp-dot"></span>Running<span class="tp-fp-num" id="tpCount-Running">0</span>
        </button>
        <button class="tp-filter-pill" data-f="Paused" onclick="window._tpFilter('Paused',this)">
            <span class="tp-fp-dot"></span>Paused<span class="tp-fp-num" id="tpCount-Paused">0</span>
        </button>
        <button class="tp-filter-pill" data-f="End" onclick="window._tpFilter('End',this)">
            <span class="tp-fp-dot"></span>Ended<span class="tp-fp-num" id="tpCount-End">0</span>
        </button>
    </div>

    <div class="nav-popup-body" id="tpBody">
        <div class="tp-card">
            <div class="tp-skeleton" style="width:62%;height:12px;margin-bottom:7px;"></div>
            <div class="tp-skeleton" style="width:38%;height:10px;margin-bottom:10px;"></div>
            <div style="display:flex;gap:5px;">
                <div class="tp-skeleton" style="width:52px;height:20px;border-radius:20px;"></div>
                <div class="tp-skeleton" style="width:55px;height:20px;border-radius:6px;margin-left:auto;"></div>
                <div class="tp-skeleton" style="width:46px;height:20px;border-radius:6px;"></div>
            </div>
        </div>
        <div class="tp-card">
            <div class="tp-skeleton" style="width:74%;height:12px;margin-bottom:7px;"></div>
            <div class="tp-skeleton" style="width:50%;height:10px;margin-bottom:10px;"></div>
            <div style="display:flex;gap:5px;">
                <div class="tp-skeleton" style="width:52px;height:20px;border-radius:20px;"></div>
                <div class="tp-skeleton" style="width:55px;height:20px;border-radius:6px;margin-left:auto;"></div>
                <div class="tp-skeleton" style="width:46px;height:20px;border-radius:6px;"></div>
            </div>
        </div>
    </div>

    <div class="nav-popup-footer">
        <a href="taskEntry.php" id="tpFooterLink">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"/>
                <polyline points="12 6 12 12 16 14"/>
            </svg>
            <span id="tpFooterTxt">Go to Task Table</span>
        </a>
    </div>
</div>

<!-- ══════════════════════════════════════
     LEAVE NOTIFICATION POPUP
══════════════════════════════════════ -->
<div class="nav-popup" id="leaveNotifPopup" style="width:340px;">
    <div class="nav-popup-header">
        <div class="nav-popup-header-left">
            <div class="nav-popup-icon-wrap">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none"
                     stroke="#2563eb" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                    <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                    <line x1="12" y1="2" x2="12" y2="4"/>
                </svg>
            </div>
            <div>
                <div class="nav-popup-title">
                    Notifications
                    <span class="nav-popup-count" id="leaveNotifHeadCount"
                          style="<?= $nav_unreadNotifCount > 0 ? '' : 'display:none;' ?>">
                        <?= $nav_unreadNotifCount ?>
                    </span>
                </div>
                <div class="nav-popup-subtitle">Latest updates &amp; alerts</div>
            </div>
        </div>
        <button class="nav-popup-close" id="leaveNotifClose">&#x2715;</button>
    </div>

    <div class="nav-popup-body" id="leaveNotifBody" style="padding:10px; max-height:320px; overflow-y:auto; background:#f8fafc;">
        <div id="leaveNotifSkeleton">
            <div style="background:#fff;border:1px solid #e8edf5;border-radius:11px;padding:11px;margin-bottom:7px;">
                <div class="tp-skeleton" style="width:55%;height:12px;margin-bottom:7px;"></div>
                <div class="tp-skeleton" style="width:85%;height:10px;margin-bottom:5px;"></div>
                <div class="tp-skeleton" style="width:40%;height:9px;"></div>
            </div>
            <div style="background:#fff;border:1px solid #e8edf5;border-radius:11px;padding:11px;">
                <div class="tp-skeleton" style="width:45%;height:12px;margin-bottom:7px;"></div>
                <div class="tp-skeleton" style="width:75%;height:10px;margin-bottom:5px;"></div>
                <div class="tp-skeleton" style="width:35%;height:9px;"></div>
            </div>
        </div>
        <div id="leaveNotifList" style="display:none;"></div>
    </div>

    <div class="nav-popup-footer">
        <a href="notifications.php">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
            </svg>
            View all notifications
        </a>
    </div>
</div>



<script>
/* ════════════════════════════════
   PHP FLAGS — punch-in state (regular + tour)
════════════════════════════════ */
var NAV_PUNCHED_IN = <?php echo $nav_hasPunchedIn ? 'true' : 'false'; ?>;
var NAV_EXPECTED_PUNCH_IN = '<?php echo $nav_expectedPunchIn; ?>';
var NAV_EXPECTED_PUNCH_OUT = '<?php echo $nav_expectedPunchOut; ?>';

/* ════════════════════════════════
   PUNCH-IN GUARD — blocks task actions if not punched in
════════════════════════════════ */
function navPunchInGuard() {
    if (!NAV_PUNCHED_IN) {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                icon: 'warning',
                title: 'Punch In Required',
                html: 'You have not punched in today.<br>Please <strong>punch in</strong> before performing any task actions.',
                confirmButtonColor: '#103d9e',
                confirmButtonText: 'OK'
            });
        } else {
            alert('You have not punched in today. Please punch in before performing any task actions.');
        }
        return true;  // blocked
    }
    return false; // allowed
}

/* ════════════════════════════════
   LOGOUT
════════════════════════════════ */
document.addEventListener("click", function(e) {
    const btn = e.target.closest(".logout-btn");
    if (!btn) return;
    e.preventDefault(); e.stopPropagation();

    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Are you sure?', text: "You will be logged out!", icon: 'question',
            showCancelButton: true, confirmButtonText: 'Yes, logout', cancelButtonText: 'Cancel',
            customClass: { icon: 'swal2-icon-question-red' }
        }).then(r => { if (r.isConfirmed) window.location.href = '../pages/logout.php'; });
    } else {
        if (confirm('Are you sure you want to log out?')) {
            window.location.href = '../pages/logout.php';
        }
    }
});

/* ════════════════════════════════
   PROFILE DROPDOWN
════════════════════════════════ */
(function() {
    const profileBtn   = document.getElementById("profileDropdown");
    const dropdownMenu = document.querySelector(".navbar-dropdown");
    if (!profileBtn || !dropdownMenu) return;
    profileBtn.addEventListener("click", function(e) {
        e.preventDefault(); e.stopPropagation();
        dropdownMenu.classList.toggle("show");
    });
    document.addEventListener("click", function(e) {
        if (!dropdownMenu.contains(e.target) && e.target !== profileBtn)
            dropdownMenu.classList.remove("show");
    });
})();

/* ════════════════════════════════
   GENERIC POPUP TOGGLE
════════════════════════════════ */
function makePopupToggle(toggleId, popupId, closeId, onOpen) {
    const toggle   = document.getElementById(toggleId);
    const popup    = document.getElementById(popupId);
    const close    = document.getElementById(closeId);
    const backdrop = document.getElementById('navBackdrop');
    if (!toggle || !popup) return;

    function openPopup() {
        document.querySelectorAll('.nav-popup').forEach(p => { if (p !== popup) p.style.display = 'none'; });
        popup.style.display = 'block';
        if (backdrop) backdrop.style.display = 'block';
        if (typeof onOpen === 'function') onOpen();
    }
    function closePopup() {
        popup.style.display = 'none';
        const anyOpen = [...document.querySelectorAll('.nav-popup')].some(p => p.style.display === 'block');
        if (backdrop && !anyOpen) backdrop.style.display = 'none';
    }
    function isOpen() { return popup.style.display === 'block'; }

    toggle.addEventListener('click', function(e) { e.stopPropagation(); isOpen() ? closePopup() : openPopup(); });
    if (close) close.addEventListener('click', function(e) { e.stopPropagation(); closePopup(); });
    if (backdrop) backdrop.addEventListener('click', function() { closePopup(); });
    document.addEventListener('click', function(e) {
        if (!popup.contains(e.target) && !toggle.contains(e.target)) closePopup();
    });
    document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closePopup(); });
}

/* ════════════════════════════════
   TASK POPUP — AJAX
════════════════════════════════ */
(function() {
    var DRAWER_URL   = '<?php echo rtrim($partialsUrl, "/"); ?>';
    var tpBody       = document.getElementById('tpBody');
    var tpHeadCount  = document.getElementById('tpHeadCount');
    var taskBadgeNav = document.getElementById('taskBadgeNav');

    var ICON_PLAY  = '<svg width="8" height="8" viewBox="0 0 12 12" fill="currentColor"><polygon points="3,1 11,6 3,11"/></svg>';
    var ICON_PAUSE = '<svg width="8" height="8" viewBox="0 0 12 12" fill="currentColor"><rect x="2" y="1" width="3" height="10" rx="1"/><rect x="7" y="1" width="3" height="10" rx="1"/></svg>';
    var ICON_CHECK = '<svg width="8" height="8" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="2,6 5,9 10,3"/></svg>';
    var ICON_LOCK  = '<svg width="8" height="8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>';

    var allTasks     = [];
    var activeFilter = 'Running';

    var TP_FOOTER = {
        Running: { txt: 'Go to tasks', href: 'taskEntry.php' },
        Paused:  { txt: 'Go to tasks', href: 'taskEntry.php' },
        End:     { txt: 'Go to tasks', href: 'taskEntry.php' }
    };

    function esc(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

    function getBadge(task, proj) {
        var t = (task||'').toLowerCase(), p = (proj||'').toLowerCase();
        if (t.includes('develop')||t.includes('cod')||t.includes('bug')||t.includes('fix')||t.includes('api')||p.includes('ams')||p.includes('develop'))
            return { cls:'tp-badge-dev', label:'Dev' };
        if (t.includes('train')||t.includes('session')||t.includes('class')||t.includes('msw')||p.includes('train')||p.includes('msw'))
            return { cls:'tp-badge-training', label:'Training' };
        if (t.includes('design')||t.includes('ui')||t.includes('ux')||t.includes('figma'))
            return { cls:'tp-badge-design', label:'Design' };
        return { cls:'tp-badge-other', label:'Task' };
    }

    function skeleton() {
        return '<div class="tp-card">' +
            '<div class="tp-skeleton" style="width:62%;height:12px;margin-bottom:7px;"></div>' +
            '<div class="tp-skeleton" style="width:40%;height:10px;margin-bottom:10px;"></div>' +
            '<div style="display:flex;gap:5px;">' +
            '<div class="tp-skeleton" style="width:52px;height:20px;border-radius:20px;"></div>' +
            '<div class="tp-skeleton" style="width:55px;height:20px;border-radius:6px;margin-left:auto;"></div>' +
            '<div class="tp-skeleton" style="width:46px;height:20px;border-radius:6px;"></div>' +
            '</div></div>';
    }

    function getPunchWarningHtml() {
        if (!NAV_PUNCHED_IN) {
            return '<div class="tp-punch-warning">' +
                '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2.2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>' +
                '<span>You have <strong>not punched in</strong> yet. Task actions are disabled.</span>' +
                '</div>';
        }
        return '';
    }

    function updateCounts(tasks) {
        var cRun = tasks.filter(function(t){ return t.status === 'Running'; }).length;
        var cPau = tasks.filter(function(t){ return t.status === 'Paused';  }).length;
        var cEnd = tasks.filter(function(t){ return t.status === 'Finished' || t.status === 'End'; }).length;
        var el;
        el = document.getElementById('tpCount-Running'); if (el) el.textContent = cRun;
        el = document.getElementById('tpCount-Paused');  if (el) el.textContent = cPau;
        el = document.getElementById('tpCount-End');     if (el) el.textContent = cEnd;
        var active = cRun + cPau;
        if (tpHeadCount) tpHeadCount.textContent = active;
        if (taskBadgeNav) {
            taskBadgeNav.textContent   = active > 0 ? active : '';
            taskBadgeNav.style.display = active > 0 ? 'block' : 'none';
        }
    }

    function renderFilter() {
        var blocked  = !NAV_PUNCHED_IN;
        var filtered = allTasks.filter(function(t) {
            if (activeFilter === 'Running') return t.status === 'Running';
            if (activeFilter === 'Paused')  return t.status === 'Paused';
            if (activeFilter === 'End')     return t.status === 'Finished' || t.status === 'End';
            return true;
        });

        var warningHtml = getPunchWarningHtml();

        if (!filtered.length) {
            tpBody.innerHTML = warningHtml +
                '<div class="tp-empty">' +
                '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#e2e8f0" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin:0 auto 8px;display:block;">' +
                '<path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/>' +
                '<rect x="9" y="3" width="6" height="4" rx="1.5"/><polyline points="9 12 11 14 15 10"/>' +
                '<line x1="9" y1="17" x2="15" y2="17"/></svg>No tasks here</div>';
            return;
        }

        var cards = filtered.map(function(t) {
            var isRunning = t.status === 'Running';
            var isEnd     = t.status === 'Finished' || t.status === 'End';
            var badge     = getBadge(t.task, t.projectName);
            var pillCls   = isRunning ? 'tp-pill-running' : isEnd ? 'tp-pill-ended' : 'tp-pill-paused';
            var pillLbl   = isRunning ? 'Running'         : isEnd ? 'Ended'         : 'Paused';
            var actionBtn = '', doneBtn = '';

            if (!isEnd) {
                if (blocked) {
                    actionBtn = isRunning
                        ? '<button class="tp-btn tp-btn-pause"  type="button" onclick="navPunchInGuard()" style="opacity:0.45;">' + ICON_LOCK + ' Pause</button>'
                        : '<button class="tp-btn tp-btn-resume" type="button" onclick="navPunchInGuard()" style="opacity:0.45;">' + ICON_LOCK + ' Resume</button>';
                    doneBtn   = '<button class="tp-btn tp-btn-done" type="button" onclick="navPunchInGuard()" style="opacity:0.45;">' + ICON_LOCK + ' Done</button>';
                } else {
                    actionBtn = isRunning
                        ? '<button class="tp-btn tp-btn-pause"  type="button" onclick="window._tpUpdate('+t.taskId+',\'Paused\')">'   + ICON_PAUSE + ' Pause</button>'
                        : '<button class="tp-btn tp-btn-resume" type="button" onclick="window._tpUpdate('+t.taskId+',\'Running\')">'  + ICON_PLAY  + ' Resume</button>';
                    doneBtn   = '<button class="tp-btn tp-btn-done" type="button" onclick="window._tpUpdate('+t.taskId+',\'Finished\')">' + ICON_CHECK + ' Done</button>';
                }
            }

            return '<div class="tp-card">' +
                '<div class="tp-card-top">' +
                '<div class="tp-task-name" title="'+esc(t.task)+'">'+esc(t.task)+'</div>' +
                '<span class="tp-badge '+badge.cls+'">'+badge.label+'</span>' +
                '</div>' +
                '<div class="tp-project"><span class="tp-project-dot"></span>'+esc(t.projectName||'No project')+'</div>' +
                '<div class="tp-card-bottom">' +
                '<span class="tp-pill '+pillCls+'"><span class="tp-pill-dot"></span>'+pillLbl+'</span>' +
                '<div class="tp-actions">'+actionBtn+doneBtn+'</div>' +
                '</div></div>';
        }).join('');

        tpBody.innerHTML = warningHtml + cards;
    }

    window._tpFilter = function(f, btn) {
        activeFilter = f;
        document.querySelectorAll('.tp-filter-pill').forEach(function(b) {
            b.classList.remove('tp-active-run', 'tp-active-pau', 'tp-active-end');
        });
        btn.classList.add(f === 'Running' ? 'tp-active-run' : f === 'Paused' ? 'tp-active-pau' : 'tp-active-end');
        var fc = TP_FOOTER[f] || TP_FOOTER.Running;
        var fl = document.getElementById('tpFooterLink');
        var ft = document.getElementById('tpFooterTxt');
        if (fl) fl.href = fc.href;
        if (ft) ft.textContent = fc.txt;
        renderFilter();
    };

    function loadTasks() {
        tpBody.innerHTML = skeleton() + skeleton();
        fetch(DRAWER_URL + '/task_drawer_data.php', { credentials: 'same-origin' })
            .then(function(r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(function(tasks) {
                if (tasks.error) {
                    tpBody.innerHTML = '<p style="color:#ef4444;text-align:center;font-size:12px;padding:16px;">DB error: ' + esc(tasks.error) + '</p>';
                    return;
                }
                allTasks = tasks;
                updateCounts(tasks);
                renderFilter();
            })
            .catch(function(err) {
                tpBody.innerHTML = '<p style="color:#ef4444;text-align:center;font-size:12px;padding:16px;">Failed to load.<br><small>'+err.message+'</small></p>';
            });
    }

    makePopupToggle('taskPopupToggle', 'taskPopup', 'taskPopupClose', loadTasks);

    window._tpUpdate = function(taskId, newStatus) {
        if (navPunchInGuard()) return;

        var btns = tpBody.querySelectorAll('.tp-btn');
        btns.forEach(function(b) { b.disabled = true; });

        fetch(DRAWER_URL + '/task_drawer_update.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'taskId='+encodeURIComponent(taskId)+'&status='+encodeURIComponent(newStatus)
        })
        .then(function(r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.text();
        })
        .then(function(text) {
            var data;
            try {
                data = JSON.parse(text);
            } catch(e) {
                console.error('Non-JSON from task_drawer_update.php:', text);
                throw new Error('Server error. Check PHP logs.');
            }
            if (data.success) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'success',
                        text: newStatus === 'Finished' ? 'Task marked as done!'
                            : newStatus === 'Running'  ? 'Task resumed!'
                            : 'Task paused!',
                        timer: 1000, showConfirmButton: false, toast: true, position: 'top-end'
                    });
                }
                setTimeout(loadTasks, 1100);
            } else {
                alert(data.msg || 'Update failed.');
                btns.forEach(function(b) { b.disabled = false; });
            }
        })
        .catch(function(err) {
            alert('Error: ' + err.message);
            btns.forEach(function(b) { b.disabled = false; });
        });
    };

    function refreshBadge() {
        fetch(DRAWER_URL + '/task_drawer_data.php', { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(tasks) {
                if (!Array.isArray(tasks)) return;
                allTasks = tasks;
                updateCounts(tasks);
            })
            .catch(function() {});
    }

    refreshBadge();
    setInterval(refreshBadge, 120000); // ✅ OPTIMIZED: Slowed down from 30s to 120s
})();

/* ════════════════════════════════
   LEAVE NOTIFICATION POPUP — AJAX
════════════════════════════════ */
(function () {
    var leaveNotifBadge = document.getElementById('leaveNotifBadge');
    var leaveNotifHead  = document.getElementById('leaveNotifHeadCount');
    var leaveNotifList  = document.getElementById('leaveNotifList');
    var leaveNotifSkel  = document.getElementById('leaveNotifSkeleton');

    function esc(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

    function updateLeaveBadge(count) {
        if (count > 0) {
            leaveNotifBadge.textContent   = count > 99 ? '99+' : count;
            leaveNotifBadge.style.display = 'block';
            leaveNotifHead.textContent    = count;
            leaveNotifHead.style.display  = 'inline-block';
        } else {
            leaveNotifBadge.style.display = 'none';
            leaveNotifHead.style.display  = 'none';
        }
    }

    var currentNotifPage = 1;
    var totalNotifPages = 1;

    function renderLeaveNotifs(data) {
        leaveNotifSkel.style.display = 'none';
        leaveNotifList.style.display = 'block';

        if (!data.notifications || data.notifications.length === 0) {
            leaveNotifList.innerHTML =
                '<div class="tp-empty">' +
                '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#e2e8f0" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin:0 auto 8px;display:block;">' +
                '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>' +
                '<path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>' +
                'No notifications yet</div>';
            return;
        }

        totalNotifPages = data.total_pages || 1;
        currentNotifPage = data.current_page || 1;

        var html = '';
        var localMaxId = typeof lastNotifId !== 'undefined' ? parseInt(lastNotifId) : 0;

        data.notifications.forEach(function (n) {
            if (n.id > localMaxId) localMaxId = n.id;

            var isUnread  = n.is_read === 0;
            var cardClass = isUnread ? 'lv-notif-card unread' : 'lv-notif-card';
            var dotHtml   = isUnread ? '<span class="lv-notif-dot"></span>' : '';
            var markBtn   = isUnread
                ? '<button class="lv-notif-markread" onclick="window._lvMarkRead(' + n.id + ')">Mark as read</button>'
                : '';

            var joinBtn = '';
            if (n.link && n.link.trim() !== '' && n.type === 'meeting') {
                joinBtn =
                    '<a href="' + esc(n.link) + '" target="_blank" rel="noopener noreferrer" ' +
                    'onclick="event.stopPropagation();" ' +
                    'style="display:inline-flex;align-items:center;gap:5px;' +
                    'background:#2563eb;color:#fff;border:none;border-radius:6px;' +
                    'padding:4px 11px;font-size:10px;font-weight:600;text-decoration:none;' +
                    'cursor:pointer;transition:background .15s;white-space:nowrap;flex-shrink:0;"' +
                    'onmouseover="this.style.background=\'#1d4ed8\'" ' +
                    'onmouseout="this.style.background=\'#2563eb\'">' +
                    '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
                    'stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">' +
                    '<polygon points="23 7 16 12 23 17 23 7"/>' +
                    '<rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>' +
                    'Join Meeting</a>';
            }

            html +=
                '<div class="' + cardClass + '" data-notif-id="' + n.id + '">' +
                '<button class="lv-notif-delete" onclick="window._lvDeleteNotif(' + n.id + ')" title="Delete">✕</button>' +
                '<div style="display:flex;align-items:flex-start;gap:3px;">' +
                dotHtml +
                '<div style="flex:1;min-width:0;padding-right:20px;">' +
                '<div class="lv-notif-title">' + esc(n.title) + '</div>' +
                '<div class="lv-notif-message">' + esc(n.message) + '</div>' +
                '<div style="display:flex;align-items:center;justify-content:space-between;margin-top:6px;gap:6px;">' +
                '<div style="display:flex;align-items:center;gap:6px;flex:1;min-width:0;">' +
                '<span class="lv-notif-time">' + esc(n.created_at) + '</span>' +
                markBtn +
                '</div>' +
                joinBtn +
                '</div>' +
                '</div></div></div>';
        });

        var paginationHtml = '';
        if (totalNotifPages > 1) {
            var prevDisabled = currentNotifPage <= 1 ? 'disabled style="opacity:0.5;cursor:not-allowed;"' : 'onclick="window._lvChangePage(' + (currentNotifPage - 1) + ')"';
            var nextDisabled = currentNotifPage >= totalNotifPages ? 'disabled style="opacity:0.5;cursor:not-allowed;"' : 'onclick="window._lvChangePage(' + (currentNotifPage + 1) + ')"';

            paginationHtml = '<div style="display:flex; justify-content:space-between; align-items:center; padding: 10px 5px; font-size: 12px; color: #64748b;">' +
                '<button class="nav-icon-btn" style="width:28px;height:28px;padding:0;" ' + prevDisabled + ' title="Previous Page"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg></button>' +
                '<span>Page ' + currentNotifPage + ' of ' + totalNotifPages + '</span>' +
                '<button class="nav-icon-btn" style="width:28px;height:28px;padding:0;" ' + nextDisabled + ' title="Next Page"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg></button>' +
                '</div>';
        }

        if (typeof lastNotifId !== 'undefined' && localMaxId > lastNotifId) {
            lastNotifId = localMaxId;
            var keyToSave = (typeof userNotifKey !== 'undefined') ? userNotifKey : 'ams_last_notif_id';
            localStorage.setItem(keyToSave, lastNotifId);
        }

        leaveNotifList.innerHTML = html + paginationHtml;
        updateLeaveBadge(data.unread_count);
    }

    window._lvChangePage = function(page) {
        currentNotifPage = page;
        fetchLeaveNotifs(page);
    };

    function fetchLeaveNotifs(page) {
        if (!page) page = currentNotifPage;
        leaveNotifSkel.style.display = 'block';
        leaveNotifList.style.display = 'none';
        fetch('../ajax/fetch_nav_notifications.php?page=' + page, { credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(function (data) { renderLeaveNotifs(data); })
            .catch(function (err) {
                leaveNotifSkel.style.display = 'none';
                leaveNotifList.style.display = 'block';
                leaveNotifList.innerHTML = '<p style="color:#ef4444;text-align:center;font-size:12px;padding:16px;">Failed to load.<br><small>' + err.message + '</small></p>';
            });
    }

    window._lvMarkRead = function (notifId) {
        var fd = new FormData();
        fd.append('notificationId', notifId);
        fetch('<?php echo rtrim($ajaxUrl, "/"); ?>/mark_notification_read.php', { method: 'POST', credentials: 'same-origin', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (res) { if (res.success) fetchLeaveNotifs(currentNotifPage); })
            .catch(function () {});
    };

    /* ── Delete notification — NO SweetAlert toast ── */
    window._lvDeleteNotif = function (notifId) {
        var card = document.querySelector('[data-notif-id="' + notifId + '"]');
        if (!card) return;
        card.classList.add('deleting');

        var fd = new FormData();
        fd.append('notificationId', notifId);

        fetch('<?php echo rtrim($ajaxUrl, "/"); ?>/delete_notification.php', {
            method: 'POST', credentials: 'same-origin', body: fd
        })
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function (res) {
            if (res.success) {
                // Animate out then silently refresh — no SweetAlert
                card.style.transition = 'opacity 0.2s, transform 0.2s';
                card.style.opacity    = '0';
                card.style.transform  = 'translateX(20px)';
                setTimeout(function() { fetchLeaveNotifs(currentNotifPage); }, 220);
            } else {
                card.classList.remove('deleting');
            }
        })
        .catch(function () {
            card.classList.remove('deleting');
        });
    };

    // ── Bell toggle — wire up open/close ────────────────────────────────
    makePopupToggle('leaveNotifToggle', 'leaveNotifPopup', 'leaveNotifClose', function() {
        currentNotifPage = 1;
        fetchLeaveNotifs(1);
    });

    // ── Request Notification Permission on first interaction ────────────
    function requestNotifPermission() {
        if ("Notification" in window && Notification.permission === "default") {
            Notification.requestPermission();
        }
        document.removeEventListener('click', requestNotifPermission);
    }
    document.addEventListener('click', requestNotifPermission);

    // ── Background badge poll — Lightweight Smart Polling ────────────
    var activeUserId       = '<?= $_SESSION["userId"] ?? "" ?>';
    var userNotifKey       = 'ams_last_notif_id_' + activeUserId;
    var lastNotifId        = parseInt(localStorage.getItem(userNotifKey) || '0', 10);
    var isFetchInProgress  = false;

    function pollLeaveBadge() {
        if (isFetchInProgress) return;
        isFetchInProgress = true;

        fetch('../ajax/fetch_unread_count.php?last_id=' + lastNotifId, { credentials: 'same-origin' })
            .then(function(r) { if (!r.ok) return; return r.json(); })
            .then(function(data) { 
                if (!data) return;
                updateLeaveBadge(data.unread_count); 

                if (lastNotifId == 0 && data.max_id > 0) {
                    lastNotifId = data.max_id;
                    localStorage.setItem(userNotifKey, lastNotifId);
                } else if (data.new_notifications && data.new_notifications.length > 0) {
                    var maxId = lastNotifId;
                    data.new_notifications.forEach(function(n) {
                        if (n.id > maxId) maxId = n.id;
                        
                        // Show physical desktop notification
                        if ("Notification" in window && Notification.permission === "granted") {
                            new Notification(n.title, { body: n.message, icon: '../images/logos/SETTribe-logo.png' });
                        } else if ("Notification" in window && Notification.permission !== "denied") {
                            Notification.requestPermission().then(function(permission) {
                                if (permission === "granted") {
                                    new Notification(n.title, { body: n.message, icon: '../images/logos/SETTribe-logo.png' });
                                }
                            });
                        }
                    });
                    lastNotifId = maxId;
                    localStorage.setItem(userNotifKey, lastNotifId);
                }
            })
            .catch(function() {})
            .finally(function() {
                isFetchInProgress = false;
            });
    }

    function checkTimeBasedReminders() {
        var now = new Date();
        var todayStr = now.getFullYear() + '-' + (now.getMonth()+1) + '-' + now.getDate();
        
        function parseTime(timeStr) {
            if (!timeStr || timeStr === '00:00:00') return null;
            var parts = timeStr.split(':');
            var d = new Date(now);
            d.setHours(parseInt(parts[0], 10), parseInt(parts[1], 10), parseInt(parts[2]||0, 10), 0);
            return d;
        }
        
        var expIn = parseTime(NAV_EXPECTED_PUNCH_IN);
        var expOut = parseTime(NAV_EXPECTED_PUNCH_OUT);
        var lunchTime = new Date(now);
        lunchTime.setHours(13, 0, 0, 0); // 1 PM
        
        function fireLocalNotification(keySuffix, title, message) {
            var key = 'ams_remind_' + keySuffix + '_' + todayStr;
            if (localStorage.getItem(key)) return;
            
            if ("Notification" in window && Notification.permission === "granted") {
                new Notification(title, { body: message, icon: '../images/logos/SETTribe-logo.png' });
                localStorage.setItem(key, '1');
            }
        }
        
        // 1. Not punched in reminders
        if (!NAV_PUNCHED_IN && expIn) {
            var diffMinutes = (now - expIn) / 60000;
            
            if (diffMinutes >= -5 && diffMinutes < 0) {
                fireLocalNotification('pre_5', 'Punch In Reminder', 'Please punch in soon. Your shift starts in 5 minutes.');
            }
            else if (diffMinutes >= 5 && diffMinutes < 10) {
                fireLocalNotification('post_5', 'Punch In Overdue', 'You are 5 minutes late. Please punch in immediately.');
            }
            else if (diffMinutes >= 10 && diffMinutes < 30) {
                fireLocalNotification('post_10', 'Punch In Overdue', 'You are 10 minutes late. Please punch in immediately.');
            }
            else if (diffMinutes >= 30 && diffMinutes < 60) {
                fireLocalNotification('absent_30', 'Marked Absent', 'Your maked as absend for not punch in');
            }
        }
        
        // 2. Lunch Break reminder
        if (NAV_PUNCHED_IN) {
            var lunchDiff = (now - lunchTime) / 60000;
            if (lunchDiff >= 0 && lunchDiff < 60) {
                fireLocalNotification('lunch', 'Lunch Break', 'Your Lunch Break');
            }
        }
        
        // 3. Punch-out reminder
        if (NAV_PUNCHED_IN && expOut) {
            var outDiff = (now - expOut) / 60000;
            if (outDiff >= 5 && outDiff < 60) {
                fireLocalNotification('out_extend', 'Punch Out', 'are you want to extend punch out');
            }
        }
    }

    function runAllChecks() {
        pollLeaveBadge();
        checkTimeBasedReminders();
    }

    // ── Smart Tab-Aware Polling Scheduler ──────────────────────────────────
    var pollTimerId = null;

    function scheduleSmartPoll() {
        // 12s/60s polling temporarily commented out
        /*
        if (pollTimerId) clearTimeout(pollTimerId);
        // Active tab: poll every 12 seconds | Hidden background tab: poll every 60 seconds
        var delay = document.hidden ? 60000 : 12000;
        pollTimerId = setTimeout(function() {
            runAllChecks();
            scheduleSmartPoll();
        }, delay);
        */
    }

    runAllChecks();
    // scheduleSmartPoll(); // 12s/60s background polling disabled

    document.addEventListener('visibilitychange', function() {
        if (!document.hidden) {
            runAllChecks(); // Instant poll when returning to active tab
        }
        // scheduleSmartPoll();
    });
})();
</script>

<script>
// ══════════════════════════════════════════════════════
// WEBSITE THEME ENGINE (LIGHT / DARK MODE ONLY)
// ══════════════════════════════════════════════════════
(function() {
    function initThemeEngine() {
        var savedTheme = localStorage.getItem('ams_theme_mode') || 'light';
        applyThemeMode(savedTheme, false);

        var toggleBtn = document.getElementById('navThemeToggleBtn');
        if (toggleBtn) {
            toggleBtn.addEventListener('click', function(e) {
                e.preventDefault();
                var current = localStorage.getItem('ams_theme_mode') || 'light';
                var next = current === 'dark' ? 'light' : 'dark';
                applyThemeMode(next, true);
            });
        }
    }

    function applyThemeMode(theme, save) {
        var body = document.body;
        var html = document.documentElement;

        if (theme === 'dark') {
            if (body) {
                body.classList.add('dark-theme', 'sidebar-dark');
                body.classList.remove('sidebar-light');
                body.setAttribute('data-theme', 'dark');
            }
            if (html) {
                html.classList.add('dark-theme', 'sidebar-dark');
                html.classList.remove('sidebar-light');
                html.setAttribute('data-theme', 'dark');
            }
        } else {
            if (body) {
                body.classList.remove('dark-theme', 'sidebar-dark');
                body.classList.add('sidebar-light');
                body.setAttribute('data-theme', 'light');
            }
            if (html) {
                html.classList.remove('dark-theme', 'sidebar-dark');
                html.classList.add('sidebar-light');
                html.setAttribute('data-theme', 'light');
            }
        }

        // Update Toggle Button Icon & Tooltip
        var iconEl = document.getElementById('navThemeCurrentIcon');
        var toggleBtn = document.getElementById('navThemeToggleBtn');
        if (iconEl) {
            if (theme === 'dark') {
                iconEl.className = 'fa-solid fa-sun';
                iconEl.style.color = '#f59e0b';
                if (toggleBtn) toggleBtn.setAttribute('title', 'Switch to Light Mode');
            } else {
                iconEl.className = 'fa-solid fa-moon';
                iconEl.style.color = '#6366f1';
                if (toggleBtn) toggleBtn.setAttribute('title', 'Switch to Dark Mode');
            }
        }

        if (save) {
            localStorage.setItem('ams_theme_mode', theme);
            try {
                window.dispatchEvent(new CustomEvent('ams:themechange', { detail: { theme: theme } }));
            } catch(e) {}
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initThemeEngine);
    } else {
        initThemeEngine();
    }
})();
</script>

<?php
// Feature flag & Role allowlist evaluation
$aiChatEnabled = strtolower(getenv('AI_CHATBOT_ENABLED') ?: ($_ENV['AI_CHATBOT_ENABLED'] ?? 'false')) === 'true';
$aiAllowedRolesStr = getenv('AI_CHATBOT_ALLOWED_ROLES') ?: ($_ENV['AI_CHATBOT_ALLOWED_ROLES'] ?? '*');
$aiAllowedRoles = array_map('trim', explode(',', strtolower($aiAllowedRolesStr)));
$isAiRoleAllowed = in_array('*', $aiAllowedRoles) 
    || in_array('all', $aiAllowedRoles) 
    || in_array(strtolower($userRole), $aiAllowedRoles) 
    || (in_array('intern', $aiAllowedRoles) && strpos(strtolower($userRole), 'intern') !== false)
    || (in_array('employee', $aiAllowedRoles) && in_array(strtolower($userRole), ['employee', 'staff', 'developer']));

if ($aiChatEnabled && $isAiRoleAllowed):
?>
<!-- Simple Clean Chatbot Widget -->
<style>
/* Clean & Minimal Chatbot Widget */
.ams-chat-widget-btn {
    position: fixed !important;
    bottom: 24px !important;
    right: 24px !important;
    width: 52px !important;
    height: 52px !important;
    border-radius: 50% !important;
    background: #2563eb !important;
    color: #ffffff !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    font-size: 22px !important;
    cursor: pointer !important;
    box-shadow: 0 4px 14px rgba(37, 99, 235, 0.35) !important;
    z-index: 99999 !important;
    transition: transform 0.2s ease, box-shadow 0.2s ease !important;
    border: none !important;
}
.ams-chat-widget-btn:hover {
    transform: scale(1.08) !important;
    box-shadow: 0 6px 18px rgba(37, 99, 235, 0.45) !important;
}

.rtl .ams-chat-widget-btn {
    right: auto !important;
    left: 24px !important;
}
.rtl .ams-chat-window {
    right: auto !important;
    left: 24px !important;
}

.ams-chat-window {
    position: fixed;
    bottom: 86px;
    right: 24px;
    width: 410px;
    max-width: calc(100vw - 32px);
    height: 540px;
    max-height: calc(100vh - 100px);
    border-radius: 14px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    box-shadow: 0 8px 30px rgba(0, 0, 0, 0.12);
    display: flex;
    flex-direction: column;
    z-index: 99999 !important;
    overflow: hidden;
    opacity: 0;
    transform: translateY(10px);
    pointer-events: none;
    transition: opacity 0.2s ease, transform 0.2s ease;
    font-family: inherit;
}
.ams-chat-window.show {
    opacity: 1;
    transform: translateY(0);
    pointer-events: auto;
}

.ams-chat-header {
    background: #2563eb;
    padding: 12px 14px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    color: #ffffff;
}
.ams-chat-header-info {
    display: flex;
    align-items: center;
    gap: 10px;
}
.ams-chat-bot-icon {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.2);
    color: #ffffff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 15px;
    position: relative;
}
.ams-chat-online-indicator {
    position: absolute;
    bottom: 0;
    right: 0;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #22c55e;
    border: 1.5px solid #2563eb;
}
.ams-chat-title {
    margin: 0;
    font-size: 14px;
    font-weight: 600;
    color: #ffffff;
}
.ams-chat-subtitle {
    margin: 0;
    font-size: 11px;
    color: #dbeafe;
}
.ams-chat-header-actions {
    display: flex;
    align-items: center;
    gap: 6px;
}
.ams-chat-header-btn {
    background: rgba(255, 255, 255, 0.15);
    border: none;
    color: #ffffff;
    width: 28px;
    height: 28px;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    cursor: pointer;
    transition: background 0.15s;
}
.ams-chat-header-btn:hover {
    background: rgba(255, 255, 255, 0.28);
}

/* Quick Suggestion Chips */
.ams-chat-chips-container {
    padding: 8px 12px;
    display: flex;
    gap: 6px;
    overflow-x: auto;
    scrollbar-width: none;
    border-bottom: 1px solid #f1f5f9;
    background: #f8fafc;
}
.ams-chat-chips-container::-webkit-scrollbar {
    display: none;
}
.ams-chat-chip {
    flex-shrink: 0;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    color: #334155;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 11px;
    cursor: pointer;
    transition: all 0.15s ease;
    white-space: nowrap;
}
.ams-chat-chip:hover {
    background: #eff6ff;
    border-color: #bfdbfe;
    color: #1d4ed8;
}

.ams-chat-messages {
    flex: 1;
    overflow-y: auto;
    padding: 12px;
    display: flex;
    flex-direction: column;
    gap: 10px;
    background: #f8fafc;
}
.ams-chat-messages::-webkit-scrollbar {
    width: 4px;
}
.ams-chat-messages::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 4px;
}

.ams-chat-message {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    max-width: 92%;
}
.ams-chat-message.bot {
    align-self: flex-start;
}
.ams-chat-message.user {
    align-self: flex-end;
    flex-direction: row-reverse;
}

.ams-chat-msg-avatar {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: #e2e8f0;
    color: #475569;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11.5px;
    flex-shrink: 0;
    margin-top: 2px;
}
.ams-chat-message.user .ams-chat-msg-avatar {
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    color: #ffffff;
}

.ams-chat-msg-bubble {
    padding: 10px 14px;
    border-radius: 14px;
    font-size: 13px;
    line-height: 1.58;
    word-break: break-word;
    font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}
.ams-chat-message.bot .ams-chat-msg-bubble {
    background: #ffffff;
    color: #1e293b;
    border: 1px solid #e2e8f0;
    border-top-left-radius: 3px;
    box-shadow: 0 1px 4px rgba(15, 23, 42, 0.04);
}
.ams-chat-message.user .ams-chat-msg-bubble {
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    color: #ffffff;
    border-top-right-radius: 3px;
    box-shadow: 0 2px 6px rgba(37, 99, 235, 0.18);
    font-weight: 500;
}

/* Markdown styling inside bubbles */
.ams-chat-msg-bubble p {
    margin: 0 0 6px 0;
}
.ams-chat-msg-bubble p:last-child {
    margin-bottom: 0;
}
.ams-chat-msg-bubble ul, .ams-chat-msg-bubble ol {
    margin: 6px 0 8px 0;
    padding-left: 20px;
}
.ams-chat-msg-bubble li {
    margin-bottom: 4px;
    line-height: 1.55;
}
.ams-chat-msg-bubble strong {
    font-weight: 600;
}
.ams-chat-message.bot .ams-chat-msg-bubble strong {
    color: #0f172a;
}
.ams-chat-msg-bubble code {
    background: #f1f5f9;
    padding: 2px 5px;
    border-radius: 4px;
    font-size: 11.5px;
    color: #2563eb;
    font-family: Consolas, monospace;
}
.ams-chat-message.user .ams-chat-msg-bubble code {
    background: rgba(255, 255, 255, 0.2);
    color: #ffffff;
}

/* Responsive Table Wrapper & Clean Corporate Table Design */
.ams-chat-table-wrap {
    width: 100%;
    overflow-x: auto;
    margin: 8px 0;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    background: #ffffff;
    -webkit-overflow-scrolling: touch;
}
.ams-chat-msg-bubble table {
    width: 100%;
    border-collapse: collapse;
    margin: 0;
    font-size: 11.5px;
    text-align: left;
}
.ams-chat-msg-bubble th, .ams-chat-msg-bubble td {
    padding: 6px 10px;
    border-bottom: 1px solid #f1f5f9;
}
.ams-chat-msg-bubble th {
    background: #f8fafc;
    font-weight: 700;
    color: #334155;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    border-bottom: 1.5px solid #e2e8f0;
    white-space: nowrap;
}
.ams-chat-msg-bubble tbody tr:nth-child(even) {
    background: #fbfcfe;
}
.ams-chat-msg-bubble tbody tr:last-child td {
    border-bottom: none;
}
.ams-chat-msg-bubble td {
    color: #1e293b;
    vertical-align: top;
}
.ams-chat-msg-bubble hr {
    border: none;
    border-top: 1px solid #e2e8f0;
    margin: 8px 0;
}
.ams-chat-msg-bubble h1, .ams-chat-msg-bubble h2, .ams-chat-msg-bubble h3, .ams-chat-msg-bubble h4 {
    margin: 8px 0 4px 0;
    font-weight: 700;
    color: #0f172a;
    line-height: 1.35;
}
.ams-chat-msg-bubble h1 { font-size: 14px; }
.ams-chat-msg-bubble h2 { font-size: 13.5px; }
.ams-chat-msg-bubble h3, .ams-chat-msg-bubble h4 { font-size: 12.5px; }
.ams-chat-msg-bubble i {
    margin-right: 3px;
    vertical-align: middle;
}

/* Typing Indicator */
.ams-chat-typing {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    max-width: 85%;
    align-self: flex-start;
}
.ams-chat-typing .ams-chat-msg-bubble {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 8px 12px;
    border-radius: 12px;
    border-top-left-radius: 2px;
}
.ams-chat-dot {
    width: 5px;
    height: 5px;
    background-color: #94a3b8;
    border-radius: 50%;
    animation: ams-typing 1.2s infinite ease-in-out both;
}
.ams-chat-dot:nth-child(1) { animation-delay: -0.32s; }
.ams-chat-dot:nth-child(2) { animation-delay: -0.16s; }
@keyframes ams-typing {
    0%, 80%, 100% { transform: scale(0.4); opacity: 0.4; }
    40% { transform: scale(1); opacity: 1; }
}

.ams-chat-confirm-actions {
    margin-top: 10px;
    padding-top: 8px;
    border-top: 1px dashed #cbd5e1;
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.ams-confirm-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border: none;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.15s ease;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
}
.ams-confirm-btn-yes {
    background: #16a34a;
    color: #ffffff;
}
.ams-confirm-btn-yes:hover {
    background: #15803d;
    transform: translateY(-1px);
}
.ams-confirm-btn-no {
    background: #ef4444;
    color: #ffffff;
}
.ams-confirm-btn-no:hover {
    background: #dc2626;
    transform: translateY(-1px);
}
.ams-confirm-btn:active {
    transform: translateY(0);
}
body.dark-theme .ams-chat-confirm-actions {
    border-top-color: #334155;
}

.ams-chat-input-area {
    padding: 8px 10px;
    background: #ffffff;
    border-top: 1px solid #e2e8f0;
    display: flex;
    gap: 6px;
    align-items: center;
}
.ams-chat-input {
    flex: 1;
    background: #f8fafc;
    border: 1px solid #cbd5e1;
    border-radius: 20px;
    padding: 8px 14px;
    color: #1e293b;
    font-size: 13px;
    outline: none;
    transition: border-color 0.15s;
}
.ams-chat-input:focus {
    border-color: #2563eb;
    background: #ffffff;
}
.ams-chat-input::placeholder {
    color: #94a3b8;
}
.ams-chat-send {
    background: #2563eb;
    border: none;
    color: #ffffff;
    width: 34px;
    height: 34px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    cursor: pointer;
    transition: background 0.15s;
}
.ams-chat-send:hover {
    background: #1d4ed8;
}

.ams-chat-error-banner {
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #dc2626;
    padding: 6px 10px;
    border-radius: 8px;
    font-size: 11.5px;
    text-align: center;
    margin-bottom: 4px;
}

/* Chat History Panel */
.ams-chat-history-panel {
    display: none;
    position: absolute;
    top: 56px;
    left: 0;
    right: 0;
    bottom: 0;
    background: #f8fafc;
    flex-direction: column;
    z-index: 10;
}
.ams-chat-history-panel.active {
    display: flex;
}
.ams-history-header {
    padding: 8px 12px;
    background: #ffffff;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.ams-history-header-title {
    font-size: 12px;
    font-weight: 700;
    color: #1e293b;
    display: flex;
    align-items: center;
    gap: 6px;
}
.ams-history-header-title i {
    color: #2563eb;
}
.ams-history-header-actions {
    display: flex;
    align-items: center;
    gap: 6px;
}
.ams-history-clear-btn {
    background: none;
    border: none;
    color: #ef4444;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    padding: 2px 6px;
    border-radius: 4px;
    transition: background 0.15s;
}
.ams-history-clear-btn:hover {
    background: #fee2e2;
}
.ams-history-back-btn {
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    color: #1d4ed8;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    padding: 3px 8px;
    border-radius: 6px;
    transition: background 0.15s;
}
.ams-history-back-btn:hover {
    background: #dbeafe;
}
.ams-history-list {
    flex: 1;
    overflow-y: auto;
    padding: 10px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.ams-history-list::-webkit-scrollbar {
    width: 4px;
}
.ams-history-list::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 4px;
}
.ams-history-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 9px 12px;
    cursor: pointer;
    transition: all 0.15s ease;
}
.ams-history-card:hover {
    border-color: #93c5fd;
    background: #f0f7ff;
    transform: translateY(-1px);
    box-shadow: 0 2px 6px rgba(37, 99, 235, 0.08);
}
.ams-history-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 10.5px;
    color: #94a3b8;
    margin-bottom: 4px;
}
.ams-history-card-query {
    font-size: 12px;
    font-weight: 600;
    color: #1e293b;
    margin-bottom: 3px;
    display: flex;
    align-items: flex-start;
    gap: 6px;
}
.ams-history-card-query i {
    color: #2563eb;
    margin-top: 2px;
    font-size: 11px;
    flex-shrink: 0;
}
.ams-history-card-reply {
    font-size: 11px;
    color: #64748b;
    line-height: 1.4;
    overflow: hidden;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
}
.ams-history-empty {
    text-align: center;
    padding: 40px 16px;
    color: #94a3b8;
    font-size: 12px;
}
.ams-history-empty i {
    font-size: 26px;
    margin-bottom: 8px;
    color: #cbd5e1;
    display: block;
}

/* Top Navigation Bar inside Chat Window */
.ams-chat-nav-bar {
    display: flex;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    flex-shrink: 0;
}
.ams-chat-nav-tab {
    flex: 1;
    padding: 8px 12px;
    font-size: 11.5px;
    font-weight: 600;
    color: #64748b;
    border: none;
    background: transparent;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    transition: all 0.15s ease;
    border-bottom: 2px solid transparent;
}
.ams-chat-nav-tab:hover {
    color: #1e293b;
    background: #f1f5f9;
}
.ams-chat-nav-tab.active {
    color: #2563eb;
    background: #ffffff;
    border-bottom-color: #2563eb;
    font-weight: 700;
}

/* Chat & Hub View Wrappers */
.ams-chat-view-container {
    flex: 1;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    position: relative;
}
.ams-chat-view-chat {
    flex: 1;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.ams-chat-view-hub {
    display: none;
    flex: 1;
    flex-direction: column;
    background: #f8fafc;
    overflow-y: auto;
}
.ams-chat-view-hub.active {
    display: flex;
}

/* Hub Category Selector */
.ams-hub-categories {
    display: flex;
    gap: 6px;
    padding: 8px 10px;
    background: #ffffff;
    border-bottom: 1px solid #e2e8f0;
    overflow-x: auto;
    flex-shrink: 0;
    -webkit-overflow-scrolling: touch;
}
.ams-hub-categories::-webkit-scrollbar {
    height: 3px;
}
.ams-hub-categories::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 3px;
}
.ams-hub-cat-btn {
    padding: 5px 10px;
    font-size: 11px;
    font-weight: 600;
    color: #475569;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 20px;
    white-space: nowrap;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 5px;
    transition: all 0.15s ease;
}
.ams-hub-cat-btn:hover {
    background: #f1f5f9;
    border-color: #cbd5e1;
}
.ams-hub-cat-btn.active {
    background: #eff6ff;
    border-color: #3b82f6;
    color: #1d4ed8;
    font-weight: 700;
}

/* Hub Sub-mode Switcher (Form vs Demo Prompt) */
.ams-hub-mode-bar {
    display: flex;
    gap: 4px;
    padding: 4px;
    background: #e2e8f0;
    border-radius: 8px;
    margin: 8px 10px 4px;
    flex-shrink: 0;
}
.ams-hub-mode-btn {
    flex: 1;
    padding: 5px 8px;
    font-size: 11px;
    font-weight: 600;
    color: #64748b;
    border: none;
    background: transparent;
    border-radius: 6px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    transition: all 0.15s ease;
}
.ams-hub-mode-btn.active {
    background: #ffffff;
    color: #1e293b;
    font-weight: 700;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
}

/* Hub Feature Content Container */
.ams-hub-content {
    flex: 1;
    padding: 8px 10px 14px;
    overflow-y: auto;
}
.ams-hub-feature-panel {
    display: none;
}
.ams-hub-feature-panel.active {
    display: block;
}
.ams-hub-mode-content[data-mode="prompt"] {
    display: block;
}

/* Hub Cards */
.ams-hub-form-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 12px 14px;
    box-shadow: 0 1px 4px rgba(0,0,0,0.03);
}
.ams-hub-form-title {
    font-size: 12px;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 2px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.ams-hub-form-subtitle {
    font-size: 10.5px;
    color: #64748b;
    margin-bottom: 10px;
    line-height: 1.4;
}
.ams-form-group {
    margin-bottom: 9px;
}
.ams-form-group label {
    display: block;
    font-size: 11px;
    font-weight: 700;
    color: #475569;
    margin-bottom: 3px;
}
.ams-form-group input,
.ams-form-group select,
.ams-form-group textarea {
    width: 100%;
    padding: 6px 9px;
    font-size: 12px;
    border: 1px solid #cbd5e1;
    border-radius: 7px;
    background: #ffffff;
    color: #1e293b;
    outline: none;
    box-sizing: border-box;
    transition: border-color 0.15s;
    font-family: inherit;
}
.ams-form-group input:focus,
.ams-form-group select:focus,
.ams-form-group textarea:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 2px rgba(37,99,235,0.1);
}
.ams-form-row {
    display: flex;
    gap: 8px;
}
.ams-form-row .ams-form-group {
    flex: 1;
}
.ams-hub-submit-btn {
    width: 100%;
    padding: 8px 12px;
    background: #2563eb;
    color: #ffffff;
    font-size: 12px;
    font-weight: 700;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    margin-top: 10px;
    transition: background 0.15s;
}
.ams-hub-submit-btn:hover {
    background: #1d4ed8;
}
.ams-hub-submit-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

/* Prompt Demo Section */
.ams-hub-prompt-intro {
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-radius: 9px;
    padding: 9px 11px;
    margin-bottom: 10px;
    font-size: 11px;
    color: #1e40af;
    line-height: 1.45;
}
.ams-hub-prompt-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 10px 12px;
    margin-bottom: 8px;
    transition: all 0.15s ease;
}
.ams-hub-prompt-card:hover {
    border-color: #93c5fd;
    background: #f0f7ff;
    box-shadow: 0 2px 6px rgba(37,99,235,0.06);
}
.ams-hub-prompt-title {
    font-size: 11px;
    font-weight: 700;
    color: #334155;
    margin-bottom: 4px;
}
.ams-hub-prompt-text {
    font-size: 11.5px;
    font-weight: 600;
    color: #1e293b;
    background: #f8fafc;
    border: 1px dashed #cbd5e1;
    border-radius: 6px;
    padding: 6px 9px;
    margin-bottom: 6px;
    user-select: all;
    line-height: 1.4;
}
.ams-hub-prompt-action {
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.ams-hub-prompt-desc {
    font-size: 10px;
    color: #64748b;
}
.ams-use-prompt-btn {
    padding: 4px 9px;
    font-size: 11px;
    font-weight: 600;
    color: #1d4ed8;
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-radius: 6px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: all 0.15s;
}
.ams-use-prompt-btn:hover {
    background: #2563eb;
    color: #ffffff;
    border-color: #2563eb;
}

/* Alert Boxes in Hub */
.ams-hub-alert {
    padding: 7px 10px;
    border-radius: 7px;
    font-size: 11px;
    margin-bottom: 9px;
    display: none;
    align-items: flex-start;
    gap: 6px;
    line-height: 1.4;
}
.ams-hub-alert.success {
    display: flex;
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    color: #166534;
}
.ams-hub-alert.error {
    display: flex;
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #991b1b;
}

/* ══════════════════════════════════════════════════════
   CHATBOT RESPONSIVE BREAKPOINTS (Mobile, Tablet, Laptop)
══════════════════════════════════════════════════════ */
@media (max-width: 575px) {
    .ams-chat-widget-btn {
        bottom: 14px !important;
        right: 14px !important;
        width: 46px !important;
        height: 46px !important;
        font-size: 18px !important;
    }
    .ams-chat-window {
        bottom: 68px !important;
        left: 8px !important;
        right: 8px !important;
        width: calc(100vw - 16px) !important;
        max-width: calc(100vw - 16px) !important;
        height: calc(100dvh - 80px) !important;
        max-height: calc(100dvh - 80px) !important;
        border-radius: 14px !important;
    }
    .ams-chat-header {
        padding: 9px 12px !important;
    }
    .ams-chat-title {
        font-size: 13px !important;
    }
    .ams-chat-subtitle {
        font-size: 10px !important;
    }
    .ams-chat-chips-container {
        padding: 6px 8px !important;
        gap: 5px !important;
    }
    .ams-chat-chip {
        font-size: 10.5px !important;
        padding: 3px 8px !important;
    }
    .ams-chat-messages {
        padding: 10px !important;
        gap: 8px !important;
    }
    .ams-chat-msg-bubble {
        font-size: 12px !important;
        padding: 7px 11px !important;
    }
    .ams-chat-input-area {
        padding: 6px 8px !important;
    }
    .ams-chat-input {
        font-size: 14px !important;
        padding: 7px 12px !important;
    }
    .ams-chat-send {
        width: 32px !important;
        height: 32px !important;
        font-size: 12px !important;
    }
    .ams-chat-history-panel {
        top: 86px !important;
    }
}

@media (min-width: 576px) and (max-width: 991px) {
    .ams-chat-widget-btn {
        bottom: 18px !important;
        right: 18px !important;
        width: 48px !important;
        height: 48px !important;
        font-size: 20px !important;
    }
    .ams-chat-window {
        bottom: 76px !important;
        right: 16px !important;
        width: 390px !important;
        max-width: calc(100vw - 32px) !important;
        height: 520px !important;
        max-height: calc(100dvh - 96px) !important;
    }
}
</style>

<?php
// Pre-fetch active projects & active employees
$ams_chat_projects = [];
$ams_chat_employees = [];
if (isset($con) && $con instanceof mysqli) {
    $pRes = @mysqli_query($con, "SELECT projectId, projectName FROM projectMaster WHERE status = 1 ORDER BY projectName ASC");
    if ($pRes) {
        while ($pr = mysqli_fetch_assoc($pRes)) {
            $ams_chat_projects[] = $pr;
        }
    }

    $eRes = @mysqli_query($con, "SELECT userId, fullName, designation FROM signup WHERE status = 'Active' ORDER BY fullName ASC");
    if ($eRes) {
        while ($er = mysqli_fetch_assoc($eRes)) {
            $ams_chat_employees[] = $er;
        }
    }

    $ams_chat_wfh_approvers = [];
    $currentChatUserId = $_SESSION['userId'] ?? '';
    $wfhAppQ = @mysqli_query($con, "
        SELECT userId, fullName, designation 
        FROM signup 
        WHERE status = 'Active' 
          AND userId != '$currentChatUserId'
          AND (LOWER(designation) IN ('admin', 'hr', 'seniorintern', 'resourceallocator')
               OR LOWER(userRole) = 'admin')
        ORDER BY CASE 
            WHEN LOWER(designation) = 'hr' THEN 1 
            WHEN LOWER(designation) = 'admin' THEN 2 
            ELSE 3 
        END, fullName ASC
    ");
    if ($wfhAppQ) {
        while ($ar = mysqli_fetch_assoc($wfhAppQ)) {
            $ams_chat_wfh_approvers[] = $ar;
        }
    }
}
?>

<!-- Marked.js & DOMPurify for Safe Markdown Rendering -->
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/dompurify@3.1.6/dist/purify.min.js"></script>

<!-- Floating Chatbot Widget Button -->
<div id="ams-chat-widget-btn" class="ams-chat-widget-btn" title="Ask AMS Assistant">
    <i class="fa-solid fa-robot"></i>
</div>

<!-- Chat Window -->
<div id="ams-chat-window" class="ams-chat-window">
    <div class="ams-chat-header">
        <div class="ams-chat-header-info">
            <div class="ams-chat-bot-icon">
                <i class="fa-solid fa-robot"></i>
                <span class="ams-chat-online-indicator"></span>
            </div>
            <div>
                <h4 class="ams-chat-title">AMS Assistant</h4>
                <p class="ams-chat-subtitle">Online &bull; AI Powered</p>
            </div>
        </div>
        <div class="ams-chat-header-actions">
            <button id="ams-chat-toggle-hub" class="ams-chat-header-btn" title="Forms & Demo Prompts Hub">
                <i class="fa-solid fa-shapes"></i>
            </button>
            <button id="ams-chat-history-btn" class="ams-chat-header-btn" title="Chat & Search History">
                <i class="fa-solid fa-clock-rotate-left"></i>
            </button>
            <button id="ams-chat-clear" class="ams-chat-header-btn" title="Reset Chat">
                <i class="fa-solid fa-rotate-right"></i>
            </button>
            <button id="ams-chat-close" class="ams-chat-header-btn" title="Close">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
    </div>
    
    <!-- Top Segment Navigation Bar -->
    <div class="ams-chat-nav-bar">
        <button type="button" id="ams-tab-btn-chat" class="ams-chat-nav-tab active">
            <i class="fa-solid fa-comments"></i> AI Chat
        </button>
        <button type="button" id="ams-tab-btn-hub" class="ams-chat-nav-tab">
            <i class="fa-solid fa-layer-group"></i> Forms & Prompts
        </button>
    </div>

    <!-- History View Panel (Toggled via History Icon) -->
    <div id="ams-chat-history-panel" class="ams-chat-history-panel">
        <div class="ams-history-header">
            <div class="ams-history-header-title">
                <i class="fa-solid fa-clock-rotate-left"></i> Past Searches & Chats
            </div>
            <div class="ams-history-header-actions">
                <button id="ams-chat-clear-history" class="ams-history-clear-btn" title="Delete All History">
                    <i class="fa-regular fa-trash-can"></i> Clear
                </button>
                <button id="ams-chat-history-back" class="ams-history-back-btn" title="Back to Chat">
                    <i class="fa-solid fa-arrow-left"></i> Back
                </button>
            </div>
        </div>
        <div id="ams-history-list" class="ams-history-list">
            <div class="ams-history-empty">
                <i class="fa-regular fa-comments"></i>
                Loading history...
            </div>
        </div>
    </div>

    <!-- View Container: Holds AI Chat OR Forms & Prompts Hub -->
    <div class="ams-chat-view-container">
        
        <!-- ── TAB 1: AI CHAT VIEW ── -->
        <div id="ams-chat-view-chat" class="ams-chat-view-chat">
            <!-- Quick Suggestion Chips -->
            <div class="ams-chat-chips-container">
                <button class="ams-chat-chip" data-prompt="check my balance leave"><i class="fa-solid fa-calendar-days" style="color:#2563eb;"></i> Leave Balance</button>
                <button class="ams-chat-chip" data-prompt="check today punch out status"><i class="fa-solid fa-fingerprint" style="color:#16a34a;"></i> Punch Status</button>
                <button class="ams-chat-chip ams-hub-chip" data-open-hub="leave"><i class="fa-solid fa-calendar-days" style="color:#0284c7;"></i> Leave Prompts</button>
                <button class="ams-chat-chip ams-hub-chip" data-open-hub="wfh"><i class="fa-solid fa-house-laptop" style="color:#6366f1;"></i> WFH Prompts</button>
                <button class="ams-chat-chip" data-prompt="apply for work from home tomorrow due to urgent personal work"><i class="fa-solid fa-laptop-house" style="color:#8b5cf6;"></i> Apply WFH</button>
                <button class="ams-chat-chip" data-prompt="check my wfh status"><i class="fa-solid fa-circle-question" style="color:#0284c7;"></i> WFH Status</button>
                <button class="ams-chat-chip ams-hub-chip" data-open-hub="task_assign"><i class="fa-solid fa-user-plus" style="color:#10b981;"></i> Assign Task</button>
                <button class="ams-chat-chip ams-hub-chip" data-open-hub="task_log"><i class="fa-solid fa-list-check" style="color:#d97706;"></i> Log Task</button>
                <button class="ams-chat-chip ams-hub-chip" data-open-hub="tour"><i class="fa-solid fa-plane-departure" style="color:#0284c7;"></i> Tour Prompts</button>
                <button class="ams-chat-chip" data-prompt="what is the late mark policy?"><i class="fa-solid fa-book-bookmark" style="color:#64748b;"></i> Late Policy</button>
            </div>

            <div id="ams-chat-messages" class="ams-chat-messages">
                <div class="ams-chat-message bot">
                    <div class="ams-chat-msg-avatar"><i class="fa-solid fa-robot"></i></div>
                    <div class="ams-chat-msg-bubble">
                        Welcome to <strong>SETTribe AMS Assistant</strong>. How may I assist you with your attendance, leaves, tasks, or policies today?
                        <div style="margin-top:8px;padding-top:8px;border-top:1px dashed #e2e8f0;display:flex;gap:6px;flex-wrap:wrap;">
                            <button class="ams-use-prompt-btn ams-hub-open-trigger" data-open-hub="leave" style="font-size:10.5px;">
                                <i class="fa-solid fa-calendar-days"></i> Leave Prompts
                            </button>
                            <button class="ams-use-prompt-btn ams-hub-open-trigger" data-open-hub="wfh" style="font-size:10.5px;">
                                <i class="fa-solid fa-house-laptop"></i> Apply WFH
                            </button>
                            <button class="ams-use-prompt-btn ams-hub-open-trigger" data-open-hub="task_assign" style="font-size:10.5px;">
                                <i class="fa-solid fa-user-plus"></i> Assign Task
                            </button>
                            <button class="ams-use-prompt-btn ams-hub-open-trigger" data-open-hub="tour" style="font-size:10.5px;">
                                <i class="fa-solid fa-plane-departure"></i> Apply Tour
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            
            <div id="ams-chat-typing" class="ams-chat-typing" style="display: none;">
                <div class="ams-chat-msg-avatar"><i class="fa-solid fa-robot"></i></div>
                <div class="ams-chat-msg-bubble">
                    <span class="ams-chat-dot"></span>
                    <span class="ams-chat-dot"></span>
                    <span class="ams-chat-dot"></span>
                </div>
            </div>

            <form id="ams-chat-input-area" class="ams-chat-input-area">
                <input type="text" id="ams-chat-input" class="ams-chat-input" placeholder="Type your message or prompt..." required autocomplete="off">
                <button type="submit" id="ams-chat-send" class="ams-chat-send" title="Send">
                    <i class="fa-solid fa-paper-plane"></i>
                </button>
            </form>
        </div>

        <!-- ── TAB 2: FORMS & DEMO PROMPTS HUB ── -->
        <div id="ams-chat-view-hub" class="ams-chat-view-hub">
            <!-- Category Pills -->
            <div class="ams-hub-categories">
                <button type="button" class="ams-hub-cat-btn active" data-cat="leave">
                    <i class="fa-solid fa-calendar-days" style="color:#2563eb;"></i> Leave
                </button>
                <button type="button" class="ams-hub-cat-btn" data-cat="wfh">
                    <i class="fa-solid fa-house-laptop" style="color:#6366f1;"></i> WFH
                </button>
                <button type="button" class="ams-hub-cat-btn" data-cat="task_assign">
                    <i class="fa-solid fa-user-plus" style="color:#10b981;"></i> Assign Task
                </button>
                <button type="button" class="ams-hub-cat-btn" data-cat="task_log">
                    <i class="fa-solid fa-list-check" style="color:#f59e0b;"></i> Log Task
                </button>
                <button type="button" class="ams-hub-cat-btn" data-cat="tour">
                    <i class="fa-solid fa-plane-departure" style="color:#0284c7;"></i> Tour
                </button>
                <button type="button" class="ams-hub-cat-btn" data-cat="late_punch">
                    <i class="fa-solid fa-clock" style="color:#8b5cf6;"></i> Late Punch
                </button>
            </div>

            <!-- AI Prompt Demo Header -->
            <div class="ams-hub-mode-bar">
                <button type="button" class="ams-hub-mode-btn active" data-mode="prompt">
                    <i class="fa-solid fa-lightbulb"></i> AI Prompt Demo
                </button>
            </div>

            <div class="ams-hub-content">
                
                <!-- ════════ 1. LEAVE APPLICATION FEATURE ════════ -->
                <div id="ams-hub-panel-leave" class="ams-hub-feature-panel active">
                    <!-- Mode: Prompt Demo View -->
                    <div class="ams-hub-mode-content" data-mode="prompt">
                        <div class="ams-hub-prompt-intro">
                            <strong><i class="fa-solid fa-wand-magic-sparkles"></i> AI Prompt Feature:</strong> You can apply for leaves or check balances simply by typing naturally in the AI Chat. Click <strong>Use Prompt</strong> to try any example right away!
                        </div>

                        <div class="ams-hub-prompt-card">
                            <div class="ams-hub-prompt-title">1. Casual Leave Tomorrow</div>
                            <div class="ams-hub-prompt-text">Apply for 1 day Casual Leave tomorrow due to personal work</div>
                            <div class="ams-hub-prompt-action">
                                <span class="ams-hub-prompt-desc">Validates date & checks available CL</span>
                                <button type="button" class="ams-use-prompt-btn" data-prompt="Apply for 1 day Casual Leave tomorrow due to personal work">
                                    <i class="fa-solid fa-paper-plane"></i> Use Prompt
                                </button>
                            </div>
                        </div>

                        <div class="ams-hub-prompt-card">
                            <div class="ams-hub-prompt-title">2. Sick Leave with Specific Dates</div>
                            <div class="ams-hub-prompt-text">Apply for 2 days Sick Leave from <?php echo date('Y-m-d', strtotime('+1 day')); ?> to <?php echo date('Y-m-d', strtotime('+2 days')); ?> due to fever</div>
                            <div class="ams-hub-prompt-action">
                                <span class="ams-hub-prompt-desc">Extracts dates & asks for confirmation</span>
                                <button type="button" class="ams-use-prompt-btn" data-prompt="Apply for 2 days Sick Leave from <?php echo date('Y-m-d', strtotime('+1 day')); ?> to <?php echo date('Y-m-d', strtotime('+2 days')); ?> due to fever">
                                    <i class="fa-solid fa-paper-plane"></i> Use Prompt
                                </button>
                            </div>
                        </div>

                        <div class="ams-hub-prompt-card">
                            <div class="ams-hub-prompt-title">3. Check Available Leave Balances</div>
                            <div class="ams-hub-prompt-text">What is my current CL and SL leave balance?</div>
                            <div class="ams-hub-prompt-action">
                                <span class="ams-hub-prompt-desc">Live calculation of CL, SL, PL, RO</span>
                                <button type="button" class="ams-use-prompt-btn" data-prompt="What is my current CL and SL leave balance?">
                                    <i class="fa-solid fa-paper-plane"></i> Use Prompt
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ════════ WORK FROM HOME (WFH) FEATURE ════════ -->
                <div id="ams-hub-panel-wfh" class="ams-hub-feature-panel">
                    <!-- Mode: Prompt Demo View -->
                    <div class="ams-hub-mode-content" data-mode="prompt">
                        <div class="ams-hub-prompt-intro">
                            <strong><i class="fa-solid fa-wand-magic-sparkles"></i> AI Chat WFH Prompts:</strong> You can apply for Work From Home or check approval status directly by typing in the chat. Click <strong>Use Prompt</strong> to try any example!
                        </div>

                        <div class="ams-hub-prompt-card">
                            <div class="ams-hub-prompt-title">1. Apply WFH Tomorrow</div>
                            <div class="ams-hub-prompt-text">Apply for Work From Home tomorrow due to personal work</div>
                            <div class="ams-hub-prompt-action">
                                <span class="ams-hub-prompt-desc">Validates date, verifies approver, asks confirmation</span>
                                <button type="button" class="ams-use-prompt-btn" data-prompt="Apply for Work From Home tomorrow due to personal work">
                                    <i class="fa-solid fa-paper-plane"></i> Use Prompt
                                </button>
                            </div>
                        </div>

                        <div class="ams-hub-prompt-card">
                            <div class="ams-hub-prompt-title">2. WFH with Specific Date Range</div>
                            <div class="ams-hub-prompt-text">Apply for 2 days WFH from <?php echo date('Y-m-d', strtotime('+1 day')); ?> to <?php echo date('Y-m-d', strtotime('+2 days')); ?> due to home internet repair</div>
                            <div class="ams-hub-prompt-action">
                                <span class="ams-hub-prompt-desc">Parses start/end dates and prompts confirmation</span>
                                <button type="button" class="ams-use-prompt-btn" data-prompt="Apply for 2 days WFH from <?php echo date('Y-m-d', strtotime('+1 day')); ?> to <?php echo date('Y-m-d', strtotime('+2 days')); ?> due to home internet repair">
                                    <i class="fa-solid fa-paper-plane"></i> Use Prompt
                                </button>
                            </div>
                        </div>

                        <div class="ams-hub-prompt-card">
                            <div class="ams-hub-prompt-title">3. Check Current WFH Status</div>
                            <div class="ams-hub-prompt-text">What is my current Work From Home status?</div>
                            <div class="ams-hub-prompt-action">
                                <span class="ams-hub-prompt-desc">Checks pending, approved, or rejected requests</span>
                                <button type="button" class="ams-use-prompt-btn" data-prompt="What is my current Work From Home status?">
                                    <i class="fa-solid fa-paper-plane"></i> Use Prompt
                                </button>
                            </div>
                        </div>

                        <div class="ams-hub-prompt-card">
                            <div class="ams-hub-prompt-title">4. WFH Policy & Guidelines</div>
                            <div class="ams-hub-prompt-text">What is the procedure and policy for Work From Home?</div>
                            <div class="ams-hub-prompt-action">
                                <span class="ams-hub-prompt-desc">Explains approval flow & rules</span>
                                <button type="button" class="ams-use-prompt-btn" data-prompt="What is the procedure and policy for Work From Home?">
                                    <i class="fa-solid fa-paper-plane"></i> Use Prompt
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ════════ 2. TASK ALLOCATION FEATURE ════════ -->
                <div id="ams-hub-panel-task_assign" class="ams-hub-feature-panel">
                    <!-- Mode: Prompt Demo View -->
                    <div class="ams-hub-mode-content" data-mode="prompt">
                        <div class="ams-hub-prompt-intro">
                            <strong><i class="fa-solid fa-wand-magic-sparkles"></i> AI Task Prompts:</strong> You can assign tasks by speaking directly to the AI.
                        </div>

                        <div class="ams-hub-prompt-card">
                            <div class="ams-hub-prompt-title">1. Assign Task to Specific Project</div>
                            <div class="ams-hub-prompt-text">Assign task "Navbar responsiveness bug" to project "AMS" with deadline tomorrow</div>
                            <div class="ams-hub-prompt-action">
                                <span class="ams-hub-prompt-desc">Resolves project name & prompts confirmation</span>
                                <button type="button" class="ams-use-prompt-btn" data-prompt="Assign task 'Navbar responsiveness bug' to project 'AMS' with deadline tomorrow">
                                    <i class="fa-solid fa-paper-plane"></i> Use Prompt
                                </button>
                            </div>
                        </div>

                        <div class="ams-hub-prompt-card">
                            <div class="ams-hub-prompt-title">2. View Active Assigned Tasks</div>
                            <div class="ams-hub-prompt-text">Show my allocated tasks and active assignments</div>
                            <div class="ams-hub-prompt-action">
                                <span class="ams-hub-prompt-desc">Fetches tasks assigned to you</span>
                                <button type="button" class="ams-use-prompt-btn" data-prompt="Show my allocated tasks and active assignments">
                                    <i class="fa-solid fa-paper-plane"></i> Use Prompt
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ════════ 3. TASK LOGGING (DIARY) FEATURE ════════ -->
                <div id="ams-hub-panel-task_log" class="ams-hub-feature-panel">
                    <!-- Mode: Prompt Demo View -->
                    <div class="ams-hub-mode-content" data-mode="prompt">
                        <div class="ams-hub-prompt-intro">
                            <strong><i class="fa-solid fa-wand-magic-sparkles"></i> AI Task Logging Prompts:</strong> Log your work with a single sentence.
                        </div>

                        <div class="ams-hub-prompt-card">
                            <div class="ams-hub-prompt-title">1. Log Work with Duration</div>
                            <div class="ams-hub-prompt-text">Log 2 hours on Project AMS for dashboard responsiveness and bug fixes</div>
                            <div class="ams-hub-prompt-action">
                                <span class="ams-hub-prompt-desc">Parses project, duration & description</span>
                                <button type="button" class="ams-use-prompt-btn" data-prompt="Log 2 hours on Project AMS for dashboard responsiveness and bug fixes">
                                    <i class="fa-solid fa-paper-plane"></i> Use Prompt
                                </button>
                            </div>
                        </div>

                        <div class="ams-hub-prompt-card">
                            <div class="ams-hub-prompt-title">2. Show Today's Tasks</div>
                            <div class="ams-hub-prompt-text">show my tasks for today</div>
                            <div class="ams-hub-prompt-action">
                                <span class="ams-hub-prompt-desc">Displays summary of today's diary entries</span>
                                <button type="button" class="ams-use-prompt-btn" data-prompt="show my tasks for today">
                                    <i class="fa-solid fa-paper-plane"></i> Use Prompt
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ════════ 4. TOUR APPLICATION FEATURE ════════ -->
                <div id="ams-hub-panel-tour" class="ams-hub-feature-panel">
                    <!-- Mode: Prompt Demo View -->
                    <div class="ams-hub-mode-content" data-mode="prompt">
                        <div class="ams-hub-prompt-intro">
                            <strong><i class="fa-solid fa-wand-magic-sparkles"></i> AI Tour Prompts:</strong> Ask the AI to apply for a tour or verify requirements.
                        </div>

                        <div class="ams-hub-prompt-card">
                            <div class="ams-hub-prompt-title">1. Apply for Tour</div>
                            <div class="ams-hub-prompt-text">Apply tour for project Vanamati from <?php echo date('Y-m-d', strtotime('+3 days')); ?> to <?php echo date('Y-m-d', strtotime('+5 days')); ?> for client meeting</div>
                            <div class="ams-hub-prompt-action">
                                <span class="ams-hub-prompt-desc">Checks for leave conflicts</span>
                                <button type="button" class="ams-use-prompt-btn" data-prompt="Apply tour for project Vanamati from <?php echo date('Y-m-d', strtotime('+3 days')); ?> to <?php echo date('Y-m-d', strtotime('+5 days')); ?> for client meeting">
                                    <i class="fa-solid fa-paper-plane"></i> Use Prompt
                                </button>
                            </div>
                        </div>

                        <div class="ams-hub-prompt-card">
                            <div class="ams-hub-prompt-title">2. Tour Rules & Guidelines</div>
                            <div class="ams-hub-prompt-text">What are the guidelines and leave rules for Tour Days (TD)?</div>
                            <div class="ams-hub-prompt-action">
                                <span class="ams-hub-prompt-desc">RAG policy lookup</span>
                                <button type="button" class="ams-use-prompt-btn" data-prompt="What are the guidelines and leave rules for Tour Days (TD)?">
                                    <i class="fa-solid fa-paper-plane"></i> Use Prompt
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ════════ 5. LATE PUNCH IN INTIMATION ════════ -->
                <div id="ams-hub-panel-late_punch" class="ams-hub-feature-panel">
                    <!-- Mode: Prompt Demo View -->
                    <div class="ams-hub-mode-content" data-mode="prompt">
                        <div class="ams-hub-prompt-intro">
                            <strong><i class="fa-solid fa-wand-magic-sparkles"></i> AI Late Intimation Prompts:</strong> Intimate HR or ask about timing policies.
                        </div>

                        <div class="ams-hub-prompt-card">
                            <div class="ams-hub-prompt-title">1. Intimate HR for Today</div>
                            <div class="ams-hub-prompt-text">Intimate HR that I will reach office by 10:15 AM today due to traffic</div>
                            <div class="ams-hub-prompt-action">
                                <span class="ams-hub-prompt-desc">Sends intimation notice to HR</span>
                                <button type="button" class="ams-use-prompt-btn" data-prompt="Intimate HR that I will reach office by 10:15 AM today due to traffic">
                                    <i class="fa-solid fa-paper-plane"></i> Use Prompt
                                </button>
                            </div>
                        </div>

                        <div class="ams-hub-prompt-card">
                            <div class="ams-hub-prompt-title">2. Late Mark Policy & Grace Period</div>
                            <div class="ams-hub-prompt-text">what is the late mark policy?</div>
                            <div class="ams-hub-prompt-action">
                                <span class="ams-hub-prompt-desc">Explains 15-min grace and monthly penalty</span>
                                <button type="button" class="ams-use-prompt-btn" data-prompt="what is the late mark policy?">
                                    <i class="fa-solid fa-paper-plane"></i> Use Prompt
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

    </div>
</div>

<script>
// Enterprise Chatbot & Actions Hub Controller
(function() {
    const widgetBtn = document.getElementById('ams-chat-widget-btn');
    const chatWindow = document.getElementById('ams-chat-window');
    const closeBtn = document.getElementById('ams-chat-close');
    const clearBtn = document.getElementById('ams-chat-clear');
    const chatForm = document.getElementById('ams-chat-input-area');
    const chatInput = document.getElementById('ams-chat-input');
    const chatMessages = document.getElementById('ams-chat-messages');
    const typingIndicator = document.getElementById('ams-chat-typing');
    const chips = document.querySelectorAll('.ams-chat-chip');

    if (!widgetBtn || !chatWindow) return;

    // Navigation Tabs & Views
    const tabBtnChat = document.getElementById('ams-tab-btn-chat');
    const tabBtnHub = document.getElementById('ams-tab-btn-hub');
    const toggleHubBtn = document.getElementById('ams-chat-toggle-hub');
    const viewChat = document.getElementById('ams-chat-view-chat');
    const viewHub = document.getElementById('ams-chat-view-hub');

    // History Elements
    const historyBtn = document.getElementById('ams-chat-history-btn');
    const historyPanel = document.getElementById('ams-chat-history-panel');
    const historyBackBtn = document.getElementById('ams-chat-history-back');
    const historyClearBtn = document.getElementById('ams-chat-clear-history');
    const historyList = document.getElementById('ams-history-list');

    // URLs
    const proxyUrl = '<?php echo (strpos($_SERVER["REQUEST_URI"] ?? "", "/pages/") !== false ? "../ajax/ai_chat_proxy.php" : "ajax/ai_chat_proxy.php"); ?>';
    const actionSubmitUrl = '<?php echo (strpos($_SERVER["REQUEST_URI"] ?? "", "/pages/") !== false ? "../ajax/ai_action_submit.php" : "ajax/ai_action_submit.php"); ?>';

    // ── SessionStorage Persistence Keys & Conversation ID ─────────────────────
    const STORAGE_KEY_HTML = 'ams_chat_history_html';
    const STORAGE_KEY_OPEN = 'ams_chat_is_open';
    const STORAGE_KEY_TAB  = 'ams_chat_active_tab';
    const STORAGE_KEY_CAT  = 'ams_chat_active_hub_cat';
    const STORAGE_KEY_CONV = 'ams_chat_conversation_id';

    let conversationId = '';
    try {
        conversationId = sessionStorage.getItem(STORAGE_KEY_CONV);
        if (!conversationId) {
            conversationId = 'conv_' + Date.now() + '_' + Math.random().toString(36).substring(2, 9);
            sessionStorage.setItem(STORAGE_KEY_CONV, conversationId);
        }
    } catch(e) {
        conversationId = 'conv_' + Date.now();
    }

    const DEFAULT_WELCOME_HTML = `
        <div class="ams-chat-message bot">
            <div class="ams-chat-msg-avatar"><i class="fa-solid fa-robot"></i></div>
            <div class="ams-chat-msg-bubble">
                Welcome to <strong>SETTribe AMS Assistant</strong>. How may I assist you with your attendance, leaves, tasks, or policies today?
                <div style="margin-top:8px;padding-top:8px;border-top:1px dashed #e2e8f0;display:flex;gap:6px;flex-wrap:wrap;">
                    <button class="ams-use-prompt-btn ams-hub-open-trigger" data-open-hub="leave" style="font-size:10.5px;">
                        <i class="fa-solid fa-calendar-days"></i> Leave Prompts
                    </button>
                    <button class="ams-use-prompt-btn ams-hub-open-trigger" data-open-hub="wfh" style="font-size:10.5px;">
                        <i class="fa-solid fa-house-laptop"></i> Apply WFH
                    </button>
                    <button class="ams-use-prompt-btn ams-hub-open-trigger" data-open-hub="task_assign" style="font-size:10.5px;">
                        <i class="fa-solid fa-user-plus"></i> Assign Task
                    </button>
                    <button class="ams-use-prompt-btn ams-hub-open-trigger" data-open-hub="tour" style="font-size:10.5px;">
                        <i class="fa-solid fa-plane-departure"></i> Apply Tour
                    </button>
                </div>
            </div>
        </div>
    `;

    function saveChatState() {
        try {
            const isTyping = (typingIndicator && typingIndicator.parentNode === chatMessages);
            if (isTyping) {
                chatMessages.removeChild(typingIndicator);
            }
            sessionStorage.setItem(STORAGE_KEY_HTML, chatMessages.innerHTML);
            sessionStorage.setItem(STORAGE_KEY_OPEN, chatWindow.classList.contains('show') ? 'true' : 'false');
            sessionStorage.setItem(STORAGE_KEY_TAB, viewHub && viewHub.classList.contains('active') ? 'hub' : 'chat');
            const activeCat = document.querySelector('.ams-hub-cat-btn.active');
            if (activeCat) {
                sessionStorage.setItem(STORAGE_KEY_CAT, activeCat.getAttribute('data-cat') || 'leave');
            }
            if (isTyping) {
                chatMessages.appendChild(typingIndicator);
            }
        } catch(e) {
            console.warn("Could not save AMS chat state to sessionStorage:", e);
        }
    }

    function restoreChatState() {
        try {
            const savedHtml = sessionStorage.getItem(STORAGE_KEY_HTML);
            if (savedHtml && savedHtml.trim().length > 0) {
                chatMessages.innerHTML = savedHtml;
                scrollToBottom();
            }

            const wasOpen = sessionStorage.getItem(STORAGE_KEY_OPEN);
            if (wasOpen === 'true') {
                chatWindow.classList.add('show');
            }

            const savedTab = sessionStorage.getItem(STORAGE_KEY_TAB);
            if (savedTab === 'hub') {
                switchMainTab('hub', false);
            }

            const savedCat = sessionStorage.getItem(STORAGE_KEY_CAT);
            if (savedCat) {
                selectHubCategory(savedCat, false);
            }
        } catch(e) {
            console.warn("Could not restore AMS chat state from sessionStorage:", e);
        }
    }

    // Toggle Chat Window
    widgetBtn.addEventListener('click', function() {
        chatWindow.classList.toggle('show');
        if (chatWindow.classList.contains('show')) {
            // Close theme settings drawer if open
            const themePanel = document.getElementById('theme-settings');
            if (themePanel && themePanel.classList.contains('open')) {
                themePanel.classList.remove('open');
            }
            if (viewChat.style.display !== 'none') {
                chatInput.focus();
                scrollToBottom();
            }
        }
        saveChatState();
    });

    closeBtn.addEventListener('click', function() {
        chatWindow.classList.remove('show');
        if (historyPanel) historyPanel.classList.remove('active');
        saveChatState();
    });

    // ── Tab View Switcher (AI Chat vs Forms & Prompts Hub) ─────────────────────
    function switchMainTab(tab, shouldSave = true) {
        if (historyPanel) historyPanel.classList.remove('active');
        if (tab === 'chat') {
            tabBtnChat.classList.add('active');
            tabBtnHub.classList.remove('active');
            viewChat.style.display = 'flex';
            viewHub.classList.remove('active');
            chatInput.focus();
            scrollToBottom();
        } else {
            tabBtnChat.classList.remove('active');
            tabBtnHub.classList.add('active');
            viewChat.style.display = 'none';
            viewHub.classList.add('active');
        }
        if (shouldSave) saveChatState();
    }

    if (tabBtnChat) tabBtnChat.addEventListener('click', () => switchMainTab('chat'));
    if (tabBtnHub) tabBtnHub.addEventListener('click', () => switchMainTab('hub'));
    if (toggleHubBtn) toggleHubBtn.addEventListener('click', () => {
        const isHubActive = viewHub.classList.contains('active');
        switchMainTab(isHubActive ? 'chat' : 'hub');
    });

    // ── Hub Category Switcher ──────────────────────────────────────────────────
    const catBtns = document.querySelectorAll('.ams-hub-cat-btn');
    function selectHubCategory(catName, shouldSave = true) {
        catBtns.forEach(btn => {
            btn.classList.toggle('active', btn.getAttribute('data-cat') === catName);
        });
        document.querySelectorAll('.ams-hub-feature-panel').forEach(panel => {
            panel.classList.toggle('active', panel.id === 'ams-hub-panel-' + catName);
        });
        if (shouldSave) saveChatState();
    }

    catBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            selectHubCategory(this.getAttribute('data-cat'));
        });
    });

    // ── Hub Sub-Mode Controller (AI Prompt Demo) ───────────────────────────
    const modeBtns = document.querySelectorAll('.ams-hub-mode-btn');
    modeBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            const mode = this.getAttribute('data-mode') || 'prompt';
            modeBtns.forEach(b => b.classList.toggle('active', b.getAttribute('data-mode') === mode));
            
            document.querySelectorAll('.ams-hub-feature-panel').forEach(panel => {
                const promptViews = panel.querySelectorAll('.ams-hub-mode-content[data-mode="prompt"]');
                promptViews.forEach(v => v.style.display = 'block');
            });
        });
    });

    // Helper: Open specific Hub category directly
    function openHubFeature(catName, mode = 'prompt') {
        switchMainTab('hub');
        selectHubCategory(catName);
        const targetModeBtn = document.querySelector(`.ams-hub-mode-btn[data-mode="${mode}"]`);
        if (targetModeBtn) targetModeBtn.click();
    }

    // Delegate chips that open Hub prompts directly (works for static and restored HTML)
    document.addEventListener('click', function(e) {
        const chip = e.target.closest('.ams-hub-chip, .ams-hub-open-trigger');
        if (chip) {
            e.stopPropagation();
            const cat = chip.getAttribute('data-open-hub');
            if (cat) openHubFeature(cat, 'prompt');
        }
    });

    // ── "⚡ Use Prompt" Button Handler ─────────────────────────────────────────
    document.addEventListener('click', function(e) {
        const promptBtn = e.target.closest('.ams-use-prompt-btn[data-prompt]');
        if (promptBtn) {
            const promptText = promptBtn.getAttribute('data-prompt');
            if (promptText) {
                switchMainTab('chat');
                chatInput.value = promptText;
                chatForm.dispatchEvent(new Event('submit', { cancelable: true }));
            }
        }
    });

    // ── History Panel Controller ───────────────────────────────────────────────
    function loadChatHistory() {
        if (!historyList) return;
        historyList.innerHTML = `
            <div class="ams-history-empty">
                <i class="fa-solid fa-spinner fa-spin"></i>
                Loading previous chats...
            </div>
        `;

        fetch(proxyUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'get_history' })
        })
        .then(res => res.json())
        .then(data => {
            if (data && data.success && Array.isArray(data.history) && data.history.length > 0) {
                let html = '';
                data.history.forEach(item => {
                    const cleanReply = (item.reply || '').replace(/[#*`_]/g, '').trim();
                    const snippet = cleanReply.length > 100 ? cleanReply.substring(0, 100) + '...' : cleanReply;
                    html += `
                        <div class="ams-history-card" data-query="${escapeHtml(item.query || '')}">
                            <div class="ams-history-card-header">
                                <span><i class="fa-regular fa-calendar-check" style="color:#2563eb;"></i> Chat</span>
                                <span>${escapeHtml(item.time || '')}</span>
                            </div>
                            <div class="ams-history-card-query">
                                <i class="fa-solid fa-magnifying-glass"></i>
                                <span>${escapeHtml(item.query || '')}</span>
                            </div>
                            ${snippet ? `<div class="ams-history-card-reply">${escapeHtml(snippet)}</div>` : ''}
                        </div>
                    `;
                });
                historyList.innerHTML = html;

                historyList.querySelectorAll('.ams-history-card').forEach(card => {
                    card.addEventListener('click', function() {
                        const q = this.getAttribute('data-query');
                        if (q) {
                            historyPanel.classList.remove('active');
                            switchMainTab('chat');
                            chatInput.value = q;
                            chatForm.dispatchEvent(new Event('submit', { cancelable: true }));
                        }
                    });
                });
            } else {
                historyList.innerHTML = `
                    <div class="ams-history-empty">
                        <i class="fa-regular fa-comment-dots"></i>
                        No previous chat history found.<br>
                        <span style="font-size:10.5px;color:#94a3b8;">Your past chats will appear here.</span>
                    </div>
                `;
            }
        })
        .catch(err => {
            console.error("Error loading chat history:", err);
            historyList.innerHTML = `
                <div class="ams-history-empty">
                    <i class="fa-solid fa-triangle-exclamation" style="color:#f59e0b;"></i>
                    Could not load history.
                </div>
            `;
        });
    }

    if (historyBtn && historyPanel) {
        historyBtn.addEventListener('click', function() {
            const isActive = historyPanel.classList.toggle('active');
            if (isActive) {
                loadChatHistory();
            }
        });
    }

    if (historyBackBtn && historyPanel) {
        historyBackBtn.addEventListener('click', function() {
            historyPanel.classList.remove('active');
            chatInput.focus();
        });
    }

    if (historyClearBtn && historyPanel) {
        historyClearBtn.addEventListener('click', function() {
            if (!confirm("Are you sure you want to clear your chat history?")) return;

            fetch(proxyUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'clear_history' })
            })
            .then(res => res.json())
            .then(data => {
                if (data && data.success) {
                    historyList.innerHTML = `
                        <div class="ams-history-empty">
                            <i class="fa-regular fa-trash-can"></i>
                            History cleared.
                        </div>
                    `;
                }
            })
            .catch(err => console.error("Error clearing history:", err));
        });
    }

    // Reset Chat
    if (clearBtn) {
        clearBtn.addEventListener('click', function() {
            chatMessages.innerHTML = DEFAULT_WELCOME_HTML;
            conversationId = 'conv_' + Date.now() + '_' + Math.random().toString(36).substring(2, 9);
            try {
                sessionStorage.setItem(STORAGE_KEY_CONV, conversationId);
                sessionStorage.removeItem(STORAGE_KEY_HTML);
            } catch(e) {}
            scrollToBottom();
        });
    }

    // Quick Chips Trigger (only for direct prompt chips)
    chips.forEach(function(chip) {
        chip.addEventListener('click', function() {
            const prompt = this.getAttribute('data-prompt');
            if (prompt) {
                switchMainTab('chat');
                chatInput.value = prompt;
                chatForm.dispatchEvent(new Event('submit', { cancelable: true }));
            }
        });
    });

    // Close on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && chatWindow.classList.contains('show')) {
            chatWindow.classList.remove('show');
            saveChatState();
        }
    });

    function scrollToBottom() {
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }

    // Convert any raw emojis to sleek FontAwesome icons
    function replaceEmojisWithIcons(text) {
        if (!text) return '';
        const map = {
            '👋': '<i class="fa-solid fa-hand" style="color:#f59e0b;"></i>',
            '🌴': '<i class="fa-solid fa-calendar-days" style="color:#2563eb;"></i>',
            '🏖️': '<i class="fa-solid fa-umbrella-beach" style="color:#0284c7;"></i>',
            '🩺': '<i class="fa-solid fa-stethoscope" style="color:#ef4444;"></i>',
            '🌟': '<i class="fa-solid fa-star" style="color:#f59e0b;"></i>',
            '🚗': '<i class="fa-solid fa-car" style="color:#0284c7;"></i>',
            '📅': '<i class="fa-regular fa-calendar" style="color:#2563eb;"></i>',
            '⏰': '<i class="fa-regular fa-clock" style="color:#0284c7;"></i>',
            '⏱️': '<i class="fa-solid fa-stopwatch" style="color:#f59e0b;"></i>',
            '📜': '<i class="fa-solid fa-file-lines" style="color:#64748b;"></i>',
            '📋': '<i class="fa-solid fa-clipboard-list" style="color:#64748b;"></i>',
            '✅': '<i class="fa-solid fa-circle-check" style="color:#16a34a;"></i>',
            '❌': '<i class="fa-solid fa-circle-xmark" style="color:#dc2626;"></i>',
            '🔓': '<i class="fa-solid fa-lock-open" style="color:#16a34a;"></i>',
            '🔒': '<i class="fa-solid fa-lock" style="color:#64748b;"></i>',
            '🚀': '<i class="fa-solid fa-arrow-trend-up" style="color:#2563eb;"></i>',
            '💡': '<i class="fa-solid fa-lightbulb" style="color:#f59e0b;"></i>',
            '⚠️': '<i class="fa-solid fa-triangle-exclamation" style="color:#f59e0b;"></i>',
            '1️⃣': '<strong>1.</strong>',
            '2️⃣': '<strong>2.</strong>',
            '3️⃣': '<strong>3.</strong>',
            '4️⃣': '<strong>4.</strong>',
            '5️⃣': '<strong>5.</strong>'
        };
        let result = text;
        for (const [emoji, iconHtml] of Object.entries(map)) {
            result = result.split(emoji).join(iconHtml);
        }
        result = result.replace(/[\u{1F600}-\u{1F64F}\u{1F300}-\u{1F5FF}\u{1F680}-\u{1F6FF}\u{1F700}-\u{1F77F}\u{1F780}-\u{1F7FF}\u{1F800}-\u{1F8FF}\u{1F900}-\u{1F9FF}\u{1FA00}-\u{1FA6F}\u{1FA70}-\u{1FAFF}\u{2600}-\u{26FF}\u{2700}-\u{27BF}]/gu, '');
        return result;
    }

    // Safe Markdown Renderer using DOMPurify with auto responsive table wrap
    function renderSafeMarkdown(rawMarkdown) {
        if (!rawMarkdown) return '';
        const processed = replaceEmojisWithIcons(rawMarkdown);
        if (typeof marked !== 'undefined' && typeof DOMPurify !== 'undefined') {
            try {
                marked.setOptions({ gfm: true, breaks: true, tables: true });
                const rawHtml = marked.parse(processed);
                const sanitized = DOMPurify.sanitize(rawHtml, {
                    ADD_TAGS: ['i'],
                    ADD_ATTR: ['style', 'class']
                });
                const tempDiv = document.createElement('div');
                tempDiv.innerHTML = sanitized;
                tempDiv.querySelectorAll('table').forEach(tbl => {
                    const wrap = document.createElement('div');
                    wrap.className = 'ams-chat-table-wrap';
                    tbl.parentNode.insertBefore(wrap, tbl);
                    wrap.appendChild(tbl);
                });
                return tempDiv.innerHTML;
            } catch(e) {
                console.error("Markdown parse error:", e);
            }
        }
        const div = document.createElement('div');
        div.textContent = processed;
        return div.innerHTML.replace(/\n/g, '<br>');
    }

    // Form Submit Handler
    chatForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const message = chatInput.value.trim();
        if (!message) return;

        // Render user message
        const userMsgHtml = `
            <div class="ams-chat-message user">
                <div class="ams-chat-msg-avatar"><i class="fa-solid fa-user"></i></div>
                <div class="ams-chat-msg-bubble">${escapeHtml(message)}</div>
            </div>
        `;
        chatMessages.insertAdjacentHTML('beforeend', userMsgHtml);
        chatInput.value = '';
        typingIndicator.style.display = 'flex';
        chatMessages.appendChild(typingIndicator);
        scrollToBottom();
        saveChatState();

        // Server-Side Auth Proxy Request (Uses LangGraph Orchestration & RAG)
        fetch(proxyUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ message: message, conversation_id: conversationId })
        })
        .then(response => response.json())
        .then(data => {
            typingIndicator.style.display = 'none';

            if (data && data.success) {
                const replyText = data.response || data.reply || (typeof data.message === 'string' ? data.message : '');
                const safeContent = renderSafeMarkdown(replyText);
                
                // Determine if confirmation options (Yes / No) should be presented
                const isConfirmation = data.confirmation_required === true || 
                                       /reply\s+\*?\*?yes\*?\*?\s+(or\s+)?\*?\*?no\*?\*?/i.test(replyText) ||
                                       (/confirm/i.test(replyText) && /\byes\b/i.test(replyText) && /\bno\b/i.test(replyText));

                let confirmButtonsHtml = '';
                if (isConfirmation) {
                    confirmButtonsHtml = `
                        <div class="ams-chat-confirm-actions">
                            <button type="button" class="ams-confirm-btn ams-confirm-btn-yes" data-confirm-choice="Yes">
                                <i class="fa-solid fa-check"></i> Yes
                            </button>
                            <button type="button" class="ams-confirm-btn ams-confirm-btn-no" data-confirm-choice="No">
                                <i class="fa-solid fa-xmark"></i> No
                            </button>
                        </div>
                    `;
                }

                const botMsgHtml = `
                    <div class="ams-chat-message bot">
                        <div class="ams-chat-msg-avatar"><i class="fa-solid fa-robot"></i></div>
                        <div class="ams-chat-msg-bubble">
                            ${safeContent}
                            ${confirmButtonsHtml}
                        </div>
                    </div>
                `;
                chatMessages.insertAdjacentHTML('beforeend', botMsgHtml);
            } else {
                const errorMsg = data.error || data.message || 'Service is temporarily unreachable. Please try again in a moment.';
                const errHtml = `
                    <div class="ams-chat-message bot">
                        <div class="ams-chat-msg-avatar"><i class="fa-solid fa-circle-exclamation" style="color:#ef4444;"></i></div>
                        <div class="ams-chat-msg-bubble">
                            <div class="ams-chat-error-banner">${escapeHtml(errorMsg)}</div>
                        </div>
                    </div>
                `;
                chatMessages.insertAdjacentHTML('beforeend', errHtml);
            }
            scrollToBottom();
            saveChatState();
        })
        .catch(err => {
            console.error("Chatbot request error:", err);
            typingIndicator.style.display = 'none';
            const failHtml = `
                <div class="ams-chat-message bot">
                    <div class="ams-chat-msg-avatar"><i class="fa-solid fa-triangle-exclamation" style="color:#f59e0b;"></i></div>
                    <div class="ams-chat-msg-bubble">
                        <div class="ams-chat-error-banner">Connection error. Please verify the AI service is active.</div>
                    </div>
                </div>
            `;
            chatMessages.insertAdjacentHTML('beforeend', failHtml);
            scrollToBottom();
            saveChatState();
        });
    });

    // Delegated click handler for quick Yes / No confirmation buttons
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.ams-confirm-btn');
        if (!btn) return;
        const choice = btn.getAttribute('data-confirm-choice') || 'Yes';

        const container = btn.closest('.ams-chat-confirm-actions');
        if (container) {
            container.querySelectorAll('.ams-confirm-btn').forEach(b => {
                b.disabled = true;
                b.style.opacity = '0.5';
                b.style.pointerEvents = 'none';
            });
            btn.style.boxShadow = '0 0 0 2px #2563eb';
        }

        chatInput.value = choice;
        chatForm.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
    });

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Restore state from sessionStorage on page load
    restoreChatState();
})();
</script>
<?php endif; ?>