import logging
from datetime import date, datetime

from app.db.mysql import execute_query

logger = logging.getLogger("ams-ai.tools.wfh")


def apply_wfh(user_id: str, from_date: str, to_date: str, reason: str) -> dict:
    """Create a pending WFH request using the AMS wfhRequests table."""
    try:
        from_dt = datetime.strptime(from_date, "%Y-%m-%d").date()
        to_dt = datetime.strptime(to_date, "%Y-%m-%d").date()
    except (TypeError, ValueError):
        return {"success": False, "message": "Please provide valid WFH start and end dates."}

    if from_dt < date.today():
        return {"success": False, "message": "You cannot apply for WFH on past dates."}
    if to_dt < from_dt:
        return {"success": False, "message": "The WFH end date cannot be earlier than the start date."}
    if not reason or not reason.strip():
        return {"success": False, "message": "Please provide a reason for the WFH request."}

    try:
        employee = execute_query(
            "SELECT designation FROM signup WHERE userId = ? LIMIT 1",
            (user_id,),
            fetch="one"
        )
        if not employee:
            return {"success": False, "message": "User not found."}

        designation = str(employee.get("designation") or "").strip().lower()
        if designation in {"seniorintern", "resourceallocator"}:
            approver_designations = ("admin", "hr")
        else:
            approver_designations = ("seniorintern", "admin", "hr", "resourceallocator")

        placeholders = ", ".join("?" for _ in approver_designations)
        approver = execute_query(
            f"""SELECT userId FROM signup
                WHERE LOWER(designation) IN ({placeholders})
                  AND LOWER(status) = 'active' AND userId != ?
                ORDER BY CASE LOWER(designation)
                    WHEN 'hr' THEN 1 WHEN 'admin' THEN 2 ELSE 3 END
                LIMIT 1""",
            (*approver_designations, user_id),
            fetch="one"
        )
        if not approver:
            return {"success": False, "message": "No active WFH approver is available. Please contact HR."}

        overlap = execute_query(
            """SELECT wfh_id FROM wfhRequests
               WHERE userid = ? AND status NOT IN ('Rejected', 'Cancelled')
                 AND fromDate <= ? AND toDate >= ? LIMIT 1""",
            (user_id, to_date, from_date),
            fetch="one"
        )
        if overlap:
            return {"success": False, "message": "You already have a WFH request during this date range."}

        leave_overlap = execute_query(
            """SELECT leave_id FROM leaves
               WHERE userId = ? AND status NOT IN ('Rejected', 'Cancelled')
                 AND leave_name NOT LIKE '%Auto Leave%'
                 AND from_date <= ? AND to_date >= ? LIMIT 1""",
            (user_id, to_date, from_date),
            fetch="one"
        )
        if leave_overlap:
            return {"success": False, "message": "You have an active or pending leave during those dates, so WFH cannot be applied."}

        execute_query(
            """INSERT INTO wfhRequests
               (userid, fromDate, toDate, wfhDate, reason, documentPath, status,
                createdDate, updatedDate, approvedBy, rejectionReason)
               VALUES (?, ?, ?, ?, ?, '-', 'Pending', NOW(), NOW(), ?, NULL)""",
            (user_id, from_date, to_date, from_date, reason.strip(), approver["userId"]),
            fetch="none"
        )
        return {
            "success": True,
            "message": f"WFH request submitted for {from_date} to {to_date}. It is pending approval."
        }
    except Exception as exc:
        logger.error("Error submitting WFH request: %s", exc)
        return {"success": False, "message": "Could not submit the WFH request because of a database error."}


def get_wfh_status(user_id: str) -> dict:
    """Fetch active and recent WFH requests for an employee."""
    try:
        requests = execute_query(
            """SELECT wfh_id, fromDate, toDate, wfhDate, reason, status, createdDate, rejectionReason, approvedBy
               FROM wfhRequests
               WHERE userid = ?
               ORDER BY createdDate DESC
               LIMIT 5""",
            (user_id,),
            fetch="all"
        )
        if not requests:
            return {
                "success": True,
                "count": 0,
                "requests": [],
                "message": "You currently have no Work From Home (WFH) requests on record."
            }

        req_list = []
        for r in requests:
            f_date = str(r.get("fromDate") or r.get("wfhDate") or "")
            t_date = str(r.get("toDate") or r.get("wfhDate") or "")
            req_list.append({
                "wfh_id": r.get("wfh_id"),
                "from_date": f_date,
                "to_date": t_date,
                "reason": r.get("reason"),
                "status": r.get("status"),
                "applied_on": str(r.get("createdDate") or "")[:10],
                "rejection_reason": r.get("rejectionReason")
            })

        return {
            "success": True,
            "count": len(req_list),
            "requests": req_list,
            "message": f"Found {len(req_list)} recent WFH request(s)."
        }
    except Exception as exc:
        logger.error("Error fetching WFH status: %s", exc)
        return {"success": False, "message": "Could not retrieve WFH requests due to a database error."}
