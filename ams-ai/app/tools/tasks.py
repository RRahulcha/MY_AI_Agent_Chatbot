import logging
from datetime import datetime, timedelta
from app.db.mysql import execute_query

logger = logging.getLogger("ams-ai.tools.tasks")

def get_my_tasks(user_id: str, query_date: str = None) -> dict:
    """
    Fetch both active allocated tasks (from task_allocation) and 
    logged diary entries for today (from TaskEntryNew).
    """
    if not query_date:
        query_date = datetime.now().strftime("%Y-%m-%d")
        
    try:
        # 1. Fetch active allocated tasks assigned to the user
        alloc_query = """
            SELECT id, COALESCE(project_name, 'General') as projectName, 
                   task_title as title, COALESCE(remark, '') as remark, 
                   deadline, status
            FROM task_allocation
            WHERE receiver_id = ? AND status != 'Done'
            ORDER BY deadline ASC
        """
        allocated_tasks = execute_query(alloc_query, (user_id,), fetch="all") or []
        for a in allocated_tasks:
            if a.get("deadline"):
                a["deadline"] = str(a["deadline"])

        # 2. Fetch logged diary tasks for query_date
        diary_query = """
            SELECT taskId, projectName, workType, task, startTime, endTime, status, totalDuration, remark
            FROM TaskEntryNew
            WHERE userId = ? AND DATE(startTime) = ?
            ORDER BY startTime ASC
        """
        diary_tasks = execute_query(diary_query, (user_id, query_date), fetch="all") or []
        for r in diary_tasks:
            if r.get("startTime"):
                r["startTime"] = str(r["startTime"])
            if r.get("endTime"):
                r["endTime"] = str(r["endTime"])

        total_active = len(allocated_tasks) + len(diary_tasks)
        if total_active == 0:
            msg = "You currently have 0 active allocated tasks and 0 task entries logged for today."
        else:
            msg = f"Found {len(allocated_tasks)} active assigned task(s) and {len(diary_tasks)} logged diary entry(ies) for {query_date}."

        return {
            "success": True,
            "data": {
                "allocated_tasks": allocated_tasks,
                "today_logged_tasks": diary_tasks
            },
            "message": msg
        }
    except Exception as e:
        logger.error(f"Error fetching tasks: {e}")
        return {"success": False, "message": f"Database error fetching tasks: {e}"}

def log_task(user_id: str, project_name: str, duration_hours: float, description: str) -> dict:
    """
    Parse and register a finished task entry for a user.
    Includes project name resolution, assignment check, and validation.
    """
    # 1. Validate duration
    if duration_hours <= 0 or duration_hours > 24:
        return {"success": False, "message": "Invalid duration. Must be between 0.1 and 24 hours."}

    # 2. Resolve project name to projectId
    logger.info(f"Resolving project name: {project_name}")
    try:
        projects = execute_query(
            "SELECT projectId, projectName FROM projectMaster WHERE projectName LIKE ? AND status = 1",
            (f"%{project_name}%",),
            fetch="all"
        )
        
        if not projects:
            return {
                "success": False, 
                "message": f"Could not find any active project named '{project_name}'. Please verify the project name."
            }
        
        # If multiple matches, return list for clarification
        if len(projects) > 1:
            p_names = [p["projectName"] for p in projects]
            return {
                "success": False, 
                "message": f"Ambiguous project name. Did you mean one of these? {', '.join(p_names)}"
            }
            
        project = projects[0]
        resolved_name = project["projectName"]
        project_id = project["projectId"]
        
        # 3. Validate user is allocated to the project
        # Checks both allocatedProject (direct allocations) and internAllocations (date range map)
        allocated = execute_query(
            "SELECT id FROM allocatedProject WHERE userId = ? AND projectId = ? LIMIT 1",
            (user_id, project_id),
            fetch="one"
        )
        
        if not allocated:
            # Check internAllocations for date matches
            today_str = datetime.now().strftime("%Y-%m-%d")
            intern_allocated = execute_query(
                """
                SELECT id FROM internAllocations 
                WHERE internId = ? AND projectName = ? AND startDate <= ? AND endDate >= ?
                LIMIT 1
                """,
                (user_id, resolved_name, today_str, today_str),
                fetch="one"
            )
            if not intern_allocated:
                return {
                    "success": False, 
                    "message": f"You are not assigned to project '{resolved_name}'. Please contact your project lead."
                }

        # 4. Insert finished task into TaskEntryNew
        now = datetime.now()
        start_time = now - timedelta(hours=duration_hours)
        start_time_str = start_time.strftime("%Y-%m-%d %H:%M:%S")
        end_time_str = now.strftime("%Y-%m-%d %H:%M:%S")
        total_duration_sec = int(duration_hours * 3600)
        
        # Create TaskEntryNew
        task_id = execute_query(
            """
            INSERT INTO TaskEntryNew 
            (userId, projectName, workType, task, remark, startTime, endTime, status, totalDuration)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'Finished', ?)
            """,
            (user_id, resolved_name, "Project Work", description, "Logged via AI Chatbot", start_time_str, end_time_str, total_duration_sec),
            fetch="none"
        )
        
        # Create matching segment in taskStatus
        man_hours_str = str(timedelta(seconds=total_duration_sec))
        execute_query(
            """
            INSERT INTO taskStatus (taskId, resumeTime, pauseTime, manHours, status, dateTime, userId)
            VALUES (?, ?, ?, ?, 'Finished', ?, ?)
            """,
            (task_id, start_time_str, end_time_str, man_hours_str, end_time_str, user_id),
            fetch="none"
        )
        
        return {
            "success": True, 
            "message": f"Logged {duration_hours} hours on project '{resolved_name}' successfully."
        }
        
    except Exception as e:
        logger.error(f"Error logging task: {e}")
        return {"success": False, "message": f"Database logging failed: {e}"}

def pause_task(user_id: str, task_id: int) -> dict:
    """Pause an active running task segment (replicates task_actions.php)."""
    now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    
    try:
        # Fetch current running task segment
        task = execute_query(
            "SELECT status, totalDuration FROM TaskEntryNew WHERE taskId = ? AND userId = ? LIMIT 1",
            (task_id, user_id),
            fetch="one"
        )
        
        if not task or task["status"] != "Running":
            return {"success": False, "message": "Task is not running or does not exist."}
            
        latest_segment = execute_query(
            "SELECT id, resumeTime FROM taskStatus WHERE taskId = ? AND pauseTime = '1970-01-01 00:00:00' ORDER BY id DESC LIMIT 1",
            (task_id,),
            fetch="one"
        )
        
        if not latest_segment:
            return {"success": False, "message": "No open running segment found."}
            
        resume_dt = latest_segment["resumeTime"]
        elapsed = int((datetime.now() - resume_dt).total_seconds())
        new_total_duration = int(task["totalDuration"] or 0) + elapsed
        
        # 1. Close current segment in taskStatus
        execute_query(
            """
            UPDATE taskStatus 
            SET pauseTime = ?, manHours = SEC_TO_TIME(TIME_TO_SEC(?) - TIME_TO_SEC(resumeTime)), status = 'Paused' 
            WHERE id = ?
            """,
            (now, now, latest_segment["id"]),
            fetch="none"
        )
        
        # 2. Update main TaskEntryNew record
        execute_query(
            "UPDATE TaskEntryNew SET status = 'Paused', totalDuration = ?, lastPausedTime = ? WHERE taskId = ?",
            (new_total_duration, now, task_id),
            fetch="none"
        )
        
        return {"success": True, "message": "Task paused successfully."}
        
    except Exception as e:
        logger.error(f"Error pausing task: {e}")
        return {"success": False, "message": f"Database update failed: {e}"}

def resume_task(user_id: str, task_id: int) -> dict:
    """Resume a paused task segment. Blocks if another task is running."""
    now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")

    try:
        # Check if another task is currently running
        running = execute_query(
            "SELECT taskId FROM TaskEntryNew WHERE userId = ? AND status = 'Running' LIMIT 1",
            (user_id,),
            fetch="one"
        )
        if running:
            return {
                "success": False, 
                "message": "You already have a running task. Please pause or finish it first."
            }

        # Fetch target task
        task = execute_query(
            "SELECT status FROM TaskEntryNew WHERE taskId = ? AND userId = ? LIMIT 1",
            (task_id, user_id),
            fetch="one"
        )
        if not task or task["status"] != "Paused":
            return {"success": False, "message": "Task is not paused or does not exist."}

        # 1. Insert new running segment into taskStatus
        execute_query(
            """
            INSERT INTO taskStatus (taskId, resumeTime, pauseTime, manHours, status, dateTime, userId)
            VALUES (?, ?, '1970-01-01 00:00:00', '00:00:00', 'Running', ?, ?)
            """,
            (task_id, now, now, user_id),
            fetch="none"
        )
        
        # 2. Update main TaskEntryNew record
        execute_query(
            "UPDATE TaskEntryNew SET status = 'Running' WHERE taskId = ?",
            (task_id,),
            fetch="none"
        )
        
        return {"success": True, "message": "Task resumed successfully."}
        
    except Exception as e:
        logger.error(f"Error resuming task: {e}")
        return {"success": False, "message": f"Database update failed: {e}"}

def finish_task(user_id: str, task_id: int) -> dict:
    """Finish a running or paused task."""
    now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")

    try:
        task = execute_query(
            "SELECT status, totalDuration FROM TaskEntryNew WHERE taskId = ? AND userId = ? LIMIT 1",
            (task_id, user_id),
            fetch="one"
        )
        if not task or task["status"] not in ["Running", "Paused"]:
            return {"success": False, "message": "Task is already finished or does not exist."}

        new_total_duration = int(task["totalDuration"] or 0)

        # Close segment only if currently running
        if task["status"] == "Running":
            latest_segment = execute_query(
                "SELECT id, resumeTime FROM taskStatus WHERE taskId = ? AND pauseTime = '1970-01-01 00:00:00' ORDER BY id DESC LIMIT 1",
                (task_id,),
                fetch="one"
            )
            if latest_segment:
                resume_dt = latest_segment["resumeTime"]
                elapsed = int((datetime.now() - resume_dt).total_seconds())
                new_total_duration += elapsed
                
                # Close segment
                execute_query(
                    """
                    UPDATE taskStatus 
                    SET pauseTime = ?, manHours = SEC_TO_TIME(TIME_TO_SEC(?) - TIME_TO_SEC(resumeTime)), status = 'Finished' 
                    WHERE id = ?
                    """,
                    (now, now, latest_segment["id"]),
                    fetch="none"
                )
        else:
            # If paused, simply close the latest paused record segment
            execute_query(
                "UPDATE taskStatus SET status = 'Finished' WHERE taskId = ? ORDER BY id DESC LIMIT 1",
                (task_id,),
                fetch="none"
            )

        # Update main TaskEntryNew record
        execute_query(
            "UPDATE TaskEntryNew SET status = 'Finished', endTime = ?, totalDuration = ? WHERE taskId = ?",
            (now, new_total_duration, task_id),
            fetch="none"
        )
        
        return {"success": True, "message": "Task finished successfully."}
        
    except Exception as e:
        logger.error(f"Error finishing task: {e}")
        return {"success": False, "message": f"Database update failed: {e}"}
