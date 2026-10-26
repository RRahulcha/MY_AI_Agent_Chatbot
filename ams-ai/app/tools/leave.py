import logging
import uuid
from datetime import datetime, date, timedelta
from app.db.mysql import execute_query
from app.notifications.fcm import send_notification_to_admins, send_notification_to_user

logger = logging.getLogger("ams-ai.tools.leave")

LEAVE_NAME_MAP = {
    "SL": "Sick Leave",
    "PL": "Privilege / Earned Leave",
    "CL": "Casual Leave",
    "TD": "Tour Day",
    "RO": "Rostering Off"
}

def get_leave_balances(user_id: str) -> dict:
    """Fetch current leave balances for CL, SL, PL, TD, and RO."""
    query = "SELECT SL, PL, CL, TD, RO FROM signup WHERE userId = ? LIMIT 1"
    try:
        res = execute_query(query, (user_id,), fetch="one")
        if not res:
            return {"success": False, "message": "User not found."}
        # Convert string columns to int safely
        balances = {k: int(v) if v is not None else 0 for k, v in res.items()}
        return {"success": True, "data": balances}
    except Exception as e:
        logger.error(f"Error fetching leave balances: {e}")
        return {"success": False, "message": f"Database error: {e}"}

def _calculate_leave_days(from_date: str, to_date: str) -> int:
    """Calculate the number of leave days excluding Sundays."""
    s_date = datetime.strptime(from_date, "%Y-%m-%d").date()
    e_date = datetime.strptime(to_date, "%Y-%m-%d").date()
    
    days = 0
    curr = s_date
    while curr <= e_date:
        if curr.weekday() != 6:  # Sunday is index 6
            days += 1
        curr += timedelta(days=1)
    return days

def apply_leave(user_id: str, leave_type: str, from_date: str, to_date: str, reason: str, document_path: str = None) -> dict:
    """Apply for leave after checking all constraints and balances."""
    today = date.today()
    from_dt = datetime.strptime(from_date, "%Y-%m-%d").date()
    to_dt = datetime.strptime(to_date, "%Y-%m-%d").date()

    if leave_type not in LEAVE_NAME_MAP:
        return {"success": False, "message": f"Invalid leave type '{leave_type}'."}

    leave_name = LEAVE_NAME_MAP[leave_type]

    # 1. Block leaves in the past
    if from_dt < today:
        return {"success": False, "message": "You cannot apply for leave on passed dates."}

    # 2. Block Sunday start/end dates
    if from_dt.weekday() == 6 or to_dt.weekday() == 6:
        return {"success": False, "message": "You cannot apply for leave on Sundays."}

    # 3. Check punch-in restriction
    check_punch = execute_query(
        "SELECT id FROM attendance WHERE userId = ? AND date = ? AND punchInTime IS NOT NULL LIMIT 1",
        (user_id, today.isoformat()),
        fetch="one"
    )
    if check_punch and from_dt == today:
        return {"success": False, "message": "You have already PUNCHED IN today. You cannot apply leave for today."}

    # 4. RO must be on Saturdays only
    if leave_type == "RO":
        curr = from_dt
        while curr <= to_dt:
            if curr.weekday() != 5:  # Saturday is index 5
                return {"success": False, "message": "Rostering Off can only be applied on Saturdays."}
            curr += timedelta(days=1)

    # 5. Overlap check in leaves table
    overlap_leaves = execute_query(
        """
        SELECT id FROM leaves 
        WHERE userId = ? 
          AND status NOT IN ('Cancelled', 'Rejected') 
          AND leave_name NOT LIKE '%Auto Leave%' 
          AND leave_type != 'Auto'
          AND from_date <= ? AND to_date >= ?
        LIMIT 1
        """,
        (user_id, to_date, from_date),
        fetch="one"
    )
    if overlap_leaves:
        return {"success": False, "message": "You already have leave applied during this period."}

    # 6. Overlap WFH check
    overlap_wfh = execute_query(
        """
        SELECT wfh_id FROM wfhRequests 
        WHERE userid = ? AND status NOT IN ('Rejected', 'Cancelled') 
          AND fromDate <= ? AND toDate >= ?
        LIMIT 1
        """,
        (user_id, to_date, from_date),
        fetch="one"
    )
    if overlap_wfh:
        return {"success": False, "message": "You have already applied for WFH during this date range."}

    # 7. Overlap Tour check
    overlap_tour = execute_query(
        """
        SELECT id FROM tour 
        WHERE userId = ? AND status NOT IN ('Rejected', 'Cancelled') 
          AND fromDate <= ? AND toDate >= ?
        LIMIT 1
        """,
        (user_id, to_date, from_date),
        fetch="one"
    )
    if overlap_tour:
        return {"success": False, "message": "You have already applied for a Tour during this date range."}

    # 8. RO Monthly Cap check (for offline interns only: max 2 days per month)
    if leave_type == "RO":
        user_type = execute_query("SELECT type FROM signup WHERE userId = ? LIMIT 1", (user_id,), fetch="one")
        if user_type and user_type["type"].lower() == "offline":
            # Group RO check per month
            ro_months = {}
            curr = from_dt
            while curr <= to_dt:
                m_key = curr.strftime("%Y-%m")
                ro_months[m_key] = ro_months.get(m_key, 0) + 1
                curr += timedelta(days=1)
                
            for m_key, days_requested in ro_months.items():
                month_start = f"{m_key}-01"
                month_end = get_last_day_of_month(month_start)
                
                existing_ro = execute_query(
                    """
                    SELECT from_date, to_date FROM leaves 
                    WHERE userId = ? AND leave_type = 'RO' 
                      AND status NOT IN ('Cancelled', 'Rejected')
                      AND from_date <= ? AND to_date >= ?
                    """,
                    (user_id, month_end, month_start),
                    fetch="all"
                )
                
                ro_used = 0
                for row in existing_ro:
                    rs = max(row["from_date"], datetime.strptime(month_start, "%Y-%m-%d").date())
                    re = min(row["to_date"], datetime.strptime(month_end, "%Y-%m-%d").date())
                    # Count Saturdays
                    c_date = rs
                    while c_date <= re:
                        if c_date.weekday() == 5:
                            ro_used += 1
                        c_date += timedelta(days=1)
                        
                if ro_used + days_requested > 2:
                    return {
                        "success": False, 
                        "message": f"Rostering Off limit reached for {m_key}. Only 2 RO days are allowed per month for offline interns."
                    }

    # 9. Balance check considering pending leaves
    available = get_leave_balances(user_id)["data"][leave_type]
    pending_ro = execute_query(
        """
        SELECT from_date, to_date FROM leaves 
        WHERE userId = ? AND leave_type = ? AND status = 'Pending'
        """,
        (user_id, leave_type),
        fetch="all"
    )
    pending_days = 0
    for p in pending_ro:
        pending_days += _calculate_leave_days(str(p["from_date"]), str(p["to_date"]))

    effective_available = max(0, available - pending_days)
    requested_days = _calculate_leave_days(from_date, to_date)
    
    if effective_available <= 0:
        return {"success": False, "message": f"You have no {leave_name} balance remaining (0 available, {pending_days} pending)."}
    if requested_days > effective_available:
        return {"success": False, "message": f"Insufficient {leave_name} balance. Available: {effective_available} day(s), Requested: {requested_days} day(s)."}

    # 10. Write leave record to DB
    leave_id = f"LV_{uuid.uuid4().hex[:16]}"
    created_date = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    
    # RO is auto-approved, others start as Pending
    status_val = "Approved" if leave_type == "RO" else "Pending"
    
    try:
        # Delete any overlapping system auto leaves
        execute_query(
            "DELETE FROM leaves WHERE userId = ? AND (leave_name LIKE '%Auto Leave%' OR leave_type = 'Auto') AND from_date <= ? AND to_date >= ?",
            (user_id, to_date, from_date),
            fetch="none"
        )
        
        # Insert leave application
        execute_query(
            """
            INSERT INTO leaves 
            (leave_id, leave_d, leave_name, leave_type, leave_day, from_date, to_date, reason, approved_by, informed_colleagues, document_path, userId, status, created_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            """,
            (leave_id, "N/A", leave_name, leave_type, "Full Day", from_date, to_date, reason, "", "", document_path, user_id, status_val, created_date),
            fetch="none"
        )
        
        # Deduct balance immediately only if auto-approved (RO)
        if leave_type == "RO":
            new_balance = max(0, available - requested_days)
            execute_query("UPDATE signup SET RO = ? WHERE userId = ?", (new_balance, user_id), fetch="none")
            
        # Send FCM notification to admins
        emp_name = execute_query("SELECT fullName FROM signup WHERE userId = ? LIMIT 1", (user_id,), fetch="one")["fullName"]
        send_notification_to_admins(
            title="New Leave Request 📋",
            body=f"{emp_name} has applied for {leave_name} ({requested_days} days).",
            notif_type="leave_applied",
            reference_id=leave_id
        )

        return {
            "success": True, 
            "message": f"Leave applied successfully. Leave ID: {leave_id}. Status: {status_val}."
        }
    except Exception as e:
        logger.error(f"Error submitting leave request: {e}")
        return {"success": False, "message": f"Database insertion failed: {e}"}

def cancel_leave(user_id: str, leave_id: str) -> dict:
    """Soft delete/cancel a leave request and refund balance if approved."""
    today_str = date.today().isoformat()

    try:
        leave = execute_query(
            "SELECT leave_type, status, from_date, to_date FROM leaves WHERE (id = ? OR leave_id = ?) AND userId = ? LIMIT 1",
            (leave_id, leave_id, user_id),
            fetch="one"
        )
        if not leave:
            return {"success": False, "message": "Leave request not found."}

        # Expired leaves cannot be cancelled
        if leave["status"] == "Approved" and str(leave["to_date"]) < today_str:
            return {"success": False, "message": "You cannot cancel approved leaves in the past."}

        # Calculate leave duration
        days = _calculate_leave_days(str(leave["from_date"]), str(leave["to_date"]))

        # Refund balance if previously Approved
        leave_type = leave["leave_type"]
        if leave["status"] == "Approved" and leave_type in LEAVE_NAME_MAP:
            execute_query(
                f"UPDATE signup SET `{leave_type}` = `{leave_type}` + ? WHERE userId = ?",
                (days, user_id),
                fetch="none"
            )

        # Update leave status to Cancelled
        execute_query(
            "UPDATE leaves SET status = 'Cancelled', updated_date = NOW() WHERE (id = ? OR leave_id = ?) AND userId = ?",
            (leave_id, leave_id, user_id),
            fetch="none"
        )

        return {"success": True, "message": "Leave request has been successfully cancelled."}
    except Exception as e:
        logger.error(f"Error cancelling leave: {e}")
        return {"success": False, "message": f"Database update failed: {e}"}

def get_pending_leaves() -> dict:
    """Fetch all pending leave requests across the system."""
    query = """
        SELECT l.leave_id, s.fullName, l.leave_name, l.from_date, l.to_date, l.reason, l.created_date 
        FROM leaves l 
        JOIN signup s ON l.userId = s.userId
        WHERE l.status = 'Pending' 
        ORDER BY l.created_date ASC
    """
    try:
        rows = execute_query(query, fetch="all")
        for r in rows:
            r["from_date"] = str(r["from_date"])
            r["to_date"] = str(r["to_date"])
            r["created_date"] = str(r["created_date"])
        return {"success": True, "data": rows}
    except Exception as e:
        logger.error(f"Error fetching pending leaves: {e}")
        return {"success": False, "message": f"Database error: {e}"}

def approve_leave(leave_id: str, approver_id: str) -> dict:
    """Approve a leave application and deduct from the user's balance."""
    try:
        leave = execute_query(
            "SELECT userId, leave_type, status, from_date, to_date FROM leaves WHERE leave_id = ? LIMIT 1",
            (leave_id,),
            fetch="one"
        )
        if not leave:
            return {"success": False, "message": "Leave request not found."}

        if leave["status"] != "Pending":
            return {"success": False, "message": f"Cannot approve leave. Current status is '{leave['status']}'."}

        days = _calculate_leave_days(str(leave["from_date"]), str(leave["to_date"]))
        leave_type = leave["leave_type"]
        applicant_id = leave["userId"]

        # Deduct balance
        if leave_type in LEAVE_NAME_MAP:
            execute_query(
                f"UPDATE signup SET `{leave_type}` = GREATEST(0, `{leave_type}` - ?) WHERE userId = ?",
                (days, applicant_id),
                fetch="none"
            )

        # Update status
        execute_query(
            "UPDATE leaves SET status = 'Approved', approved_by = ?, updated_date = NOW() WHERE leave_id = ?",
            (approver_id, leave_id),
            fetch="none"
        )

        # Dispatch FCM to applicant
        send_notification_to_user(
            user_id=applicant_id,
            title="Leave Approved ✅",
            body=f"Your leave request for {leave['leave_type']} has been approved.",
            notif_type="leave_status",
            reference_id=leave_id
        )

        return {"success": True, "message": f"Leave request approved. Balance deducted: {days} day(s)."}
    except Exception as e:
        logger.error(f"Error approving leave: {e}")
        return {"success": False, "message": f"Database update failed: {e}"}

def reject_leave(leave_id: str, approver_id: str, reason: str = None) -> dict:
    """Reject a leave application."""
    try:
        leave = execute_query(
            "SELECT userId, leave_type, status FROM leaves WHERE leave_id = ? LIMIT 1",
            (leave_id,),
            fetch="one"
        )
        if not leave:
            return {"success": False, "message": "Leave request not found."}

        if leave["status"] != "Pending":
            return {"success": False, "message": f"Cannot reject leave. Current status is '{leave['status']}'."}

        applicant_id = leave["userId"]

        # Update status
        execute_query(
            "UPDATE leaves SET status = 'Rejected', approved_by = ?, rejection_reason = ?, updated_date = NOW() WHERE leave_id = ?",
            (approver_id, reason, leave_id),
            fetch="none"
        )

        # Dispatch FCM to applicant
        send_notification_to_user(
            user_id=applicant_id,
            title="Leave Rejected ❌",
            body=f"Your leave request has been rejected. Reason: {reason or 'Not specified'}.",
            notif_type="leave_status",
            reference_id=leave_id
        )

        return {"success": True, "message": "Leave request rejected."}
    except Exception as e:
        logger.error(f"Error rejecting leave: {e}")
        return {"success": False, "message": f"Database update failed: {e}"}

# Helper to find last day of month
def get_last_day_of_month(date_str: str) -> str:
    dt = datetime.strptime(date_str, "%Y-%m-%d").date()
    next_month = dt.replace(day=28) + timedelta(days=4)
    res = next_month - timedelta(days=next_month.day)
    return res.isoformat()
