from celery import Celery
from app.config import settings

# Initialize Celery app
celery_app = Celery(
    "ams_ai_workers",
    broker=settings.REDIS_URL,
    backend=settings.REDIS_URL
)

# Celery Configurations
celery_app.conf.update(
    task_serializer="json",
    accept_content=["json"],
    result_serializer="json",
    timezone="Asia/Kolkata",
    enable_utc=False,
    beat_schedule={
        "check-running-tasks-every-15-mins": {
            "task": "app.workers.tasks.check_long_running_tasks",
            "schedule": 900.0,  # 15 minutes in seconds
        }
    }
)

# Auto-discover tasks from the tasks module
celery_app.autodiscover_tasks(["app.workers"])
