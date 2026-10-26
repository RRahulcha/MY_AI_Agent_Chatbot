import logging
import time
from fastapi import APIRouter, status, Response
from app.db.mysql import execute_query
from app.api.chat import redis_client

logger = logging.getLogger("ams-ai.api.health")
router = APIRouter(prefix="/api", tags=["Monitoring & Health"])

@router.get("/health")
def health_check():
    """Liveness probe. Returns immediately if FastAPI is running."""
    return {"status": "healthy", "timestamp": time.time()}

@router.get("/ready")
def readiness_check(response: Response):
    """Readiness probe. Checks MySQL and Redis connection states."""
    mysql_ready = False
    redis_ready = False

    # 1. Test MySQL
    try:
        res = execute_query("SELECT 1", fetch="one")
        if res:
            mysql_ready = True
    except Exception as e:
        logger.error(f"Readiness check failed: MySQL database is unreachable: {e}")

    # 2. Test Redis
    if redis_client:
        try:
            if redis_client.ping():
                redis_ready = True
        except Exception as e:
            logger.error(f"Readiness check failed: Redis is unreachable: {e}")
    else:
        # In local development fallback
        redis_ready = True

    # Redis is a soft dependency (session history falls back to in-memory)
    if not mysql_ready:
        response.status_code = status.HTTP_503_SERVICE_UNAVAILABLE
        return {
            "status": "unready",
            "mysql": "online" if mysql_ready else "offline",
            "redis": "online" if redis_ready else "offline",
            "timestamp": time.time()
        }

    return {
        "status": "ready",
        "mysql": "online",
        "redis": "online",
        "timestamp": time.time()
    }

@router.get("/metrics")
def metrics_check():
    """Metrics telemetry. Exposes vital metrics from the AMS database."""
    try:
        # Count running tasks
        running_tasks = execute_query(
            "SELECT COUNT(*) as count FROM TaskEntryNew WHERE status = 'Running'",
            fetch="one"
        )["count"]
        
        # Count pending leaves
        pending_leaves = execute_query(
            "SELECT COUNT(*) as count FROM leaves WHERE status = 'Pending'",
            fetch="one"
        )["count"]
        
        # Count active users
        active_users = execute_query(
            "SELECT COUNT(*) as count FROM signup WHERE status = 'active'",
            fetch="one"
        )["count"]

        return {
            "active_users": active_users,
            "running_tasks": running_tasks,
            "pending_leaves_to_approve": pending_leaves,
            "timestamp": time.time()
        }
    except Exception as e:
        logger.error(f"Metrics collection failed: {e}")
        return {"error": "Failed to collect metrics", "timestamp": time.time()}
