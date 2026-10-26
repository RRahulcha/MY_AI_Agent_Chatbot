import logging
from datetime import datetime, date, time
from app.db.mysql import execute_query

logger = logging.getLogger("ams-ai.tools.attendance")

# Late Mark Constants (from late_mark_helper.php)
EXPECTED_PUNCH_IN = "09:30:00"
LATE_THRESHOLD = "09:45:00"
HALF_DAY_IN_THRESHOLD = "10:30:00"

# Early Go Constants (from early_go_helper.php)
EXPECTED_PUNCH_OUT = "18:30:00"

def get_attendance_range(user_id: str, from_date: str, to_date: str) -> dict:
    """Fetch attendance logs within a date range."""
    query = """
        SELECT date, punchInTime, punchOutTime, lateMark, punchOutStatus, remark, status
        FROM attendance 
        WHERE userId = ? AND date BETWEEN ? AND ? 
        ORDER BY date ASC
    """
    try:
        rows = execute_query(query, (user_id, from_date, to_date), fetch="all")
        # Format time and date for output
        for r in rows:
            if r["date"]:
                r["date"] = str(r["date"])
            if r["punchInTime"]:
                r["punchInTime"] = str(r["punchInTime"])
            if r["punchOutTime"]:
                r["punchOutTime"] = str(r["punchOutTime"])
        return {"success": True, "data": rows}
    except Exception as e:
        logger.error(f"Error fetching attendance range: {e}")
        return {"success": False, "message": f"Database error: {e}"}

def punch_in(user_id: str, latitude: float, longitude: float, selfie_url: str) -> dict:
    """Register daily entry punch-in with geolocation and selfie checks."""
    today_str = date.today().isoformat()
    now_time_str = datetime.now().strftime("%H:%M:%S")
    now_datetime_str = datetime.now().strftime("%Y-%m-%d %H:%M:%S")

    # 1. Prevent double punch-in
    check = execute_query(
        "SELECT id FROM attendance WHERE userId = ? AND date = ? LIMIT 1",
        (user_id, today_str),
        fetch="one"
    )
    if check:
        return {"success": False, "message": "You have already punched in today."}

    # 2. Resolve expected punch-in time from signup table
    user_info = execute_query(
        "SELECT expectedPunchIn, userRole, designation FROM signup WHERE userId = ? LIMIT 1",
        (user_id,),
        fetch="one"
    )
    expected_in = str(user_info.get("expectedPunchIn") or EXPECTED_PUNCH_IN)
    user_role = user_info.get("userRole") or "junior_intern"
    designation = user_info.get("designation") or ""

    # 3. Evaluate Late Mark Policy (replaces late_mark_helper.php evaluation)
    grace_sec = datetime.strptime(expected_in, "%H:%M:%S") + timedelta_minutes(15)
    half_day_sec = datetime.strptime(expected_in, "%H:%M:%S") + timedelta_minutes(60) # 10:30 AM
    actual_sec = datetime.strptime(now_time_str, "%H:%M:%S")

    late_mark = "no"
    status_val = "Present"

    # Check eligibility (only senior_intern / resourceAllocator / designation hr get late marks)
    is_eligible = (
        user_role in ["senior_intern", "resourceAllocator"]
        or designation.lower().strip() == "hr"
    )

    if is_eligible:
        if actual_sec <= grace_sec:
            late_mark = "no"
        elif actual_sec <= half_day_sec:
            late_mark = "yes"
        else:
            late_mark = "no"
            status_val = "Half Day"

    # 4. Insert punch-in record
    try:
        execute_query(
            """
            INSERT INTO attendance 
            (date, userId, punchInTime, punchInImage, punchInLatitude, punchInLongitude, lateMark, status, dateTime)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            """,
            (today_str, user_id, now_time_str, selfie_url, latitude, longitude, late_mark, status_val, now_datetime_str),
            fetch="none"
        )
        return {
            "success": True, 
            "message": f"Punch-in recorded successfully at {now_time_str}. Status: {status_val}. Late mark: {late_mark}."
        }
    except Exception as e:
        logger.error(f"Error executing punch-in query: {e}")
        return {"success": False, "message": f"Database insertion failed: {e}"}

def punch_out(user_id: str, latitude: float, longitude: float, selfie_url: str) -> dict:
    """Register daily exit punch-out with blocker and early go checks."""
    today_str = date.today().isoformat()
    now_time_str = datetime.now().strftime("%H:%M:%S")

    # 1. Verify user is punched in today
    attendance_record = execute_query(
        "SELECT id, punchInTime FROM attendance WHERE userId = ? AND date = ? LIMIT 1",
        (user_id, today_str),
        fetch="one"
    )
    if not attendance_record or not attendance_record["punchInTime"]:
        return {"success": False, "message": "No open punch-in record found for today."}

    # 2. Block punch-out if there are active running tasks (Punch-Out Task Blocker)
    running_task = execute_query(
        "SELECT taskId FROM TaskEntryNew WHERE userId = ? AND status = 'Running' LIMIT 1",
        (user_id,),
        fetch="one"
    )
    if running_task:
        return {
            "success": False, 
            "message": "You cannot punch out while tasks are still running. Please pause or finish your active task first."
        }

    # 3. Resolve expected exit time
    user_info = execute_query(
        "SELECT expectedPunchOut FROM signup WHERE userId = ? LIMIT 1",
        (user_id,),
        fetch="one"
    )
    expected_out = str(user_info.get("expectedPunchOut") or EXPECTED_PUNCH_OUT)
    
    # 4. Check for pre-granted HR Early Go
    hr_early_go = execute_query(
        "SELECT early_go_time FROM early_go WHERE userId = ? AND date = ? AND hr_granted = 1 ORDER BY id DESC LIMIT 1",
        (user_id, today_str),
        fetch="one"
    )
    
    effective_expected = expected_out
    if hr_early_go and hr_early_go["early_go_time"]:
        effective_expected = str(hr_early_go["early_go_time"])

    # 5. Evaluate Early Go duration
    expected_ts = datetime.strptime(f"{today_str} {effective_expected}", "%Y-%m-%d %H:%M:%S")
    actual_ts = datetime.strptime(f"{today_str} {now_time_str}", "%Y-%m-%d %H:%M:%S")
    
    minutes_early = max(0, int((expected_ts - actual_ts).total_seconds() / 60))
    
    early_mark = 0
    action_taken = "Normal"
    punch_out_status = "Normal"

    if hr_early_go:
        action_taken = "HR Approved Early Go"
    elif minutes_early <= 15:
        action_taken = "Allowed (<=15 mins)"
    elif minutes_early <= 120:
        early_mark = 1
        action_taken = f"1 Early Go Mark ({minutes_early} mins)"
        punch_out_status = "Early_Go"
    else:
        early_mark = 1
        action_taken = f"Half Day Leave (>120 mins: {minutes_early} mins)"
        punch_out_status = "Early_Go"

    # Update early_go table and attendance records in database transaction
    try:
        # Update attendance record
        execute_query(
            """
            UPDATE attendance 
            SET punchOutTime = ?, punchOutImage = ?, punchOutLatitude = ?, punchOutLongitude = ?, punchOutStatus = ?
            WHERE userId = ? AND date = ?
            """,
            (now_time_str, selfie_url, latitude, longitude, punch_out_status, user_id, today_str),
            fetch="none"
        )
        
        # Upsert early_go record if there is a mark or approved early go
        if early_mark == 1 or hr_early_go:
            # Check if record exists
            exist_early = execute_query(
                "SELECT id FROM early_go WHERE userId = ? AND date = ? LIMIT 1",
                (user_id, today_str),
                fetch="one"
            )
            
            # Retrieve user display name
            emp_name = execute_query("SELECT fullName FROM signup WHERE userId = ? LIMIT 1", (user_id,), fetch="one")["fullName"]
            
            if exist_early:
                execute_query(
                    """
                    UPDATE early_go 
                    SET emp_name = ?, early_go_time = ?, early_departure_minutes = ?, early_go_mark = ?, action_taken = ?
                    WHERE id = ?
                    """,
                    (emp_name, now_time_str, minutes_early, early_mark, action_taken, exist_early["id"]),
                    fetch="none"
                )
            else:
                execute_query(
                    """
                    INSERT INTO early_go 
                    (userId, emp_name, early_go_time, early_departure_minutes, early_go_mark, action_taken, date, reason, hr_granted)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    """,
                    (user_id, emp_name, now_time_str, minutes_early, early_mark, action_taken, today_str, "Punched out early", 0),
                    fetch="none"
                )

        return {
            "success": True, 
            "message": f"Punch-out recorded successfully at {now_time_str}. Action: {action_taken}."
        }
    except Exception as e:
        logger.error(f"Error executing punch-out transaction: {e}")
        return {"success": False, "message": f"Database update failed: {e}"}

def check_punch_out_status(user_id: str) -> dict:
    """Check if user has permission to exit punch-out (locked/unlocked)."""
    today_str = date.today().isoformat()
    now_ts = datetime.now()

    try:
        user_info = execute_query(
            "SELECT expectedPunchOut FROM signup WHERE userId = ? LIMIT 1",
            (user_id,),
            fetch="one"
        )
        if not user_info:
            return {"success": False, "message": "User not found."}
            
        expected_out = str(user_info.get("expectedPunchOut") or EXPECTED_PUNCH_OUT)
        expected_out_ts = datetime.combine(date.today(), datetime.strptime(expected_out, "%H:%M:%S").time())

        # Check early go approved for today
        hr_early_go = execute_query(
            "SELECT early_go_time FROM early_go WHERE userId = ? AND date = ? AND hr_granted = 1 ORDER BY id DESC LIMIT 1",
            (user_id, today_str),
            fetch="one"
        )
        
        unlock_ts = expected_out_ts
        has_hr_early_go = False
        early_go_time = None
        
        if hr_early_go and hr_early_go["early_go_time"]:
            early_go_time_val = hr_early_go["early_go_time"]
            # MySQL time field can contain timedelta, format as time string
            if isinstance(early_go_time_val, str):
                t_val = datetime.strptime(early_go_time_val, "%H:%M:%S").time()
            else:
                # timedelta / time object
                t_val = (datetime.min + early_go_time_val).time()
            unlock_ts = datetime.combine(date.today(), t_val)
            has_hr_early_go = True
            early_go_time = unlock_ts.strftime("%I:%M %p")

        unlocked = (now_ts >= unlock_ts)
        seconds_left = max(0, int((unlock_ts - now_ts).total_seconds()))

        status_msg = f"Punch-out is {'UNLOCKED' if unlocked else 'LOCKED'}. Expected Punch-Out: {expected_out_ts.strftime('%I:%M %p')}."
        if has_hr_early_go:
            status_msg += f" (HR Approved Early Go granted for {early_go_time})."
        elif not unlocked:
            mins_left = int(seconds_left // 60)
            status_msg += f" ({mins_left} minutes remaining until {unlock_ts.strftime('%I:%M %p')})."
        else:
            status_msg += " (You are eligible to punch out now)."

        return {
            "success": True,
            "data": {
                "unlocked": unlocked,
                "secondsLeft": seconds_left,
                "unlockTime": unlock_ts.strftime("%I:%M %p"),
                "hasHrEarlyGo": has_hr_early_go,
                "earlyGoTime": early_go_time,
                "expectedPunchOut": expected_out_ts.strftime("%I:%M %p"),
                "status": "unlocked" if unlocked else "locked"
            },
            "message": status_msg
        }
    except Exception as e:
        logger.error(f"Error checking punch out status: {e}")
        return {"success": False, "message": f"Database error: {e}"}

# Helper
def timedelta_minutes(minutes: int):
    import datetime as dt
    return dt.timedelta(minutes=minutes)

def get_today_punch_status(user_id: str) -> dict:
    """Fetch complete punch-in and punch-out attendance status for today."""
    today_str = date.today().isoformat()
    now_ts = datetime.now()

    try:
        att = execute_query(
            "SELECT punchInTime, punchOutTime, lateMark, status, punchOutStatus, dateTime FROM attendance WHERE userId = ? AND date = ? ORDER BY id DESC LIMIT 1",
            (user_id, today_str),
            fetch="one"
        )
        
        user_info = execute_query(
            "SELECT expectedPunchIn, expectedPunchOut, fullName FROM signup WHERE userId = ? LIMIT 1",
            (user_id,),
            fetch="one"
        ) or {}

        def format_time_val(t_val):
            if not t_val:
                return None
            if isinstance(t_val, str):
                try:
                    return datetime.strptime(t_val, "%H:%M:%S").strftime("%I:%M:%S %p")
                except Exception:
                    return t_val
            elif hasattr(t_val, "total_seconds"):
                tot_sec = int(t_val.total_seconds())
                h = (tot_sec // 3600) % 24
                m = (tot_sec % 3600) // 60
                s = tot_sec % 60
                dt_obj = datetime.combine(date.today(), time(h, m, s))
                return dt_obj.strftime("%I:%M:%S %p")
            elif hasattr(t_val, "strftime"):
                return t_val.strftime("%I:%M:%S %p")
            return str(t_val)

        punched_in = False
        punch_in_time = None
        punched_out = False
        punch_out_time = None
        late_mark = "no"
        attendance_status = "Not Punched"

        if att:
            if att.get("punchInTime"):
                punched_in = True
                punch_in_time = format_time_val(att["punchInTime"])
            if att.get("punchOutTime"):
                punched_out = True
                punch_out_time = format_time_val(att["punchOutTime"])
            late_mark = att.get("lateMark", "no")
            attendance_status = att.get("status", "Present")

        expected_in = format_time_val(user_info.get("expectedPunchIn") or EXPECTED_PUNCH_IN)
        expected_out = format_time_val(user_info.get("expectedPunchOut") or EXPECTED_PUNCH_OUT)

        punch_out_permission = check_punch_out_status(user_id)
        out_data = punch_out_permission.get("data", {}) if punch_out_permission.get("success") else {}

        if not punched_in:
            msg = f"You have not punched in yet today. Expected Punch-In: {expected_in}."
        elif punched_in and not punched_out:
            msg = f"You are currently PUNCHED IN (Punch-In time: {punch_in_time}). Status: {attendance_status}. Punch-Out is currently PENDING (Expected: {expected_out})."
            if out_data.get("unlocked"):
                msg += " You are eligible to punch out now."
            elif out_data.get("unlockTime"):
                msg += f" Punch-out will unlock at {out_data['unlockTime']}."
        else:
            msg = f"You have completed today's attendance! Punch-In: {punch_in_time}, Punch-Out: {punch_out_time}. Status: {attendance_status}."

        return {
            "success": True,
            "data": {
                "date": today_str,
                "punched_in": punched_in,
                "punch_in_time": punch_in_time,
                "punched_out": punched_out,
                "punch_out_time": punch_out_time,
                "attendance_status": attendance_status,
                "late_mark": late_mark,
                "expected_punch_in": expected_in,
                "expected_punch_out": expected_out,
                "punch_out_unlocked": out_data.get("unlocked", False)
            },
            "message": msg
        }
    except Exception as e:
        logger.error(f"Error in get_today_punch_status: {e}")
        return {"success": False, "message": f"Database error fetching punch status: {e}"}

