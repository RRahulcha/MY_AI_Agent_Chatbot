<?php
include('../include/db_config.php');
$current_page = basename($_SERVER['PHP_SELF']);
?>

<style>
    @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap');

    /* ── Content Wrapper Fix ── */
    .content-wrapper {
        background: #F8FAFC !important;
        padding: 1.5rem 1.25rem !important;
    }

    .grid-margin {
        margin-bottom: 0.85rem !important;
    }

    /* ── Root Sticky / Fixed Sidebar ── */
    .sidebar {
        background: #ffffff !important;
        font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif !important;
        width: 235px !important;
        border-right: 1px solid #eef2f6 !important;
        box-shadow: 1px 0 4px rgba(15, 23, 42, 0.02) !important;
        transition: width 0.25s cubic-bezier(0.4, 0, 0.2, 1), background 0.25s ease !important;
        z-index: 1025 !important;
    }

    @media screen and (min-width: 992px) {
        .sidebar {
            position: fixed !important;
            top: 60px !important;
            left: 0 !important;
            bottom: 0 !important;
            height: calc(100vh - 60px) !important;
            max-height: calc(100vh - 60px) !important;
            overflow-y: auto !important;
            overflow-x: hidden !important;
        }

        .main-panel {
            margin-left: 235px !important;
            width: calc(100% - 235px) !important;
            min-height: calc(100vh - 60px) !important;
            transition: margin-left 0.25s ease, width 0.25s ease !important;
        }

        body.sidebar-icon-only .sidebar {
            width: 70px !important;
        }

        body.sidebar-icon-only .main-panel {
            margin-left: 70px !important;
            width: calc(100% - 70px) !important;
        }
    }

    @media screen and (max-width: 991px) {
        .main-panel {
            margin-left: 0 !important;
            width: 100% !important;
        }
    }

    /* Slim modern scrollbar */
    .sidebar::-webkit-scrollbar {
        width: 4px !important;
    }
    .sidebar::-webkit-scrollbar-track {
        background: transparent !important;
    }
    .sidebar::-webkit-scrollbar-thumb {
        background: #e2e8f0 !important;
        border-radius: 999px !important;
    }
    .sidebar:hover::-webkit-scrollbar-thumb {
        background: #cbd5e1 !important;
    }

    /* ── Main Nav List ── */
    .sidebar .nav {
        overflow-x: hidden !important;
        flex-wrap: nowrap !important;
        flex-direction: column !important;
        margin: 0 !important;
        padding: 12px 10px 80px 10px !important;
        gap: 2px !important;
    }

    /* ── Top-Level Nav Item ── */
    .sidebar .nav:not(.sub-menu) > .nav-item {
        margin: 1px 0 !important;
        border-radius: 10px !important;
        background: transparent !important;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1) !important;
        position: relative !important;
    }

    /* Top-Level Nav Link */
    .sidebar .nav:not(.sub-menu) > .nav-item > .nav-link {
        display: flex !important;
        align-items: center !important;
        white-space: nowrap !important;
        padding: 9px 12px !important;
        color: #475569 !important;
        border-radius: 10px !important;
        font-size: 13px !important;
        font-weight: 500 !important;
        letter-spacing: -0.01em !important;
        line-height: 1.35 !important;
        background: transparent !important;
        text-decoration: none !important;
        transition: all 0.18s cubic-bezier(0.4, 0, 0.2, 1) !important;
    }

    /* Menu Title */
    .sidebar .nav .nav-item .nav-link .menu-title {
        display: block !important;
        line-height: 1.35 !important;
        font-size: 13px !important;
        color: inherit !important;
        white-space: normal !important;
        overflow: visible !important;
        text-overflow: unset !important;
        flex: 1 1 auto !important;
        transition: color 0.18s ease !important;
    }

    /* Menu Icons */
    .sidebar .nav .nav-item .nav-link i.menu-icon {
        font-size: 15px !important;
        width: 22px !important;
        height: 22px !important;
        min-width: 22px !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        margin-right: 10px !important;
        color: #64748b !important;
        transition: color 0.18s ease, transform 0.18s ease !important;
    }

    /* Top-Level Hover (when NOT active) */
    .sidebar .nav:not(.sub-menu) > .nav-item:not(.active):hover > .nav-link {
        background: #f1f5f9 !important;
        color: #0f172a !important;
        transform: translateX(2px) !important;
    }
    .sidebar .nav:not(.sub-menu) > .nav-item:not(.active):hover > .nav-link i.menu-icon {
        color: #2563eb !important;
        transform: scale(1.06) !important;
    }

    /* Top-Level Active - Single Page (Direct Link) */
    .sidebar .nav:not(.sub-menu) > .nav-item.active > .nav-link:not([data-bs-toggle='collapse']) {
        background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 100%) !important;
        color: #ffffff !important;
        font-weight: 600 !important;
        box-shadow: 0 4px 12px rgba(37, 99, 235, 0.22) !important;
        transform: none !important;
    }
    .sidebar .nav:not(.sub-menu) > .nav-item.active > .nav-link:not([data-bs-toggle='collapse']) i.menu-icon {
        color: #ffffff !important;
    }

    /* Top-Level Parent Link when Collapsible is Active or Expanded */
    .sidebar .nav:not(.sub-menu) > .nav-item.active > .nav-link[data-bs-toggle='collapse'],
    .sidebar .nav:not(.sub-menu) > .nav-item > .nav-link[data-bs-toggle='collapse'][aria-expanded='true'] {
        background: #eff6ff !important;
        color: #1d4ed8 !important;
        font-weight: 600 !important;
    }
    .sidebar .nav:not(.sub-menu) > .nav-item.active > .nav-link[data-bs-toggle='collapse'] i.menu-icon,
    .sidebar .nav:not(.sub-menu) > .nav-item > .nav-link[data-bs-toggle='collapse'][aria-expanded='true'] i.menu-icon {
        color: #2563eb !important;
    }

    /* ── Chevron Arrow ── */
    .sidebar .nav .nav-item .nav-link .menu-arrow {
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        margin-left: auto !important;
        margin-right: 0 !important;
        width: 14px !important;
        height: 14px !important;
        font-size: 11px !important;
        color: #94a3b8 !important;
        transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1), color 0.2s ease !important;
        transform: rotate(0deg) !important;
    }
    .sidebar .nav .nav-item .nav-link .menu-arrow::before {
        display: none !important; /* Hide old themify pseudo-element */
    }
    .sidebar .nav .nav-item.active > .nav-link .menu-arrow,
    .sidebar .nav .nav-item > .nav-link[aria-expanded='true'] .menu-arrow {
        color: #2563eb !important;
    }
    .sidebar .nav .nav-item > .nav-link[aria-expanded='true'] .menu-arrow {
        transform: rotate(90deg) !important;
    }

    /* ── Submenu Container ── */
    .sidebar .collapse {
        overflow: visible !important;
    }
    .sidebar .collapse .sub-menu {
        display: none;
    }
    .sidebar .collapse.show .sub-menu {
        display: block !important;
    }

    .sidebar .nav.sub-menu {
        background: transparent !important;
        list-style: none !important;
        margin: 3px 0 5px 18px !important;
        padding: 3px 0 3px 10px !important;
        border-left: 2px solid #e2e8f0 !important;
    }

    .sidebar .nav.sub-menu .nav-item {
        margin: 2px 0 !important;
        padding: 0 !important;
        position: relative !important;
        background: transparent !important;
    }

    .sidebar .nav.sub-menu .nav-item::before {
        display: none !important; /* Remove old bullet dot */
    }

    /* Submenu Link */
    .sidebar .nav.sub-menu .nav-item .nav-link {
        display: block !important;
        padding: 6px 11px !important;
        color: #64748b !important;
        border-radius: 8px !important;
        font-size: 12.5px !important;
        font-weight: 500 !important;
        line-height: 1.35 !important;
        background: transparent !important;
        text-decoration: none !important;
        transition: all 0.16s ease !important;
        white-space: normal !important;
    }

    .sidebar .nav.sub-menu .nav-item .nav-link:hover {
        background: #f1f5f9 !important;
        color: #0f172a !important;
        padding-left: 14px !important;
    }

    /* Submenu Link Active */
    .sidebar .nav.sub-menu .nav-item .nav-link.active,
    .sidebar .nav.sub-menu .nav-item.active > .nav-link {
        background: #eff6ff !important;
        color: #1d4ed8 !important;
        font-weight: 600 !important;
        box-shadow: inset 3px 0 0 #2563eb !important;
        padding-left: 14px !important;
    }

    /* ── Desktop Icon-Only Mode (body.sidebar-icon-only) ── */
    body.sidebar-icon-only .sidebar {
        width: 70px !important;
        min-width: 70px !important;
        max-width: 70px !important;
        overflow-x: hidden !important;
    }

    body.sidebar-icon-only .sidebar .nav,
    body.sidebar-icon-only .sidebar .nav:not(.sub-menu) {
        width: 70px !important;
        margin: 0 !important;
        padding: 8px 0 80px 0 !important;
        overflow-x: hidden !important;
        display: flex !important;
        flex-direction: column !important;
        align-items: center !important;
    }

    body.sidebar-icon-only .sidebar .nav .nav-item,
    body.sidebar-icon-only .sidebar .nav:not(.sub-menu) > .nav-item {
        width: 70px !important;
        min-width: 70px !important;
        max-width: 70px !important;
        margin: 4px 0 !important;
        padding: 0 !important;
        display: flex !important;
        justify-content: center !important;
        align-items: center !important;
        position: relative !important;
        background: transparent !important;
    }

    /* Fixed centered 46px card for nav-link */
    body.sidebar-icon-only .sidebar .nav .nav-item .nav-link,
    body.sidebar-icon-only .sidebar .nav:not(.sub-menu) > .nav-item > .nav-link {
        width: 46px !important;
        height: 46px !important;
        min-width: 46px !important;
        max-width: 46px !important;
        min-height: 46px !important;
        max-height: 46px !important;
        margin: 0 auto !important;
        padding: 0 !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        text-align: center !important;
        border-radius: 12px !important;
        transform: none !important;
        box-sizing: border-box !important;
        position: relative !important;
        background: transparent !important;
        color: #475569 !important;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1) !important;
    }

    /* Perfectly centered icon inside the card */
    body.sidebar-icon-only .sidebar .nav .nav-item .nav-link i.menu-icon,
    body.sidebar-icon-only .sidebar .nav:not(.sub-menu) > .nav-item > .nav-link i.menu-icon {
        margin: 0 !important;
        padding: 0 !important;
        width: 24px !important;
        height: 24px !important;
        min-width: 24px !important;
        font-size: 19px !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        line-height: 1 !important;
        color: inherit !important;
        transform: none !important;
        float: none !important;
    }

    /* Hover State (when not active) */
    body.sidebar-icon-only .sidebar .nav:not(.sub-menu) > .nav-item:not(.active):hover > .nav-link,
    body.sidebar-icon-only .sidebar .nav .nav-item:not(.active):hover > .nav-link {
        background: #f1f5f9 !important;
        color: #1d4ed8 !important;
        transform: none !important;
        border-radius: 12px !important;
        box-shadow: 0 2px 8px rgba(15, 23, 42, 0.08) !important;
    }

    body.sidebar-icon-only .sidebar .nav .nav-item:not(.active):hover > .nav-link i.menu-icon {
        color: #1d4ed8 !important;
        transform: scale(1.1) !important;
    }

    /* Active State (Centered Royal Blue Card) */
    body.sidebar-icon-only .sidebar .nav:not(.sub-menu) > .nav-item.active > .nav-link,
    body.sidebar-icon-only .sidebar .nav .nav-item.active > .nav-link,
    body.sidebar-icon-only .sidebar .nav:not(.sub-menu) > .nav-item > .nav-link[data-bs-toggle='collapse'][aria-expanded='true'],
    body.sidebar-icon-only .sidebar .nav:not(.sub-menu) > .nav-item.active > .nav-link[data-bs-toggle='collapse'] {
        width: 46px !important;
        height: 46px !important;
        margin: 0 auto !important;
        background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 100%) !important;
        color: #ffffff !important;
        border-radius: 12px !important;
        box-shadow: 0 4px 14px rgba(37, 99, 235, 0.3) !important;
        transform: none !important;
    }

    body.sidebar-icon-only .sidebar .nav .nav-item.active > .nav-link i.menu-icon,
    body.sidebar-icon-only .sidebar .nav:not(.sub-menu) > .nav-item > .nav-link[data-bs-toggle='collapse'][aria-expanded='true'] i.menu-icon,
    body.sidebar-icon-only .sidebar .nav:not(.sub-menu) > .nav-item.active > .nav-link[data-bs-toggle='collapse'] i.menu-icon {
        color: #ffffff !important;
        margin: 0 !important;
        transform: none !important;
    }

    /* Nuke any old Skydash style.css flyout menu-title overlay completely */
    body.sidebar-icon-only .sidebar .nav .nav-item.hover-open .nav-link .menu-title,
    body.sidebar-icon-only .sidebar .nav .nav-item .nav-link .menu-title,
    body.sidebar-icon-only .sidebar .nav .nav-item.hover-open .nav-link:hover .menu-title,
    body.sidebar-icon-only .menu-title,
    body.sidebar-icon-only .menu-arrow,
    body.sidebar-icon-only .nav-item .nav-link span,
    body.sidebar-icon-only .badge {
        display: none !important;
        visibility: hidden !important;
        opacity: 0 !important;
        width: 0 !important;
        height: 0 !important;
        padding: 0 !important;
        margin: 0 !important;
        border: none !important;
        box-shadow: none !important;
        background: transparent !important;
        position: absolute !important;
        left: -9999px !important;
        pointer-events: none !important;
    }

    /* Prevent inline collapsibles from taking vertical space in the 70px sidebar */
    body.sidebar-icon-only .sidebar .nav .nav-item > .collapse,
    body.sidebar-icon-only .sidebar .nav .nav-item.hover-open > .collapse,
    body.sidebar-icon-only .sidebar .nav .nav-item > .collapse.show {
        display: none !important;
        visibility: hidden !important;
        height: 0 !important;
        overflow: hidden !important;
        padding: 0 !important;
        margin: 0 !important;
        border: none !important;
        box-shadow: none !important;
    }

    /* Floating Flyout Submenu Popup */
    body.sidebar-icon-only .sub-menu {
        position: fixed !important;
        left: 74px !important;
        background: #ffffff !important;
        border: 1px solid #e2e8f0 !important;
        border-radius: 12px !important;
        box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.16), 0 8px 10px -6px rgba(15, 23, 42, 0.08) !important;
        padding: 8px !important;
        z-index: 99999 !important;
        width: 220px !important;
        min-width: 220px !important;
        margin: 0 !important;
        display: none;
        animation: sidebarFlyoutFade 0.18s cubic-bezier(0.16, 1, 0.3, 1) !important;
    }

    @keyframes sidebarFlyoutFade {
        from { opacity: 0; transform: translateX(-6px); }
        to   { opacity: 1; transform: translateX(0); }
    }

    body.sidebar-icon-only .sub-menu .nav-item {
        margin: 2px 0 !important;
        width: 100% !important;
    }
    body.sidebar-icon-only .sub-menu .nav-link {
        width: 100% !important;
        height: auto !important;
        padding: 8px 12px !important;
        white-space: nowrap !important;
        border-radius: 6px !important;
        font-size: 13px !important;
        color: #334155 !important;
        font-weight: 500 !important;
        justify-content: flex-start !important;
        text-align: left !important;
    }
    body.sidebar-icon-only .sub-menu .nav-link:hover {
        background: #f1f5f9 !important;
        color: #0f172a !important;
    }
    body.sidebar-icon-only .sub-menu .nav-link.active {
        background: #eff6ff !important;
        color: #1d4ed8 !important;
        font-weight: 600 !important;
        box-shadow: none !important;
    }

    /* ── Mobile & Tablet Responsiveness (max-width: 991px) ── */
    @media screen and (max-width: 991px) {
        .sidebar.sidebar-offcanvas {
            position: fixed !important;
            top: 60px !important;
            bottom: 0 !important;
            left: -285px !important;
            right: auto !important;
            width: 275px !important;
            max-width: 85vw !important;
            height: calc(100vh - 60px) !important;
            max-height: calc(100vh - 60px) !important;
            overflow-y: auto !important;
            background: #ffffff !important;
            border-right: 1px solid #e2e8f0 !important;
            box-shadow: 6px 0 24px rgba(15, 23, 42, 0.18) !important;
            transition: left 0.28s cubic-bezier(0.4, 0, 0.2, 1) !important;
            z-index: 1040 !important;
        }

        .sidebar.sidebar-offcanvas.active {
            left: 0 !important;
            right: auto !important;
        }
    }

    /* ── Sidebar Header Bar (Attached directly to Sidebar) ── */
    .sidebar-header-bar {
        position: sticky !important;
        top: 0 !important;
        z-index: 40 !important;
        background: var(--ams-surface, #ffffff) !important;
        display: flex !important;
        align-items: center !important;
        justify-content: space-between !important;
        padding: 10px 14px !important;
        border-bottom: 1px solid #f1f5f9 !important;
        margin-bottom: 4px !important;
        min-height: 52px !important;
        box-sizing: border-box !important;
    }

    .sidebar-header-title {
        font-size: 11px !important;
        font-weight: 700 !important;
        letter-spacing: 0.08em !important;
        color: #64748b !important;
        text-transform: uppercase !important;
        user-select: none !important;
        display: flex !important;
        align-items: center !important;
        gap: 6px !important;
    }

    .sidebar-header-title::before {
        content: "" !important;
        display: inline-block !important;
        width: 6px !important;
        height: 6px !important;
        border-radius: 50% !important;
        background: #2563eb !important;
    }

    .sidebar-toggle-btn {
        width: 32px !important;
        height: 32px !important;
        border-radius: 8px !important;
        border: 1px solid #e2e8f0 !important;
        background: #f8fafc !important;
        color: #334155 !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        cursor: pointer !important;
        padding: 0 !important;
        margin: 0 !important;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1) !important;
        outline: none !important;
    }

    .sidebar-toggle-btn:hover {
        background: #eff6ff !important;
        border-color: #93c5fd !important;
        color: #1d4ed8 !important;
        transform: scale(1.05) !important;
        box-shadow: 0 2px 8px rgba(37, 99, 235, 0.15) !important;
    }

    .sidebar-toggle-btn svg {
        transition: transform 0.2s ease !important;
        flex-shrink: 0 !important;
    }

    .sidebar-toggle-btn .svg-collapse {
        display: inline-block !important;
    }

    .sidebar-toggle-btn .svg-expand {
        display: none !important;
    }

    .sidebar-toggle-btn .svg-close {
        display: none !important;
    }

    /* Floating edge expand pill on sidebar right border */
    .sidebar-edge-toggle {
        display: none;
    }

    /* Icon-Only Mode (70px desktop) */
    body.sidebar-icon-only .sidebar-header-bar {
        justify-content: center !important;
        padding: 8px 4px !important;
        min-height: 56px !important;
        background: var(--ams-surface, #ffffff) !important;
        border-bottom: 1px solid #e2e8f0 !important;
    }

    body.sidebar-icon-only .sidebar-header-title {
        display: none !important;
    }

    body.sidebar-icon-only .sidebar-toggle-btn {
        width: 48px !important;
        height: 40px !important;
        border-radius: 10px !important;
        background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%) !important;
        border: 1.5px solid #93c5fd !important;
        color: #1d4ed8 !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        box-shadow: 0 2px 8px rgba(37, 99, 235, 0.18) !important;
    }

    body.sidebar-icon-only .sidebar-toggle-btn:hover {
        background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%) !important;
        border-color: #1d4ed8 !important;
        color: #ffffff !important;
        transform: scale(1.06) !important;
        box-shadow: 0 4px 14px rgba(37, 99, 235, 0.35) !important;
    }

    body.sidebar-icon-only .sidebar-toggle-btn .svg-collapse {
        display: none !important;
    }

    body.sidebar-icon-only .sidebar-toggle-btn .svg-expand {
        display: inline-block !important;
    }

    body.sidebar-icon-only .sidebar-edge-toggle {
        display: flex !important;
        position: fixed !important;
        top: 74px !important;
        left: 57px !important;
        width: 24px !important;
        height: 24px !important;
        border-radius: 50% !important;
        background: #ffffff !important;
        border: 1.5px solid #93c5fd !important;
        color: #1d4ed8 !important;
        align-items: center !important;
        justify-content: center !important;
        cursor: pointer !important;
        z-index: 1030 !important;
        box-shadow: 0 2px 6px rgba(15, 23, 42, 0.16) !important;
        padding: 0 !important;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1) !important;
    }

    body.sidebar-icon-only .sidebar-edge-toggle:hover {
        background: #2563eb !important;
        color: #ffffff !important;
        border-color: #1d4ed8 !important;
        transform: scale(1.15) !important;
        box-shadow: 0 4px 12px rgba(37, 99, 235, 0.35) !important;
    }

    /* Mobile & Tablet Mode (<= 991px) */
    @media screen and (max-width: 991px) {
        .sidebar-header-title {
            color: #1e293b !important;
            font-size: 12px !important;
        }

        .sidebar-toggle-btn .svg-collapse,
        .sidebar-toggle-btn .svg-expand {
            display: none !important;
        }

        .sidebar-toggle-btn .svg-close {
            display: inline-block !important;
            color: #ef4444 !important;
        }

        body.sidebar-icon-only .sidebar-edge-toggle {
            display: none !important;
        }

        .mobile-sidebar-floating-btn {
            display: inline-flex !important;
            position: fixed !important;
            bottom: 22px !important;
            left: 20px !important;
            width: 44px !important;
            height: 44px !important;
            border-radius: 50% !important;
            background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 100%) !important;
            color: #ffffff !important;
            border: none !important;
            box-shadow: 0 4px 18px rgba(37, 99, 235, 0.4), 0 2px 6px rgba(0, 0, 0, 0.1) !important;
            z-index: 1030 !important;
            align-items: center !important;
            justify-content: center !important;
            font-size: 16px !important;
            cursor: pointer !important;
            transition: transform 0.2s cubic-bezier(0.4, 0, 0.2, 1), box-shadow 0.2s ease !important;
        }

        .mobile-sidebar-floating-btn:hover,
        .mobile-sidebar-floating-btn:active {
            transform: scale(1.08) !important;
            box-shadow: 0 6px 22px rgba(37, 99, 235, 0.5) !important;
        }
    }

    .mobile-sidebar-floating-btn {
        display: none;
    }

    /* ══════════════════════════════════════════════════════
       SIDEBAR DARK THEME OVERRIDES (BLACK, WHITE, BLUE PALETTE)
    ══════════════════════════════════════════════════════ */
    .dark-theme .sidebar,
    .dark-theme .sidebar.sidebar-offcanvas,
    [data-theme="dark"] .sidebar {
        background: #0f172a !important;
        border-right: 1px solid #1e293b !important;
        box-shadow: 1px 0 6px rgba(0, 0, 0, 0.35) !important;
    }

    html.dark-theme body .sidebar-header-bar,
    html.dark-theme body.sidebar-icon-only .sidebar-header-bar,
    body.dark-theme .sidebar-header-bar,
    body.dark-theme.sidebar-icon-only .sidebar-header-bar,
    .dark-theme .sidebar-header-bar,
    .dark-theme.sidebar-icon-only .sidebar-header-bar,
    [data-theme="dark"] .sidebar-header-bar {
        background: #0f172a !important;
        border-bottom: 1px solid #1e293b !important;
    }

    .dark-theme .sidebar-header-title {
        color: #94a3b8 !important;
    }

    .dark-theme .sidebar-toggle-btn {
        background: #1e293b !important;
        border: 1px solid #334155 !important;
        color: #94a3b8 !important;
    }

    .dark-theme .sidebar-toggle-btn:hover {
        background: #334155 !important;
        border-color: #60a5fa !important;
        color: #60a5fa !important;
    }

    .dark-theme .sidebar-edge-toggle,
    .dark-theme body.sidebar-icon-only .sidebar-edge-toggle {
        background: #1e293b !important;
        border: 1.5px solid #3b82f6 !important;
        color: #60a5fa !important;
    }

    .dark-theme .sidebar .nav:not(.sub-menu) > .nav-item > .nav-link {
        color: #94a3b8 !important;
    }

    .dark-theme .sidebar .nav .nav-item .nav-link i.menu-icon {
        color: #64748b !important;
    }

    .dark-theme .sidebar .nav:not(.sub-menu) > .nav-item:not(.active):hover > .nav-link {
        background: #1e293b !important;
        color: #f8fafc !important;
    }

    .dark-theme .sidebar .nav:not(.sub-menu) > .nav-item:not(.active):hover > .nav-link i.menu-icon {
        color: #60a5fa !important;
    }

    .dark-theme .sidebar .nav:not(.sub-menu) > .nav-item.active > .nav-link:not([data-bs-toggle='collapse']) {
        background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 100%) !important;
        color: #ffffff !important;
        box-shadow: 0 4px 14px rgba(37, 99, 235, 0.35) !important;
    }

    .dark-theme .sidebar .nav:not(.sub-menu) > .nav-item.active > .nav-link:not([data-bs-toggle='collapse']) i.menu-icon {
        color: #ffffff !important;
    }

    .dark-theme .sidebar .nav:not(.sub-menu) > .nav-item.active > .nav-link[data-bs-toggle='collapse'],
    .dark-theme .sidebar .nav:not(.sub-menu) > .nav-item > .nav-link[data-bs-toggle='collapse'][aria-expanded='true'] {
        background: rgba(37, 99, 235, 0.15) !important;
        color: #60a5fa !important;
    }

    .dark-theme .sidebar .nav.sub-menu {
        border-left: 2px solid #334155 !important;
    }

    .dark-theme .sidebar .nav.sub-menu .nav-item .nav-link {
        color: #94a3b8 !important;
    }

    .dark-theme .sidebar .nav.sub-menu .nav-item .nav-link:hover {
        background: #1e293b !important;
        color: #f8fafc !important;
    }

    .dark-theme .sidebar .nav.sub-menu .nav-item .nav-link.active,
    .dark-theme .sidebar .nav.sub-menu .nav-item.active > .nav-link {
        background: rgba(37, 99, 235, 0.2) !important;
        color: #60a5fa !important;
        box-shadow: inset 3px 0 0 #3b82f6 !important;
    }

    .dark-theme.sidebar-icon-only .sub-menu,
    .dark-theme .sidebar-icon-only .sub-menu {
        background: #0f172a !important;
        border-color: #334155 !important;
        box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.5) !important;
    }

    .dark-theme.sidebar-icon-only .sub-menu .nav-link,
    .dark-theme .sidebar-icon-only .sub-menu .nav-link {
        color: #cbd5e1 !important;
    }

    .dark-theme.sidebar-icon-only .sub-menu .nav-link:hover,
    .dark-theme .sidebar-icon-only .sub-menu .nav-link:hover {
        background: #1e293b !important;
        color: #f8fafc !important;
    }

    .dark-theme .content-wrapper,
    [data-theme="dark"] .content-wrapper {
        background: #0b1120 !important;
    }
</style>

<nav class="sidebar sidebar-offcanvas" id="sidebar">
    <!-- Top Header Bar attached cleanly to sidebar -->
    <div class="sidebar-header-bar">
        <span class="sidebar-header-title">Navigation</span>
        <button type="button" class="sidebar-toggle-btn" id="sidebarCollapseToggle" title="Toggle Sidebar" aria-label="Toggle Sidebar">
            <!-- Collapse icon (pointing left <<) -->
            <svg class="sidebar-toggle-svg svg-collapse" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="11 17 6 12 11 7"></polyline>
                <polyline points="18 17 13 12 18 7"></polyline>
            </svg>
            <!-- Expand icon (pointing right >>) -->
            <svg class="sidebar-toggle-svg svg-expand" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="13 17 18 12 13 7"></polyline>
                <polyline points="6 17 11 12 6 7"></polyline>
            </svg>
            <!-- Mobile close icon (X) -->
            <svg class="sidebar-toggle-svg svg-close" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="18" y1="6" x2="6" y2="18"></line>
                <line x1="6" y1="6" x2="18" y2="18"></line>
            </svg>
        </button>
    </div>

    <!-- Edge Expand Pill (desktop icon-only mode) -->
    <button type="button" class="sidebar-edge-toggle" id="sidebarEdgeToggle" title="Expand Sidebar" aria-label="Expand Sidebar">
        <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="9 18 15 12 9 6"></polyline>
        </svg>
    </button>

    <ul class="nav">
        <?php
        // ─────────────────────────────────────────────────────────────────────
        // OPTIMIZED: Fetch only the 3 columns actually used (was SELECT *)
        // ─────────────────────────────────────────────────────────────────────
        $query = mysqli_query($con, "SELECT userId, designation, type FROM signup WHERE userId = '{$_SESSION['userId']}'");
        $result = mysqli_fetch_assoc($query);

        $designation = $result['designation'];
        $userType = $result['type']; // Get user type (Online/Offline)
        
        // ─────────────────────────────────────────────────────────────────────
        // Helper: generate a collapsible sidebar menu
        // ─────────────────────────────────────────────────────────────────────
        function collapseMenu($id, $title, $icon, $items)
        {
            $currentPage = basename($_SERVER['PHP_SELF']);
            $isAnyChildActive = false;
            foreach ($items as $item) {
                $itemFile = explode('?', $item['link'])[0];
                if ($currentPage === $itemFile) {
                    $isAnyChildActive = true;
                    break;
                }
            }

            $parentActive = $isAnyChildActive ? ' active' : '';
            $expanded = $isAnyChildActive ? 'true' : 'false';
            $collapseClass = $isAnyChildActive ? 'collapse show' : 'collapse';

            echo '<li class="nav-item' . $parentActive . '">';
            echo '<a class="nav-link' . $parentActive . '" data-bs-toggle="collapse" href="#' . $id . '" aria-expanded="' . $expanded . '">';
            echo '<i class="' . $icon . ' menu-icon"></i>';
            echo '<span class="menu-title">' . $title . '</span>';
            echo '<i class="fa-solid fa-chevron-right menu-arrow collapse-arrow"></i></a>';
            echo '<div class="' . $collapseClass . '" id="' . $id . '">';
            echo '<ul class="nav flex-column sub-menu">';
            foreach ($items as $item) {
                $itemFile = explode('?', $item['link'])[0];
                $active = ($currentPage === $itemFile) ? 'active' : '';
                echo '<li class="nav-item">
                        <a class="nav-link ' . $active . '" href="' . $item['link'] . '">' . $item['name'] . '</a>
                      </li>';
            }
            echo '</ul></div></li>';
        }

        // ═════════════════════════════════════════════════════════════════════
        // ADMIN
        // ═════════════════════════════════════════════════════════════════════
        if ($designation == "admin") {

            if ($result['userId'] === '62af510636e89') {
                // ── Special MD-style admin ──
                echo '<li class="nav-item"><a class="nav-link" href="mdDashboard.php"><i class="fa-solid fa-table-columns menu-icon"></i><span class="menu-title">Dashboard</span></a></li>';
                echo '<li class="nav-item"><a class="nav-link" href="Monthly_Attendance_Report.php"><i class="fa-regular fa-calendar-alt menu-icon"></i><span class="menu-title">Monthly Attendance Report</span></a></li>';
                echo '<li class="nav-item"><a class="nav-link" href="Employee_Attendance_Report.php"><i class="fa-solid fa-user-check menu-icon"></i><span class="menu-title">Employee Attendance Report</span></a></li>';
                echo '<li class="nav-item"><a class="nav-link" href="dailyTaskReport.php"><i class="fa-solid fa-list-check menu-icon"></i><span class="menu-title">Daily Task Report</span></a></li>';
                echo '<li class="nav-item"><a class="nav-link" href="projectUtilizationReport.php"><i class="fa-solid fa-chart-bar menu-icon"></i><span class="menu-title">Project Wise Employee Utilization</span></a></li>';
            } else {
                // ── Standard admin ──
                echo '<li class="nav-item"><a class="nav-link" href="adminDashboard.php"><i class="fa-solid fa-table-columns menu-icon"></i><span class="menu-title">Dashboard</span></a></li>';
                echo '<li class="nav-item"><a class="nav-link" href="user_register.php"><i class="fa-solid fa-user menu-icon"></i><span class="menu-title">User Approval</span></a></li>';
                collapseMenu('admin-task-alloc', 'Task Allocation', 'fa-solid fa-user-pen', [
                    ['name' => 'My Allocated Tasks', 'link' => 'my_allocated_tasks.php'],
                    ['name' => 'Allocate Task', 'link' => 'task_allocation.php']
                ]);

                collapseMenu('intern-report', 'Intern Report Regularization', 'fa-regular fa-clipboard', [
                    ['name' => 'Attendance Report', 'link' => 'attendance_report.php'],
                    ['name' => 'Tour Attendance Report', 'link' => 'tourAttendance_report.php']
                ]);

                collapseMenu('approval-report', 'Approval Regularization', 'fa-solid fa-clipboard-check', [
                    ['name' => 'Tour Approval', 'link' => 'tour_update.php'],
                    ['name' => 'Leave Approval', 'link' => 'leave_approval.php'],
                    ['name' => 'Leave Quota Management', 'link' => 'hr_leave_management.php'],
                    ['name' => 'Attendance Regularization<br> Approval', 'link' => 'Attendance_Regularization_report.php'],
                    ['name' => 'WFH Approval', 'link' => 'Approval-wfh.php']
                ]);

                collapseMenu('task-reports', 'Task Reports', 'fa-solid fa-list-check', [
                    ['name' => 'Daily Task Report', 'link' => 'employeeTaskReportDaily.php'],
                    ['name' => 'Monthly Task Report', 'link' => 'employeeTaskReportMonthly.php'],
                    ['name' => 'Task Diary Detailed Report', 'link' => 'taskDiaryDetailedReport.php']
                ]);

                collapseMenu('attendance', 'Attendance Reports', 'fa-regular fa-bar-chart', [
                    ['name' => 'Monthly Attendance <br> Report', 'link' => 'Monthly_Attendance_Report.php'],
                    ['name' => 'Employee Attendance <br> Report', 'link' => 'Employee_Attendance_Report.php'],
                    ['name' => 'FIP Attendance <br> Sheet', 'link' => 'fip_attendance_report.php']
                ]);

                collapseMenu('holidays', 'Holidays Regularization', 'fa-regular fa-calendar-days', [
                    ['name' => 'Add Holidays', 'link' => 'add_holidays.php'],
                    ['name' => 'View Holidays', 'link' => 'view_holidays.php']
                ]);

                collapseMenu('admin-late-regularization', 'Late Mark & Early Go Regularization', 'fa-regular fa-clock', [
                    ['name' => 'Late Mark & Leave Report', 'link' => 'overall_report.php'],
                    ['name' => 'Late Punch_In Report', 'link' => 'Late_PunchIn_report.php'],
                    ['name' => 'Early_Go Report', 'link' => 'Early_Go Report.php']
                ]);

                collapseMenu('admin-onboarding-process', 'Onboarding Process', 'fa-solid fa-sitemap', [
                    ['name' => 'Process Dashboard', 'link' => 'onboarding_process_dashboard.php'],
                    ['name' => 'Interaction Entry', 'link' => 'interaction_entry.php'],
                    ['name' => 'Technical Evaluation (Evaluator)', 'link' => 'technical_evaluation.php'],
                    ['name' => 'Technical Evaluation (HR)', 'link' => 'technicalEvaluationHr.php'],
                    ['name' => 'Candidate Onboarding', 'link' => 'onBoarding.php'],
                    ['name' => 'Onboarding History', 'link' => 'onboarding_history.php'],
                    ['name' => 'Active Interns', 'link' => 'active-interns.php'],
                    ['name' => 'Inactive Interns', 'link' => 'deactivated-interns.php']
                ]);

                // ── Weekly Plan Overview (admin can view but NOT assign tasks directly) ──
                echo '<li class="nav-item"><a class="nav-link" href="weeklyPlanOverview.php"><i class="fa-solid fa-calendar-week menu-icon"></i><span class="menu-title">Weekly Plan Overview</span></a></li>';

                echo '<li class="nav-item"><a class="nav-link" href="intern_kra_list.php"><i class="fa-solid fa-graduation-cap menu-icon"></i><span class="menu-title">Intern KRA List</span></a></li>';
                echo '<li class="nav-item"><a class="nav-link" href="internship-extension.php"><i class="fa-solid fa-calendar-plus menu-icon"></i><span class="menu-title">Internship Extension</span></a></li>';
                echo '<li class="nav-item"><a class="nav-link" href="Client_Call_Notification.php"><i class="fa-solid fa-bell menu-icon"></i><span class="menu-title">Notify for Client Call</span></a></li>';
                echo '<li class="nav-item"><a class="nav-link" href="editProfile.php"><i class="fa-regular fa-user menu-icon"></i><span class="menu-title">Edit Profile</span></a></li>';
                echo '<li class="nav-item"><a class="nav-link" href="feedback.php"><i class="fa-solid fa-comment-dots menu-icon"></i><span class="menu-title">Feedback / Issue</span></a></li>';
                echo '<li class="nav-item"><a class="nav-link" href="jrModeSwitch.php"><i class="fa-solid fa-repeat menu-icon"></i><span class="menu-title">Junior Mode Switch</span></a></li>';

                // ── Advertisement & Board (admin) ──
                collapseMenu('admin-board-module', 'Advertisements &amp; Board', 'fa-solid fa-rectangle-ad', [
                    ['name' => 'Manage Announcements', 'link' => 'add_announcement.php'],
                    ['name' => 'Manage Advertisements', 'link' => 'add_advertisement.php'],
                    ['name' => 'Placed Students', 'link' => 'add_placed_student.php']
                ]);
            }

            // ═════════════════════════════════════════════════════════════════════
            // DEVELOPER
            // ═════════════════════════════════════════════════════════════════════
        } else if ($designation == "developer") {
            echo '<li class="nav-item"><a class="nav-link" href="DeveloperDashboard.php"><i class="fa-solid fa-table-columns menu-icon"></i><span class="menu-title">Dashboard</span></a></li>';
            collapseMenu('dev-task-alloc', 'Task Allocation', 'fa-solid fa-user-pen', [
                ['name' => 'My Allocated Tasks', 'link' => 'my_allocated_tasks.php'],
                ['name' => 'Allocate Task', 'link' => 'task_allocation.php']
            ]);
            echo '<li class="nav-item"><a class="nav-link" href="developer_feedback.php"><i class="fa-solid fa-comment-dots menu-icon"></i><span class="menu-title">Feedback / Issue</span></a></li>';

            // ═════════════════════════════════════════════════════════════════════
            // HR  (combination of admin + senior features)
            // ═════════════════════════════════════════════════════════════════════
        } else if ($designation == "hr") {

            // ── Dashboard ──
            echo '<li class="nav-item">
        <a class="nav-link" href="hrDashboard.php">
            <i class="fa-solid fa-table-columns menu-icon"></i>
            <span class="menu-title">Dashboard</span>
        </a>
    </li>';
            echo '<li class="nav-item"><a class="nav-link" href="user_register.php"><i class="fa-solid fa-user menu-icon"></i><span class="menu-title">User Approval</span></a></li>';
            // ── Approvals (ALL IN ONE) ──
            collapseMenu('hr-approvals', 'Approvals', 'fa-solid fa-clipboard-check', [
                ['name' => 'Leave Approval', 'link' => 'leave_approval.php'],
                ['name' => 'Tour Approval', 'link' => 'tour_update.php'],
                ['name' => 'WFH Approval', 'link' => 'Approval-wfh.php'],
                ['name' => 'Attendance Regularization', 'link' => 'Attendance_Regularization_report.php']
            ]);

            // ── Task & Planning ──
            collapseMenu('hr-task-alloc', 'Task Allocation', 'fa-solid fa-user-pen', [
                ['name' => 'My Allocated Tasks', 'link' => 'my_allocated_tasks.php'],
                ['name' => 'Allocate Task', 'link' => 'task_allocation.php']
            ]);
            collapseMenu('hr-planning', 'Planning & Tasks', 'fa-solid fa-calendar-week', [
                ['name' => 'Weekly Plan Overview', 'link' => 'weeklyPlanOverview.php'],
                ['name' => 'Task Diary', 'link' => 'taskDiary.php'],
                ['name' => 'Team Diary Overview', 'link' => 'taskDiaryAnalytics.php?view=admin'],
                ['name' => 'MD Task Diary Report', 'link' => 'mdTaskDiary.php'],
                ['name' => 'Task Diary Detailed Report', 'link' => 'taskDiaryDetailedReport.php']
            ]);

            collapseMenu('hr-meetings', 'Meetings', 'fa-solid fa-video', [
                ['name' => 'Create Meeting', 'link' => 'createMeeting.php'],
                ['name' => 'Meeting History', 'link' => 'meetingHistory.php'],
                ['name' => 'Meeting Analytics', 'link' => 'meetingDashboard.php'],
                ['name' => 'Recurring Report', 'link' => 'meetingComparisonReport.php']
            ]);

            // ── Reports (ALL IN ONE) ──
            collapseMenu('hr-reports', 'Reports', 'fa-solid fa-chart-column', [
                ['name' => 'KRA Analytics', 'link' => 'kraDashboard.php'],
                ['name' => 'KRA Submissions', 'link' => 'intern_kra_list.php'],
                ['name' => 'Attendance Report', 'link' => 'attendance_report.php'],
                ['name' => 'Tour Attendance Report', 'link' => 'tourAttendance_report.php'],
                ['name' => 'Daily Task Report', 'link' => 'employeeTaskReportDaily.php'],
                ['name' => 'Monthly Task Report', 'link' => 'employeeTaskReportMonthly.php'],
                ['name' => 'Late Mark Report', 'link' => 'overall_report.php'],
                ['name' => 'Intern Early Go', 'link' => 'hr_early_go.php'],
                ['name' => 'Day Wise Attendance <br> Report', 'link' => 'fip_attendance_report.php'],
                ['name' => 'Employee Attendance <br> Report', 'link' => 'Employee_Attendance_Report.php']
            ]);

            collapseMenu('senior-leave', 'Leave', 'fa-regular fa-calendar-days', [
                ['name' => 'Apply Leave', 'link' => 'apply_leave.php'],
                ['name' => 'My Leaves', 'link' => 'cancel_leaves.php']
            ]);

            collapseMenu('hr-wfh', 'WFH', 'fa-solid fa-house-laptop', [
                ['name' => 'Apply WFH', 'link' => 'apply-wfh.php'],
                ['name' => 'My WFH', 'link' => 'my-wfh.php']
            ]);


            // ── Holidays ──
            collapseMenu('hr-holidays', 'Holidays', 'fa-regular fa-calendar-days', [
                ['name' => 'Add Holidays', 'link' => 'add_holidays.php'],
                ['name' => 'View Holidays', 'link' => 'view_holidays.php']
            ]);

            collapseMenu('hr-onboarding-process', 'Onboarding Process', 'fa-solid fa-sitemap', [
                ['name' => 'Process Dashboard', 'link' => 'onboarding_process_dashboard.php'],
                ['name' => 'Interaction Entry', 'link' => 'interaction_entry.php'],
                ['name' => 'Technical Evaluation (HR)', 'link' => 'technicalEvaluationHr.php'],
                ['name' => 'Candidate Onboarding', 'link' => 'onBoarding.php'],
                ['name' => 'Onboarding History', 'link' => 'onboarding_history.php'],
                ['name' => 'Active Interns Roster', 'link' => 'active-interns.php'],
                ['name' => 'Inactive Interns', 'link' => 'deactivated-interns.php']
            ]);

            echo '<li class="nav-item"><a class="nav-link" href="Attendance_Regularization.php"><i class="fa-solid fa-list-check menu-icon"></i><span class="menu-title">Attendance Regularization</span></a></li>';
            echo '<li class="nav-item"><a class="nav-link" href="batch_master.php"><i class="fa-solid fa-layer-group menu-icon"></i><span class="menu-title">Batch Master</span></a></li>';
            echo '<li class="nav-item"><a class="nav-link" href="intern_kra_list.php"><i class="fa-solid fa-graduation-cap menu-icon"></i><span class="menu-title">Intern KRA List</span></a></li>';
            echo '<li class="nav-item"><a class="nav-link" href="internship-extension.php"><i class="fa-solid fa-calendar-plus menu-icon"></i><span class="menu-title">Internship Extension</span></a></li>';
            echo '<li class="nav-item"><a class="nav-link" href="myAttendance.php"><i class="fa-solid fa-user-check menu-icon"></i><span class="menu-title">My Attendance</span></a></li>';
            // ── Other ──
            echo '<li class="nav-item">
        <a class="nav-link" href="Client_Call_Notification.php">
            <i class="fa-solid fa-bell menu-icon"></i>
            <span class="menu-title">Client Call Notification</span>
        </a>
    </li>';

            echo '<li class="nav-item">
        <a class="nav-link" href="editProfile.php">
            <i class="fa-regular fa-user menu-icon"></i>
            <span class="menu-title">Edit Profile</span>
        </a>
    </li>';

            echo '<li class="nav-item">
        <a class="nav-link" href="feedback.php">
            <i class="fa-solid fa-comment-dots menu-icon"></i>
            <span class="menu-title">Feedback / Issue</span>
        </a>
    </li>';

            // ── Advertisement & Board ──
            collapseMenu('hr-board-module', 'Advertisements &amp; Board', 'fa-solid fa-rectangle-ad', [
                ['name' => 'Manage Announcements', 'link' => 'add_announcement.php'],
                ['name' => 'Manage Advertisements', 'link' => 'add_advertisement.php'],
                ['name' => 'Placed Students', 'link' => 'add_placed_student.php']
            ]);

            echo '<li class="nav-item"><a class="nav-link" href="jrModeSwitch.php"><i class="fa-solid fa-repeat menu-icon"></i><span class="menu-title">Junior Mode Switch</span></a></li>';

            // ═════════════════════════════════════════════════════════════════════
            // DEPARTMENT HEAD
            // ═════════════════════════════════════════════════════════════════════
        } else if ($designation == "departmentHead") {
            echo '<li class="nav-item"><a class="nav-link" href="srEmpDashboard.php"><i class="fa-solid fa-table-columns menu-icon"></i><span class="menu-title">Dashboard</span></a></li>';

            collapseMenu('dh-team-reports', 'Team Reports', 'fa-solid fa-users', [
                ['name' => 'Team Task Report', 'link' => 'employeeTaskReportDaily.php'],
                ['name' => 'Team Attendance', 'link' => 'Monthly_Attendance_Report.php'],
                ['name' => 'Team Performance', 'link' => 'projectUtilizationReport.php']
            ]);

            collapseMenu('dh-approvals', 'Approvals', 'fa-solid fa-clipboard-check', [
                ['name' => 'Leave Approval', 'link' => 'leave_approval.php'],
                ['name' => 'Tour Approval', 'link' => 'tour_update.php'],
                ['name' => 'WFH Approval', 'link' => 'Approval-wfh.php'],
                ['name' => 'Attendance Regularization', 'link' => 'Attendance_Regularization_report.php']
            ]);

            echo '<li class="nav-item"><a class="nav-link" href="taskEntry.php"><i class="fa-solid fa-list-check menu-icon"></i><span class="menu-title">My Tasks</span></a></li>';
            collapseMenu('dh-task-alloc', 'Task Allocation', 'fa-solid fa-user-pen', [
                ['name' => 'My Allocated Tasks', 'link' => 'my_allocated_tasks.php'],
                ['name' => 'Allocate Task', 'link' => 'task_allocation.php']
            ]);

            collapseMenu('dh-leave', 'Leave', 'fa-regular fa-calendar-days', [
                ['name' => 'Apply Leave', 'link' => 'apply_leave.php'],
                ['name' => 'My Leaves', 'link' => 'cancel_leaves.php']
            ]);

            echo '<li class="nav-item"><a class="nav-link" href="myAttendance.php"><i class="fa-solid fa-user-check menu-icon"></i><span class="menu-title">My Attendance</span></a></li>';
            echo '<li class="nav-item"><a class="nav-link" href="editProfile.php"><i class="fa-regular fa-user menu-icon"></i><span class="menu-title">Edit Profile</span></a></li>';
            echo '<li class="nav-item"><a class="nav-link" href="feedback.php"><i class="fa-solid fa-comment-dots menu-icon"></i><span class="menu-title">Feedback / Issue</span></a></li>';

            // ═════════════════════════════════════════════════════════════════════
            // MANAGER
            // ═════════════════════════════════════════════════════════════════════
        } else if ($designation == "manager") {
            echo '<li class="nav-item"><a class="nav-link" href="srEmpDashboard.php"><i class="fa-solid fa-table-columns menu-icon"></i><span class="menu-title">Dashboard</span></a></li>';

            collapseMenu('mgr-projects', 'Project Management', 'fa-solid fa-diagram-project', [
                ['name' => 'Project Master', 'link' => 'projectMaster.php'],
                ['name' => 'Project Progress', 'link' => 'projectProgress.php']
            ]);

            collapseMenu('mgr-reports', 'Reports', 'fa-solid fa-chart-line', [
                ['name' => 'Task Reports', 'link' => 'employeeTaskReportDaily.php'],
                ['name' => 'Attendance Reports', 'link' => 'Employee_Attendance_Report.php'],
                ['name' => 'Project Utilization', 'link' => 'projectUtilizationReport.php']
            ]);

            collapseMenu('mgr-approvals', 'Approvals', 'fa-solid fa-clipboard-check', [
                ['name' => 'Leave Approval', 'link' => 'leave_approval.php'],
                ['name' => 'WFH Approval', 'link' => 'Approval-wfh.php']
            ]);

            echo '<li class="nav-item"><a class="nav-link" href="taskEntry.php"><i class="fa-solid fa-list-check menu-icon"></i><span class="menu-title">My Tasks</span></a></li>';
            collapseMenu('mgr-task-alloc', 'Task Allocation', 'fa-solid fa-user-pen', [
                ['name' => 'My Allocated Tasks', 'link' => 'my_allocated_tasks.php'],
                ['name' => 'Allocate Task', 'link' => 'task_allocation.php']
            ]);

            collapseMenu('mgr-leave', 'Leave', 'fa-regular fa-calendar-days', [
                ['name' => 'Apply Leave', 'link' => 'apply_leave.php'],
                ['name' => 'My Leaves', 'link' => 'cancel_leaves.php']
            ]);

            echo '<li class="nav-item"><a class="nav-link" href="myAttendance.php"><i class="fa-solid fa-user-check menu-icon"></i><span class="menu-title">My Attendance</span></a></li>';
            echo '<li class="nav-item"><a class="nav-link" href="editProfile.php"><i class="fa-regular fa-user menu-icon"></i><span class="menu-title">Edit Profile</span></a></li>';
            echo '<li class="nav-item"><a class="nav-link" href="feedback.php"><i class="fa-solid fa-comment-dots menu-icon"></i><span class="menu-title">Feedback / Issue</span></a></li>';

            // ═════════════════════════════════════════════════════════════════════
            // RESOURCE ALLOCATOR / RESOURCE HEAD
            // ═════════════════════════════════════════════════════════════════════
        } else if (in_array(strtolower(str_replace([' ', '_'], '', $designation)), ['resourceallocator', 'resourcehead', 'resourcemanager'])) {

            // ── Dashboard ──
            echo '<li class="nav-item"><a class="nav-link" href="resourceHeadDashboard.php">
        <i class="fa-solid fa-table-columns menu-icon"></i>
        <span class="menu-title">Dashboard</span>
    </a></li>';

            collapseMenu('ra-task-diary', 'Task Diary', 'fa-solid fa-book-open', [
                ['name' => 'Task Diary Log', 'link' => 'taskDiary.php'],
                ['name' => 'Diary Analytics', 'link' => 'taskDiaryAnalytics.php']
            ]);

            // ── TASKS & DAILY WORK (Senior Features) ──
            echo '<li class="nav-item"><a class="nav-link" href="taskEntry.php">
        <i class="fa-solid fa-list-check menu-icon"></i>
        <span class="menu-title">My Tasks</span>
    </a></li>';

            collapseMenu('ra-task-alloc', 'Task Allocation', 'fa-solid fa-user-pen', [
                ['name' => 'My Allocated Tasks', 'link' => 'my_allocated_tasks.php'],
                ['name' => 'Allocate Task', 'link' => 'task_allocation.php']
            ]);

            echo '<li class="nav-item"><a class="nav-link" href="Attendance_Regularization.php">
        <i class="fa-solid fa-user-check menu-icon"></i>
        <span class="menu-title">Attendance Regularization</span>
    </a></li>';

            echo '<li class="nav-item"><a class="nav-link" href="Attendance_Regularization_report.php"><i class="fa-solid fa-calendar-check menu-icon"></i><span class="menu-title">Attendance Regularization Approval</span></a></li>';

            collapseMenu('dh-meetings', 'Meetings', 'fa-solid fa-video', [
                ['name' => 'Create Meeting', 'link' => 'createMeeting.php'],
                ['name' => 'Meeting History', 'link' => 'meetingHistory.php'],
                ['name' => 'Meeting Analytics', 'link' => 'meetingDashboard.php'],
                ['name' => 'Recurring Report', 'link' => 'meetingComparisonReport.php']
            ]);

            echo '<li class="nav-item"><a class="nav-link" href="weeklyPlanOverview.php">
        <i class="fa-solid fa-calendar-week menu-icon"></i>
        <span class="menu-title">Weekly Plan Overview</span>
    </a></li>';



            // ── RESOURCE MANAGEMENT (ROLE-SPECIFIC 🔥) ──
            collapseMenu('ra-management', 'Resource Management', 'fa-solid fa-users-gear', [
                ['name' => 'Resource Approvals', 'link' => 'resourceApprovals.php'],
                ['name' => 'Resource Request', 'link' => 'resourceRequest.php'],
                ['name' => 'Free Intern Pool', 'link' => 'freeInternPool.php'],
                ['name' => 'Project Master', 'link' => 'projectMaster.php'],
                ['name' => 'Internship Extension', 'link' => 'internship-extension.php']
            ]);

            // ── REPORTS ──
            collapseMenu('ra-reports', 'Reports', 'fa-solid fa-chart-column', [
                ['name' => 'Project Utilization Report', 'link' => 'projectUtilizationReport.php'],
                ['name' => 'Allocation Variance Report', 'link' => 'allocationVarianceReport.php'],
                ['name' => 'Project Cost & Consumption', 'link' => 'projectCostEfficiencyReport.php'],
                ['name' => 'Unplanned Switching Index', 'link' => 'projectSwitchingReport.php'],
                ['name' => 'Forward Capacity Forecast', 'link' => 'forwardCapacityReport.php'],
                ['name' => 'Team Lead Demand Audit', 'link' => 'teamLeadRequestAuditReport.php'],
                ['name' => 'Task Report', 'link' => 'employeeTaskReportDaily.php'],
                ['name' => 'Attendance Report', 'link' => 'Employee_Attendance_Report.php'],
                ['name' => 'Intern Early Go', 'link' => 'hr_early_go.php']
            ]);

            collapseMenu('ra-intern-kra', 'Intern KRA', 'fa-solid fa-graduation-cap', [
                ['name' => 'Intern KRA List', 'link' => 'intern_kra_list.php'],
                ['name' => 'Intern KRA Form', 'link' => 'intern_kra_form.php']
            ]);
            // ── LEAVE & WFH (Only WFH if type is Offline) ──
            if ($userType == "Offline") {
                collapseMenu('ra-leave', 'Leave & WFH', 'fa-regular fa-calendar-days', [
                    ['name' => 'Apply Leave', 'link' => 'apply_leave.php'],
                    ['name' => 'My Leaves', 'link' => 'cancel_leaves.php'],
                    ['name' => 'Leave Approval', 'link' => 'leave_approval.php'],
                    ['name' => 'Apply WFH', 'link' => 'apply-wfh.php'],
                    ['name' => 'My WFH', 'link' => 'my-wfh.php'],
                    ['name' => 'WFH Approval', 'link' => 'Approval-wfh.php']
                ]);
            } else {
                collapseMenu('ra-leave', 'Leave', 'fa-regular fa-calendar-days', [
                    ['name' => 'Apply Leave', 'link' => 'apply_leave.php'],
                    ['name' => 'My Leaves', 'link' => 'cancel_leaves.php']
                ]);
            }

            // ── TOUR ──
            echo '<li class="nav-item"><a class="nav-link" href="apply_tour.php">
        <i class="fa-solid fa-map-location-dot menu-icon"></i>
        <span class="menu-title">Apply Tour</span>
    </a></li>';

            echo '<li class="nav-item"><a class="nav-link" href="display_tour.php">
        <i class="fa-regular fa-file-lines menu-icon"></i>
        <span class="menu-title">Tour Applications</span>
    </a></li>';

            // ── EVALUATION ──
            echo '<li class="nav-item">
    <a class="nav-link" href="technical_evaluation.php">
        <i class="fa-solid fa-star-half-stroke menu-icon"></i>
        <span class="menu-title">Technical Evaluation</span>
    </a>
</li>';

            // ── PERSONAL ──
            echo '<li class="nav-item"><a class="nav-link" href="myAttendance.php">
        <i class="fa-solid fa-user-check menu-icon"></i>
        <span class="menu-title">My Attendance</span>
    </a></li>';

            echo '<li class="nav-item"><a class="nav-link" href="editProfile.php">
        <i class="fa-regular fa-user menu-icon"></i>
        <span class="menu-title">Edit Profile</span>
    </a></li>';

            echo '<li class="nav-item"><a class="nav-link" href="feedback.php">
        <i class="fa-solid fa-comment-dots menu-icon"></i>
        <span class="menu-title">Feedback / Issue</span>
    </a></li>';

            echo '<li class="nav-item"><a class="nav-link" href="jrModeSwitch.php"><i class="fa-solid fa-repeat menu-icon"></i><span class="menu-title">Junior Mode Switch</span></a></li>';

            // ═════════════════════════════════════════════════════════════════════
            // MD
            // ═════════════════════════════════════════════════════════════════════
        } else if ($designation == "md") {
            echo '<li class="nav-item"><a class="nav-link" href="mdDashboard.php"><i class="fa-solid fa-table-columns menu-icon"></i><span class="menu-title">Dashboard</span></a></li>';
            collapseMenu('md-task-diary', 'Task Diary', 'fa-solid fa-book-open', [
                ['name' => 'TL Task Diary', 'link' => 'mdTaskDiary.php'],
                ['name' => 'Team Diary Overview', 'link' => 'taskDiaryAnalytics.php?view=admin'],
                ['name' => 'Task Diary Detailed Report', 'link' => 'taskDiaryDetailedReport.php']
            ]);
            collapseMenu('md-task-alloc', 'Task Allocation', 'fa-solid fa-user-pen', [
                ['name' => 'My Allocated Tasks', 'link' => 'my_allocated_tasks.php'],
                ['name' => 'Allocate Task', 'link' => 'task_allocation.php']
            ]);

            collapseMenu('md-analytics', 'Analytics & Reports', 'fa-solid fa-chart-pie', [
                ['name' => 'Monthly Attendance', 'link' => 'Monthly_Attendance_Report.php'],
                ['name' => 'Employee Attendance', 'link' => 'Employee_Attendance_Report.php'],
                ['name' => 'Task Report', 'link' => 'dailyTaskReport.php'],
                ['name' => 'Task Diary Detailed Report', 'link' => 'taskDiaryDetailedReport.php']
            ]);

            collapseMenu('md-team-overview', 'Team Overview', 'fa-solid fa-users-viewfinder', [
                ['name' => 'Active Interns', 'link' => 'active-interns.php'],
                ['name' => 'Inactive Interns', 'link' => 'deactivated-interns.php'],
                ['name' => 'Overall Report', 'link' => 'overall_report.php']
            ]);

            echo '<li class="nav-item"><a class="nav-link" href="editProfile.php"><i class="fa-regular fa-user menu-icon"></i><span class="menu-title">Edit Profile</span></a></li>';
            echo '<li class="nav-item"><a class="nav-link" href="feedback.php"><i class="fa-solid fa-comment-dots menu-icon"></i><span class="menu-title">Feedback / Issue</span></a></li>';

            // ═════════════════════════════════════════════════════════════════════
            // TEAM LEAD
            // ═════════════════════════════════════════════════════════════════════
        } else if ($designation == "teamLead") {
            echo '<li class="nav-item"><a class="nav-link" href="srEmpDashboard.php"><i class="fa-solid fa-table-columns menu-icon"></i><span class="menu-title">Dashboard</span></a></li>';
            echo '<li class="nav-item"><a class="nav-link" href="taskEntry.php"><i class="fa-solid fa-list-check menu-icon"></i><span class="menu-title">Tasks</span></a></li>';
            collapseMenu('tl-task-alloc', 'Task Allocation', 'fa-solid fa-user-pen', [
                ['name' => 'My Allocated Tasks', 'link' => 'my_allocated_tasks.php'],
                ['name' => 'Allocate Task', 'link' => 'task_allocation.php']
            ]);
            echo '<li class="nav-item"><a class="nav-link" href="Attendance_Regularization.php"><i class="fa-solid fa-list-check menu-icon"></i><span class="menu-title">Attendance Regularization</span></a></li>';

            echo '<li class="nav-item"><a class="nav-link" href="weeklyPlanOverview.php"><i class="fa-solid fa-calendar-week menu-icon"></i><span class="menu-title">Weekly Plan Overview</span></a></li>';

            collapseMenu('tl-meetings', 'Meetings', 'fa-solid fa-video', [
                ['name' => 'Create Meeting', 'link' => 'createMeeting.php'],
                ['name' => 'Meeting History', 'link' => 'meetingHistory.php'],
                ['name' => 'Meeting Analytics', 'link' => 'meetingDashboard.php'],
                ['name' => 'Recurring Report', 'link' => 'meetingComparisonReport.php']
            ]);

            collapseMenu('tl-team-management', 'Team Management', 'fa-solid fa-users-cog', [
                ['name' => 'Technical Evaluation', 'link' => 'technical_evaluation.php']
            ]);

            collapseMenu('tl-team-reports', 'Team Reports', 'fa-solid fa-chart-column', [
                ['name' => 'Task Report', 'link' => 'jrTaskReport.php'],
                ['name' => 'Attendance Reports', 'link' => 'jrAttendanceReport.php']
            ]);

            collapseMenu('tl-approvals', 'Approvals', 'fa-solid fa-clipboard-check', [
                ['name' => 'Leave Approval', 'link' => 'leave_approval.php'],
                ['name' => 'WFH Approval', 'link' => 'Approval-wfh.php']
            ]);

            echo '<li class="nav-item"><a class="nav-link" href="apply_tour.php"><i class="fa-solid fa-map-location-dot menu-icon"></i><span class="menu-title">Apply Tour</span></a></li>';
            echo '<li class="nav-item"><a class="nav-link" href="display_tour.php"><i class="fa-regular fa-file-lines menu-icon"></i><span class="menu-title">Tour Applications</span></a></li>';

            collapseMenu('tl-leave', 'Leave', 'fa-regular fa-calendar-days', [
                ['name' => 'Apply Leave', 'link' => 'apply_leave.php'],
                ['name' => 'My Leaves', 'link' => 'cancel_leaves.php']
            ]);

            echo '<li class="nav-item"><a class="nav-link" href="late_punch_early_go.php"><i class="fa-solid fa-user-clock menu-icon"></i><span class="menu-title">Late Punch-in Intimation</span></a></li>';

            // ── WFH (Only show if type is Offline) ──
            if ($userType == "Offline") {
                collapseMenu('tl-wfh', 'WFH', 'fa-solid fa-house-laptop', [
                    ['name' => 'Apply WFH', 'link' => 'apply-wfh.php'],
                    ['name' => 'My WFH', 'link' => 'my-wfh.php']
                ]);
            }


            echo '<li class="nav-item"><a class="nav-link" href="editProfile.php"><i class="fa-regular fa-user menu-icon"></i><span class="menu-title">Edit Profile</span></a></li>';

            echo '<li class="nav-item"><a class="nav-link" href="myAttendance.php"><i class="fa-solid fa-user-check menu-icon"></i><span class="menu-title">My Attendance</span></a></li>';
            echo '<li class="nav-item"><a class="nav-link" href="projectProgress.php"><i class="fa-solid fa-chart-line menu-icon"></i><span class="menu-title">Project Progress</span></a></li>';
            echo '<li class="nav-item"><a class="nav-link" href="feedback.php"><i class="fa-solid fa-comment-dots menu-icon"></i><span class="menu-title">Feedback / Issue</span></a></li>';

            // ═════════════════════════════════════════════════════════════════════
            // SENIOR INTERN
            // ═════════════════════════════════════════════════════════════════════
        } else if ($designation == "seniorIntern") {
            echo '<li class="nav-item"><a class="nav-link" href="srEmpDashboard.php"><i class="fa-solid fa-table-columns menu-icon"></i><span class="menu-title">Dashboard</span></a></li>';
            collapseMenu('sr-task-diary', 'Task Diary', 'fa-solid fa-book-open', [
                ['name' => 'Task Diary Log', 'link' => 'taskDiary.php'],
                ['name' => 'Diary Analytics', 'link' => 'taskDiaryAnalytics.php']
            ]);
            echo '<li class="nav-item"><a class="nav-link" href="taskEntry.php"><i class="fa-solid fa-list-check menu-icon"></i><span class="menu-title">Tasks</span></a></li>';
            collapseMenu('sr-task-alloc', 'Task Allocation', 'fa-solid fa-user-pen', [
                ['name' => 'My Allocated Tasks', 'link' => 'my_allocated_tasks.php'],
                ['name' => 'Allocate Task', 'link' => 'task_allocation.php']
            ]);
            echo '<li class="nav-item"><a class="nav-link" href="Attendance_Regularization.php"><i class="fa-solid fa-list-check menu-icon"></i><span class="menu-title">Attendance Regularization</span></a></li>';

            // ── Weekly Plan Overview (view-only) ──
            echo '<li class="nav-item"><a class="nav-link" href="weeklyPlanOverview.php"><i class="fa-solid fa-calendar-week menu-icon"></i><span class="menu-title">Weekly Plan Overview</span></a></li>';

            collapseMenu('sr-meetings', 'Meetings', 'fa-solid fa-video', [
                ['name' => 'Create Meeting', 'link' => 'createMeeting.php'],
                ['name' => 'Meeting History', 'link' => 'meetingHistory.php'],
                ['name' => 'Meeting Analytics', 'link' => 'meetingDashboard.php'],
                ['name' => 'Recurring Report', 'link' => 'meetingComparisonReport.php']
            ]);

            collapseMenu('senior-creation', 'Creation', 'fa-solid fa-pen-to-square', [
                ['name' => 'Project Master', 'link' => 'projectMaster.php'],
                ['name' => "Project's FTP / <br> Git Link", 'link' => 'employeeProjects.php'],
            ]);

            collapseMenu('senior-junior-reports', 'Junior Reports', 'fa-solid fa-chart-column', [
                ['name' => 'Task Report', 'link' => 'jrTaskReport.php'],
                ['name' => 'Attendance Reports', 'link' => 'jrAttendanceReport.php']
            ]);

            collapseMenu('tour-menu', 'Tour', 'fa-solid fa-map-location-dot', [
                ['name' => 'Apply Tour', 'link' => 'apply_tour.php'],
                ['name' => 'Tour Applications', 'link' => 'display_tour.php']
            ]);

            collapseMenu('senior-leave', 'Leave', 'fa-regular fa-calendar-days', [
                ['name' => 'Apply Leave', 'link' => 'apply_leave.php'],
                ['name' => 'My Leaves', 'link' => 'cancel_leaves.php']
            ]);

            echo '<li class="nav-item"><a class="nav-link" href="late_punch_early_go.php"><i class="fa-solid fa-user-clock menu-icon"></i><span class="menu-title">Late Punch-in Intimation</span></a></li>';

            // ── WFH (Only show if type is Offline) ──
            if ($userType == "Offline") {
                collapseMenu('senior-wfh', 'WFH', 'fa-solid fa-house-laptop', [
                    ['name' => 'Apply WFH', 'link' => 'apply-wfh.php'],
                    ['name' => 'My WFH', 'link' => 'my-wfh.php']
                ]);
            }

            echo '<li class="nav-item"><a class="nav-link" href="technical_evaluation.php"><i class="fa-solid fa-star menu-icon"></i><span class="menu-title">Technical Evaluation</span></a></li>';
            echo '<li class="nav-item"><a class="nav-link" href="intern_kra_form.php"><i class="fa-solid fa-graduation-cap menu-icon"></i><span class="menu-title">Intern KRA Form</span></a></li>';
            echo '<li class="nav-item"><a class="nav-link" href="resourceRequest.php"><i class="fa-solid fa-clipboard-list menu-icon"></i><span class="menu-title">Resource Requests</span></a></li>';
            echo '<li class="nav-item"><a class="nav-link" href="editProfile.php"><i class="fa-regular fa-user menu-icon"></i><span class="menu-title">Edit Profile</span></a></li>';
            echo '<li class="nav-item"><a class="nav-link" href="myAttendance.php"><i class="fa-solid fa-user-check menu-icon"></i><span class="menu-title">My Attendance</span></a></li>';
            echo '<li class="nav-item"><a class="nav-link" href="projectProgress.php"><i class="fa-solid fa-chart-line menu-icon"></i><span class="menu-title">Project Progress</span></a></li>';
            echo '<li class="nav-item"><a class="nav-link" href="feedback.php"><i class="fa-solid fa-comment-dots menu-icon"></i><span class="menu-title">Feedback / Issue</span></a></li>';

            // ═════════════════════════════════════════════════════════════════════
            // JUNIOR INTERN
            // ═════════════════════════════════════════════════════════════════════
        } else if ($designation == "juniorIntern") {
            echo '<li class="nav-item"><a class="nav-link" href="jrEmpDashboard.php"><i class="fa-solid fa-table-columns menu-icon"></i><span class="menu-title">Dashboard</span></a></li>';
            echo '<li class="nav-item"><a class="nav-link" href="taskEntry.php"><i class="fa-solid fa-list-check menu-icon"></i><span class="menu-title">Tasks</span></a></li>';
            echo '<li class="nav-item"><a class="nav-link" href="my_allocated_tasks.php"><i class="fa-solid fa-clipboard-list menu-icon"></i><span class="menu-title">My Allocated Tasks</span></a></li>';
            echo '<li class="nav-item"><a class="nav-link" href="late_punch_early_go.php"><i class="fa-solid fa-user-clock menu-icon"></i><span class="menu-title">Late Punch-in Intimation</span></a></li>';

            collapseMenu('junior-leave', 'Leave', 'fa-regular fa-calendar-days', [
                ['name' => 'Apply Leave', 'link' => 'apply_leave.php'],
                ['name' => 'My Leaves', 'link' => 'cancel_leaves.php']
            ]);

            echo '<li class="nav-item"><a class="nav-link" href="apply_tour.php"><i class="fa-solid fa-map-location-dot menu-icon"></i><span class="menu-title">Apply Tour</span></a></li>';
            echo '<li class="nav-item"><a class="nav-link" href="display_tour.php"><i class="fa-regular fa-file-lines menu-icon"></i><span class="menu-title">Tour Applications</span></a></li>';

            // ── WFH (Only show if type is Offline) ──
            if ($userType == "Offline") {
                collapseMenu('junior-wfh', 'WFH', 'fa-solid fa-house-laptop', [
                    ['name' => 'Apply WFH', 'link' => 'apply-wfh.php'],
                    ['name' => 'My WFH', 'link' => 'my-wfh.php']
                ]);
            }

            collapseMenu('junior-meetings', 'Meetings', 'fa-solid fa-video', [
                ['name' => 'Meeting History', 'link' => 'meetingHistory.php']
            ]);
            echo '<li class="nav-item"><a class="nav-link" href="employeeProjects.php"><i class="mdi mdi-briefcase-outline menu-icon"></i><span class="menu-title">Employee Projects</span></a></li>';
            echo '<li class="nav-item"><a class="nav-link" href="Attendance_Regularization.php"><i class="fa-solid fa-list-check menu-icon"></i><span class="menu-title">Attendance <br>Regularization</span></a></li>';
            echo '<li class="nav-item"><a class="nav-link" href="editProfile.php"><i class="fa-regular fa-user menu-icon"></i><span class="menu-title">Edit Profile</span></a></li>';
            echo '<li class="nav-item"><a class="nav-link" href="myAttendance.php"><i class="fa-solid fa-user-check menu-icon"></i><span class="menu-title">My Attendance</span></a></li>';
            echo '<li class="nav-item"><a class="nav-link" href="feedback.php"><i class="fa-solid fa-comment-dots menu-icon"></i><span class="menu-title">Feedback / Issue</span></a></li>';
        } else if ($designation == "receptionist") {
            echo '<li class="nav-item"><a class="nav-link" href="receptionistDashboard.php"><i class="fa-solid fa-table-columns menu-icon"></i><span class="menu-title">Dashboard</span></a></li>';
            collapseMenu('receptionist-meetings', 'Meetings', 'fa-solid fa-video', [
                ['name' => 'Create Meeting', 'link' => 'createMeeting.php'],
                ['name' => 'Meeting History', 'link' => 'meetingHistory.php'],
                ['name' => 'Meeting Analytics', 'link' => 'meetingDashboard.php']
            ]);
            collapseMenu('receptionist-reports', 'Reports', 'fa-solid fa-chart-column', [
                ['name' => 'Monthly attendance', 'link' => 'Monthly_Attendance_Report.php'],
                ['name' => 'Attendance report', 'link' => 'attendance_report.php'],
                ['name' => 'Tour attendance report', 'link' => 'tourAttendance_report.php'],
                ['name' => 'Late mark report', 'link' => 'overall_report.php'],
                ['name' => 'Emp attendance report', 'link' => 'Employee_Attendance_Report.php']
            ]);
            collapseMenu('receptionist-leave', 'Leave', 'fa-regular fa-calendar-days', [
                ['name' => 'Apply leave', 'link' => 'apply_leave.php'],
                ['name' => 'My Leaves', 'link' => 'cancel_leaves.php']
            ]);
            echo '<li class="nav-item"><a class="nav-link" href="late_punch_early_go.php"><i class="fa-solid fa-user-clock menu-icon"></i><span class="menu-title">Late punch-in intimation</span></a></li>';
            collapseMenu('receptionist-wfh', 'WFH', 'fa-solid fa-house-laptop', [
                ['name' => 'Apply WFH', 'link' => 'apply-wfh.php'],
                ['name' => 'My WFH', 'link' => 'my-wfh.php']
            ]);
            echo '<li class="nav-item"><a class="nav-link" href="Attendance_Regularization.php"><i class="fa-solid fa-list-check menu-icon"></i><span class="menu-title">Attendance regularisation</span></a></li>';
            echo '<li class="nav-item"><a class="nav-link" href="myAttendance.php"><i class="fa-solid fa-user-check menu-icon"></i><span class="menu-title">My attendance</span></a></li>';
            echo '<li class="nav-item"><a class="nav-link" href="editProfile.php"><i class="fa-regular fa-user menu-icon"></i><span class="menu-title">Edit profile</span></a></li>';
            echo '<li class="nav-item"><a class="nav-link" href="feedback.php"><i class="fa-solid fa-comment-dots menu-icon"></i><span class="menu-title">Feedback/issue</span></a></li>';
        } else if ($designation == "staff" || strtolower($designation) == "staff" || strtolower($_SESSION['userRole'] ?? '') == "staff") {
            echo '<li class="nav-item"><a class="nav-link" href="staffDashboard.php"><i class="fa-solid fa-table-columns menu-icon"></i><span class="menu-title">Dashboard</span></a></li>';
            echo '<li class="nav-item"><a class="nav-link" href="myAttendance.php"><i class="fa-solid fa-user-check menu-icon"></i><span class="menu-title">My Attendance</span></a></li>';
            collapseMenu('staff-leave', 'Leave', 'fa-regular fa-calendar-days', [
                ['name' => 'Apply Leave', 'link' => 'apply_leave.php'],
                ['name' => 'My Leaves', 'link' => 'cancel_leaves.php']
            ]);
            collapseMenu('staff-wfh', 'WFH', 'fa-solid fa-house-laptop', [
                ['name' => 'Apply WFH', 'link' => 'apply-wfh.php'],
                ['name' => 'My WFH', 'link' => 'my-wfh.php']
            ]);
            echo '<li class="nav-item"><a class="nav-link" href="editProfile.php"><i class="fa-regular fa-user menu-icon"></i><span class="menu-title">Edit Profile</span></a></li>';
            echo '<li class="nav-item"><a class="nav-link" href="feedback.php"><i class="fa-solid fa-comment-dots menu-icon"></i><span class="menu-title">Feedback / Issue</span></a></li>';
        }

        $is_hr = ['admin', 'hr'];
        $is_rh = ['admin', 'resourceAllocator'];

        // 3. HR Exit Process (Visible to HR and Admin)
        if (in_array($designation, $is_hr)) {
            echo '<li class="nav-item"><a class="nav-link" href="hr_exit_process.php"><i class="fa-solid fa-person-walking-arrow-right menu-icon"></i><span class="menu-title"> Exit Process</span></a></li>';
        }

        // 4. RH Exit Approvals (Visible to Resource Head and Admin)
        if (in_array($designation, $is_rh)) {
            echo '<li class="nav-item"><a class="nav-link" href="rh_exit_approvals.php"><i class="fa-solid fa-clipboard-check menu-icon"></i><span class="menu-title">Exit Approvals</span></a></li>';
        }

        // ── Common: What's New ──
        if ($designation !== "juniorIntern" && strtolower($designation) !== "juniorintern" && strtolower($_SESSION['userRole'] ?? '') !== "junior_intern") {
            echo '<li class="nav-item"><a class="nav-link" href="whatsNew.php"><i class="mdi mdi-bullhorn menu-icon"></i><span class="menu-title">What\'s New</span></a></li>';
        }
        ?>
    </ul>
</nav>

<!-- Floating Trigger for Mobile Devices (Zero Navbar Interference) -->
<button type="button" class="mobile-sidebar-floating-btn" id="mobileSidebarOpenBtn" title="Open Menu" aria-label="Open Navigation Menu">
    <i class="fa-solid fa-bars-staggered"></i>
</button>

<script>
    document.addEventListener("DOMContentLoaded", () => {

        let currentOpenMenu = null;

        // Auto-detect and mark active navigation item on page load
        const curFile = window.location.pathname.split("/").pop().toLowerCase();
        if (curFile) {
            document.querySelectorAll("#sidebar .nav-link").forEach(link => {
                const href = link.getAttribute("href");
                if (!href || href.startsWith("#") || href.startsWith("javascript")) return;
                const linkFile = href.split("?")[0].split("#")[0].split("/").pop().toLowerCase();
                if (linkFile === curFile) {
                    const item = link.closest(".nav-item");
                    if (item) item.classList.add("active");
                    link.classList.add("active");

                    // If inside a collapsible submenu, expand parent and sync aria-expanded
                    const parentCollapse = link.closest(".collapse");
                    if (parentCollapse) {
                        parentCollapse.classList.add("show");
                        const parentItem = parentCollapse.closest(".nav-item");
                        if (parentItem) {
                            parentItem.classList.add("active");
                            const parentLink = parentItem.querySelector(":scope > .nav-link[data-bs-toggle='collapse']");
                            if (parentLink) {
                                parentLink.setAttribute("aria-expanded", "true");
                                parentLink.classList.add("active");
                            }
                        }
                    }
                }
            });
        }

        // Handle arrow click for collapse toggle
        document.querySelectorAll(".collapse-arrow").forEach(arrow => {
            arrow.addEventListener("click", function (e) {
                e.preventDefault();
                e.stopPropagation();
                this.closest(".nav-link").dispatchEvent(new MouseEvent("click"));
            });
        });

        // Handle nav link click for collapse
        document.querySelectorAll(".nav-link[data-bs-toggle='collapse']").forEach(link => {
            link.addEventListener("click", function (e) {
                e.preventDefault();
                e.stopPropagation();

                const targetId = this.getAttribute("href");
                const target = document.querySelector(targetId);
                if (!target) return;

                const subMenu = target.querySelector(".sub-menu");
                if (!subMenu) return;

                if (document.body.classList.contains("sidebar-icon-only")) {
                    if (currentOpenMenu === subMenu) {
                        subMenu.style.display = "none";
                        currentOpenMenu = null;
                        return;
                    }
                    document.querySelectorAll(".sub-menu").forEach(menu => menu.style.display = "none");
                    const rect = this.getBoundingClientRect();
                    subMenu.style.position = "fixed";
                    subMenu.style.top = rect.top + "px";
                    subMenu.style.left = (rect.right + 8) + "px";
                    subMenu.style.display = "block";
                    subMenu.style.zIndex = "99999";
                    currentOpenMenu = subMenu;
                } else {
                    const willShow = !target.classList.contains("show");

                    // Smooth accordion: close other open collapsibles
                    document.querySelectorAll("#sidebar .collapse.show").forEach(openCol => {
                        if (openCol !== target) {
                            openCol.classList.remove("show");
                            const parentLnk = openCol.closest(".nav-item")?.querySelector(":scope > .nav-link[data-bs-toggle='collapse']");
                            if (parentLnk) parentLnk.setAttribute("aria-expanded", "false");
                        }
                    });

                    target.classList.toggle("show", willShow);
                    this.setAttribute("aria-expanded", willShow ? "true" : "false");
                }
            });
        });

        // Add clean tooltips to each nav-link so in icon-only mode users see title on hover
        document.querySelectorAll("#sidebar .nav-item > .nav-link").forEach(link => {
            const titleElem = link.querySelector(".menu-title");
            if (titleElem && !link.getAttribute("title")) {
                link.setAttribute("title", titleElem.textContent.trim());
            }
        });

        // Sidebar toggle buttons (Attached to Sidebar Header & Edge floating pill)
        const sidebarCollapseToggle = document.getElementById("sidebarCollapseToggle");
        const sidebarEdgeToggle = document.getElementById("sidebarEdgeToggle");
        const mobileSidebarOpenBtn = document.getElementById("mobileSidebarOpenBtn");
        const mobileBackdrop = document.getElementById("mobileSidebarBackdrop");
        const sidebarElem = document.querySelector("#sidebar");

        function handleSidebarToggle(e) {
            if (e) {
                e.preventDefault();
                e.stopPropagation();
            }

            if (window.innerWidth <= 991) {
                // Mobile / Tablet: Close the sidebar drawer
                if (sidebarElem) sidebarElem.classList.remove("active");
                if (mobileBackdrop) mobileBackdrop.classList.remove("active");
            } else {
                // Desktop: Toggle compact icon-only mode
                const isNowIconOnly = document.body.classList.toggle("sidebar-icon-only");
                if (sidebarCollapseToggle) {
                    sidebarCollapseToggle.setAttribute("title", isNowIconOnly ? "Expand Sidebar" : "Collapse Sidebar");
                    sidebarCollapseToggle.setAttribute("aria-label", isNowIconOnly ? "Expand Sidebar" : "Collapse Sidebar");
                }
                currentOpenMenu = null;
                document.querySelectorAll(".sub-menu").forEach(menu => {
                    menu.style.display = "none";
                    menu.classList.remove("show");
                });
            }
        }

        if (sidebarCollapseToggle) {
            sidebarCollapseToggle.addEventListener("click", handleSidebarToggle);
        }
        if (sidebarEdgeToggle) {
            sidebarEdgeToggle.addEventListener("click", handleSidebarToggle);
        }

        // Mobile Floating Open Button (Opens Sidebar without touching navbar)
        if (mobileSidebarOpenBtn) {
            mobileSidebarOpenBtn.addEventListener("click", function (e) {
                e.preventDefault();
                e.stopPropagation();
                if (sidebarElem) sidebarElem.classList.add("active");
                if (mobileBackdrop) mobileBackdrop.classList.add("active");
            });
        }

        // Close mobile sidebar on backdrop click
        if (mobileBackdrop) {
            mobileBackdrop.addEventListener("click", function () {
                const sidebarElem = document.querySelector("#sidebar");
                if (sidebarElem) sidebarElem.classList.remove("active");
                mobileBackdrop.classList.remove("active");
            });
        }

        // Auto-close mobile sidebar when clicking any navigation link
        document.querySelectorAll("#sidebar .nav-link:not([data-bs-toggle='collapse'])").forEach(link => {
            link.addEventListener("click", function () {
                if (window.innerWidth <= 991) {
                    const sidebarElem = document.querySelector("#sidebar");
                    if (sidebarElem) sidebarElem.classList.remove("active");
                    if (mobileBackdrop) mobileBackdrop.classList.remove("active");
                }
            });
        });

        // Close submenu when clicking outside (icon-only mode)
        document.addEventListener('click', function (e) {
            if (!document.body.classList.contains("sidebar-icon-only")) return;
            const isClickInsideSidebar = e.target.closest('#sidebar');
            const isClickOnNavLink = e.target.closest('.nav-link[data-bs-toggle="collapse"]');
            const isClickInsideSubMenu = e.target.closest('.sub-menu');
            if (!isClickInsideSidebar && !isClickOnNavLink && !isClickInsideSubMenu) {
                document.querySelectorAll(".sub-menu").forEach(menu => { menu.style.display = "none"; });
                currentOpenMenu = null;
            }
        });

        // Close on ESC key
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && document.body.classList.contains("sidebar-icon-only")) {
                document.querySelectorAll(".sub-menu").forEach(menu => { menu.style.display = "none"; });
                currentOpenMenu = null;
            }
        });

        // Prevent closing when clicking inside submenu
        document.querySelectorAll('.sub-menu').forEach(menu => {
            menu.addEventListener('click', function (e) { e.stopPropagation(); });
        });

    });
</script>