import logging
from datetime import datetime, timedelta
from app.workers.celery import celery_app
from app.db.mysql import execute_query
from app.notifications.fcm import send_notification_to_user

logger = logging.getLogger("ams-ai.workers.tasks")

@celery_app.task(name="app.workers.tasks.check_long_running_tasks")
def check_long_running_tasks():
    """
    Periodic task to check for tasks in 'Running' state for more than 4 consecutive hours.
    Dispatches warning notifications to users if found.
    """
    logger.info("Running task checker beat check...")
    four_hours_ago = datetime.now() - timedelta(hours=4)
    four_hours_ago_str = four_hours_ago.strftime("%Y-%m-%d %H:%M:%S")

    try:
        # Find all currently running tasks started/resumed more than 4 hours ago
        long_tasks = execute_query(
            """
            SELECT t.taskId, t.userId, t.projectName, t.task, s.resumeTime 
            FROM TaskEntryNew t
            JOIN taskStatus s ON t.taskId = s.taskId
            WHERE t.status = 'Running' 
              AND s.pauseTime = '1970-01-01 00:00:00'
              AND s.resumeTime <= ?
            """,
            (four_hours_ago_str,),
            fetch="all"
        )
        
        logger.info(f"Found {len(long_tasks)} tasks running for > 4 hours.")
        
        for task in long_tasks:
            user_id = task["userId"]
            task_desc = task["task"]
            proj_name = task["projectName"]
            resume_time = task["resumeTime"]
            
            logger.info(f"Dispatching running task warning to user {user_id} for task {task['taskId']}.")
            send_notification_to_user(
                user_id=user_id,
                title="Running Task Reminder ⚠️",
                body=f"Your task '{task_desc}' on project '{proj_name}' has been active since {resume_time.strftime('%I:%M %p')}. Please pause it if you are not currently working.",
                notif_type="task_reminder",
                reference_id=str(task["taskId"])
            )
            
    except Exception as e:
        logger.error(f"Error in long running task background check: {e}")
