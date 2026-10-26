# 🚀 Release Notes: AMS Platform Update

**Comparison**: `Dev-SETTribe-AMS` (Latest `main`) vs. `Prod-SETTribe-AMS` (`main`)  
**Release Date**: August 17, 2026  
**Environment**: Production Candidate  

---

## 📌 Executive Summary
This release introduces major policy implementations, management dashboards, digital signage modules, and UI responsiveness upgrades across the Attendance Management System (AMS). Key additions include a **revamped Late Mark Policy** with automated leave deductions, an **HR Early Go Management Portal**, a **Task Diary Detailed Analytics Module**, **Financial Year Leave Cycle Automation**, and a complete **Digital Kiosk Display & Announcement System**.

---

## 🔥 Key Feature Highlights

### 1. ⏰ Revamped Late Mark Policy & Automated Deductions
- **Grace Time Window**: Punch-ins between **9:30 AM and 9:45 AM** are marked On-Time / Present with 0 late minutes (`lateMark = 'no'`).
- **Late Mark Window**: Punch-ins between **9:46 AM and 10:30 AM** record 1 Late Mark (`lateMark = 'yes'`).
- **Half Day Threshold**: Punch-ins after **10:30 AM** are automatically evaluated as Half Day status.
- **Monthly Penalty Deduction Engine**:
  - **1 – 3 Late Marks**: Warning level (0 leave penalty).
  - **4th Late Mark Onward**: 0.5 Day Leave deduction for every 2 late marks (`floor(($lateCount - 2) / 2) * 0.5`).
- **Employee Visibility**: Stat card on `myAttendance.php` displays real-time late counts and penalty deductions (`X/3 Warning Level` or `X.X Half Day Leave Deduction`).
- **Integrated Files**:
  - `include/late_mark_helper.php` *(New helper library)*
  - `pages/myAttendance.php` *(UI stat card & penalty note)*
  - `pages/attendance.php` *(Self punch-in API integration)*
  - `pages/save_selfie.php` *(Web selfie punch-in API integration)*

---

### 2. 🏃 Early Go Policy & HR Management Portal
- **Early Punch-Out Rules**: Employees/interns are allowed to punch out 15 minutes prior to their scheduled punch-out time, or upon receiving HR approval.
- **HR Approval Portal (`pages/hr_early_go.php`)**:
  - **KPI Metrics**: Real-time cards for *Granted Today*, *7-Day Volume*, *Average Punch-out Time*, and *Top Departure Reason*.
  - **Analytics Charts**: Interactive 7-day trend bar chart and 30-day reason distribution doughnut chart.
  - **Quick Reason Chips**: One-click preset reasons (`🏥 Medical`, `🚘 Personal`, `📚 Exam`, `💼 Official Work`, `⚡ Emergency`).
  - **Notification Integration**: Automatically dispatches in-app notifications to interns upon HR approval.
- **Mobile Responsiveness Overhaul**: Added full container fluid layout, dynamic text wrapping, chart legend re-positioning, and touch-scrolling data tables for small screens.
- **Integrated Files**:
  - `include/early_go_helper.php` *(New helper library)*
  - `pages/hr_early_go.php` *(HR Management Portal & Responsive Layout)*
  - `pages/Early_Go Report.php` *(Detailed early go report)*
  - `pages/late_punch_early_go.php` *(Employee permission view)*

---

### 3. 🏖️ New Leave Policy & Financial Year Automation
- **Financial Year Cycle Automation**: Added `cron/update_leave_policy_fy.php` for annual leave balance resets and carry-forward calculations.
- **Roster & Dropdown Filtering**: Updated leave application dropdowns and junior intern rosters to automatically filter out inactive, rejected, or blank-named employees.
- **Granular Cancellation**: Added support for single-day leave cancellations (`cancelSingleDay.php`, `cancelLeaveAjax.php`).
- **Integrated Files**:
  - `cron/update_leave_policy_fy.php` *(Annual FY leave cron script)*
  - `pages/apply_leave.php` *(Updated leave request form)*
  - `pages/hr_leave_management.php` *(HR leave control portal)*
  - `pages/leave_approval.php` *(Approval workflow engine)*
  - `pages/leave_DB.php` *(Leave database management)*

---

### 4. 📊 Task Diary Detailed Reporting Engine
- **MD & Management Reporting (`pages/taskDiaryDetailedReport.php`)**:
  - Provides a centralized view of daily task entries submitted across departments.
  - Advanced filtering by date range, department, intern name, and completion status.
  - Export capabilities for audit and performance reviews.
- **Integrated Files**:
  - `pages/taskDiaryDetailedReport.php` *(New detailed report interface)*
  - `ajax/mdTaskDiary.php` *(Backend AJAX data provider)*

---

### 5. 📺 AMS Display Board, Announcements & Advertisements Module
- **Digital Signage Kiosk (`pages/ams_display_board.php`)**: Kiosk-ready display board for reception desks and lobby monitors.
- **Announcement Management (`pages/add_announcement.php`)**: HR/Admin broadcast system with target audience filters, unread badge counters, and read-receipt tracking.
- **Advertisements & Placements (`pages/add_advertisement.php`)**: Module to schedule internal news, achievements, and placement banners.
- **Integrated Files**:
  - `pages/ams_display_board.php` *(Display kiosk page)*
  - `pages/add_announcement.php` *(Announcement creation module)*
  - `pages/add_advertisement.php` *(Ad & Placement management)*
  - `ajax/*` *(Announcement and Advertisement AJAX handlers)*

---

## 🎨 UI/UX & Dashboard Enhancements
- **HR & Resource Head Dashboards (`pages/hrDashboard.php`, `pages/resourceHeadDashboard.php`)**:
  - Completely revamped dashboard layout with modern card aesthetics, real-time KPI metrics, attendance activity feeds, and interactive Chart.js widgets.
- **Punch-Out Component (`partials/_punch_out_btn.php`)**:
  - Improved open punch-in row detection logic to eliminate false *"No open punch-in found"* toast messages.
- **Junior Mode Switch (`pages/jrModeSwitch.php`)**:
  - Added filter to hide interns with blank names or inactive status from the roster switcher.

---

## 📁 Key File Changes Summary

| Module | Files Modified / Added | Action |
| :--- | :--- | :--- |
| **Late Mark Policy** | `include/late_mark_helper.php`, `pages/myAttendance.php`, `pages/attendance.php`, `pages/save_selfie.php` | **[NEW]** Helper & logic integration |
| **Early Go Policy** | `include/early_go_helper.php`, `pages/hr_early_go.php`, `pages/Early_Go Report.php`, `pages/late_punch_early_go.php` | **[NEW]** Portal, API & responsive UI |
| **Leave Policy** | `cron/update_leave_policy_fy.php`, `pages/apply_leave.php`, `pages/hr_leave_management.php`, `pages/leave_approval.php` | **[NEW]** Cron script & roster filters |
| **Task Diary** | `pages/taskDiaryDetailedReport.php`, `ajax/mdTaskDiary.php` | **[NEW]** Detailed report module |
| **Display Kiosk & Ads** | `pages/ams_display_board.php`, `pages/add_announcement.php`, `pages/add_advertisement.php`, `ajax/*` | **[NEW]** Announcement & Ad engine |
| **Dashboards & Components** | `pages/hrDashboard.php`, `pages/resourceHeadDashboard.php`, `partials/_punch_out_btn.php` | **[MODIFY]** UI overhaul & bug fixes |

---

## 🛠️ Verification & Testing
- **Unit Tests**: Executed 18 test cases validating late mark policy edge cases (9:30 AM, 9:45 AM, 9:46 AM, 10:30 AM, 10:31 AM, and monthly deduction counts >= 4).
- **Database Historical Sync**: Database records verified across 8,000+ historical entries.
- **Linting & Syntax**: PHP CLI syntax checks (`php -l`) passed clean across all modified files.
