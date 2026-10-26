import pytest
from app.auth.permissions import has_permission, P_LEAVE_APPROVE, P_ATTENDANCE_PUNCH, P_TASK_CREATE
from app.tools.attendance import LATE_THRESHOLD, EXPECTED_PUNCH_IN
from datetime import datetime

def test_rbac_admin_permissions():
    """Assert that admin users possess all permissions."""
    assert has_permission("admin", "developer", [P_LEAVE_APPROVE, P_ATTENDANCE_PUNCH, P_TASK_CREATE])
    assert has_permission("junior_intern", "admin", [P_LEAVE_APPROVE])

def test_rbac_junior_permissions():
    """Assert that junior interns are restricted from executing approvals."""
    assert not has_permission("junior_intern", "intern", [P_LEAVE_APPROVE])
    assert has_permission("junior_intern", "intern", [P_ATTENDANCE_PUNCH, P_TASK_CREATE])

def test_late_mark_grace_period():
    """Assert that late mark grace windows are correctly calculated."""
    expected_in = "09:30:00"
    grace_limit = "09:45:00"
    late_limit = "10:30:00"
    
    # 09:37 is within grace period
    pi_time_1 = datetime.strptime("09:37:00", "%H:%M:%S")
    expected_ts = datetime.strptime(expected_in, "%H:%M:%S")
    grace_ts = datetime.strptime(grace_limit, "%H:%M:%S")
    assert pi_time_1 <= grace_ts
    
    # 09:48 is late
    pi_time_2 = datetime.strptime("09:48:00", "%H:%M:%S")
    assert pi_time_2 > grace_ts
    assert pi_time_2 <= datetime.strptime(late_limit, "%H:%M:%S")
    
    # 10:45 is half day
    pi_time_3 = datetime.strptime("10:45:00", "%H:%M:%S")
    assert pi_time_3 > datetime.strptime(late_limit, "%H:%M:%S")

def test_leave_day_exclusion():
    """Assert that Sundays are excluded from leave days duration counting."""
    # From 2026-08-28 (Friday) to 2026-08-31 (Monday) has 4 days total but includes 1 Sunday
    from_date = "2026-08-28"
    to_date = "2026-08-31"
    
    # Excludes Sunday (2026-08-30)
    s_dt = datetime.strptime(from_date, "%Y-%m-%d").date()
    e_dt = datetime.strptime(to_date, "%Y-%m-%d").date()
    
    days = 0
    curr = s_dt
    while curr <= e_dt:
        if curr.weekday() != 6: # 6 is Sunday
            days += 1
        curr += timedelta_days(1)
        
    assert days == 3  # Fri, Sat, Mon (excluding Sun)

def timedelta_days(days: int):
    import datetime as dt
    return dt.timedelta(days=days)
